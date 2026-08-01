#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
MODE='check'
BACKUP_FILE=''
STARTED_AT=''
DEPLOY_LOG=''
PARTNER_MIGRATIONS=(
    database/migrations/2026_07_31_000000_create_partner_api_tables.php
    database/migrations/2026_07_31_010000_create_partner_payouts_table.php
    database/migrations/2026_07_31_020000_add_partner_api_admin_menu_item.php
    database/migrations/2026_08_01_000000_add_duration_to_partner_webhook_deliveries.php
    database/migrations/2026_08_01_010000_create_partner_checkout_quotes_and_payment_idempotencies.php
)
PARTNER_PATH_ARGS=()
for migration in "${PARTNER_MIGRATIONS[@]}"; do
    PARTNER_PATH_ARGS+=("--path=${migration}")
done

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

artisan_readonly() {
    LOG_CHANNEL=stderr php artisan "$@"
}

for argument in "$@"; do
    case "${argument}" in
        --deploy) MODE='deploy' ;;
        --backup=*) BACKUP_FILE="${argument#--backup=}" ;;
        *) fail "Unknown argument: ${argument}" ;;
    esac
done

cd -- "${PROJECT_ROOT}"

required_files=(
    artisan
    composer.json
    routes/partner.php
    config/partners.php
    resources/openapi/partner-v1.json
    "${PARTNER_MIGRATIONS[@]}"
)
for file in "${required_files[@]}"; do
    [[ -f "${file}" ]] || fail "Required file is missing: ${file}"
done

command -v php >/dev/null 2>&1 || fail 'PHP CLI is unavailable.'
command -v df >/dev/null 2>&1 || fail 'df is unavailable.'

printf '== Laravel ==\n'
artisan_readonly about
printf '\n== Migration status (read-only) ==\n'
artisan_readonly migrate:status
printf '\n== Pending SQL preview (no changes) ==\n'
artisan_readonly migrate --pretend "${PARTNER_PATH_ARGS[@]}"
printf '\n== Partner API routes ==\n'
artisan_readonly route:list --path=api/partner
printf '\n== Scheduler ==\n'
artisan_readonly schedule:list

printf '\n== Cron ==\n'
if command -v crontab >/dev/null 2>&1 && crontab -l 2>/dev/null | grep -Eq 'artisan[[:space:]]+schedule:run'; then
    printf 'Laravel schedule:run cron entry: present\n'
else
    fail 'Laravel schedule:run cron entry was not found for the current user.'
fi

printf '\n== Queue worker ==\n'
if command -v pgrep >/dev/null 2>&1 && pgrep -af 'artisan (queue:work|horizon)' >/dev/null; then
    printf 'Active Laravel queue worker: present\n'
else
    fail 'An active Laravel queue worker was not found.'
fi

printf '\n== Capacity ==\n'
df -h -- "${PROJECT_ROOT}"
AVAILABLE_KB="$(df -Pk -- "${PROJECT_ROOT}" | awk 'NR == 2 { print $4 }')"
[[ "${AVAILABLE_KB}" =~ ^[0-9]+$ && "${AVAILABLE_KB}" -ge 2097152 ]] || fail 'Less than 2 GiB of disk space is available.'
if command -v free >/dev/null 2>&1; then
    free -h
    AVAILABLE_MEMORY_KB="$(awk '/MemAvailable:/ { print $2 }' /proc/meminfo)"
    [[ "${AVAILABLE_MEMORY_KB}" =~ ^[0-9]+$ && "${AVAILABLE_MEMORY_KB}" -ge 262144 ]] || fail 'Less than 256 MiB of memory is available.'
else
    printf 'Memory check unavailable: free command is missing.\n'
fi

printf '\n== Writable paths ==\n'
[[ -d storage && -w storage ]] || fail 'storage is not writable.'
[[ -d bootstrap/cache && -w bootstrap/cache ]] || fail 'bootstrap/cache is not writable.'
printf 'storage and bootstrap/cache: writable\n'

printf '\n== Partner API environment keys ==\n'
[[ -f .env ]] || fail '.env is missing.'
required_env=(PARTNER_API_SIGNATURE_TTL PARTNER_API_RATE_LIMIT PARTNER_RESERVATION_TTL_MINUTES PARTNER_WEBHOOK_TIMEOUT PARTNER_WEBHOOK_MAX_ATTEMPTS PARTNER_WEBHOOK_ALLOW_HTTP PARTNER_WEBHOOK_ALLOWED_PORTS)
for key in "${required_env[@]}"; do
    if awk -F= -v wanted="${key}" '$1 == wanted && length(substr($0, index($0, "=") + 1)) > 0 { found=1 } END { exit(found ? 0 : 1) }' .env; then
        printf '%s: configured\n' "${key}"
    else
        fail "Required environment key is missing or empty: ${key}"
    fi
done

printf '\n== OpenAPI ==\n'
php -r 'json_decode(file_get_contents("resources/openapi/partner-v1.json"), true, 512, JSON_THROW_ON_ERROR); echo "OpenAPI JSON: valid\n";'

if [[ "${MODE}" == 'check' ]]; then
    printf '\nRead-only Partner API deployment check completed. No deployment actions were performed.\n'
    exit 0
fi

[[ -n "${BACKUP_FILE}" ]] || fail '--deploy requires --backup=/absolute/path/to/fresh-dump.sql[.gz].'
[[ "${BACKUP_FILE}" = /* ]] || fail 'Backup path must be absolute.'
[[ -f "${BACKUP_FILE}" && -r "${BACKUP_FILE}" && -s "${BACKUP_FILE}" ]] || fail 'Database dump is missing, unreadable or empty.'
find "${BACKUP_FILE}" -mmin -1440 -print -quit | grep -q . || fail 'Database dump is older than 24 hours; create and verify a fresh dump.'
case "${BACKUP_FILE}" in
    *.gz) gzip -t -- "${BACKUP_FILE}" || fail 'Compressed database dump integrity check failed.' ;;
    *) head -c 64 -- "${BACKUP_FILE}" >/dev/null || fail 'Database dump readability check failed.' ;;
esac

printf '\n== Exact pending migrations ==\n'
PENDING_MIGRATIONS="$(artisan_readonly migrate:status --pending=1 "${PARTNER_PATH_ARGS[@]}" 2>/dev/null || true)"
[[ -n "${PENDING_MIGRATIONS}" ]] || fail 'No pending migrations were reported; deployment was not started.'
printf '%s\n' "${PENDING_MIGRATIONS}"
printf '\nType DEPLOY PARTNER API to apply exactly the pending migrations: '
read -r confirmation
[[ "${confirmation}" == 'DEPLOY PARTNER API' ]] || fail 'Interactive confirmation did not match; deployment cancelled.'

STARTED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
DEPLOY_LOG="storage/logs/partner-api-deploy-$(date -u +'%Y%m%dT%H%M%SZ').log"
{
    printf 'Partner API deployment started_at=%s\n' "${STARTED_AT}"
    printf 'backup_file=%s backup_bytes=%s\n' "${BACKUP_FILE}" "$(wc -c < "${BACKUP_FILE}")"
    printf 'pending_migrations:\n%s\n' "${PENDING_MIGRATIONS}"
    printf 'command=php artisan migrate --force %s\n' "${PARTNER_PATH_ARGS[*]}"
} | tee -a "${DEPLOY_LOG}"

php artisan migrate --force "${PARTNER_PATH_ARGS[@]}" 2>&1 | tee -a "${DEPLOY_LOG}"
printf 'Partner API migration step completed_at=%s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" | tee -a "${DEPLOY_LOG}"
printf 'No cache clearing, process restart or release switching was performed by this script.\n'
