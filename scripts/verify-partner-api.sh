#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
TEMP_ROOT=""

cleanup() {
    if [[ -n "${TEMP_ROOT}" && -d "${TEMP_ROOT}" ]]; then
        rm -rf -- "${TEMP_ROOT}"
    fi
}
trap cleanup EXIT INT TERM

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

command -v mktemp >/dev/null 2>&1 || fail 'mktemp is required; tests were not started.'
command -v tar >/dev/null 2>&1 || fail 'tar is required; tests were not started.'
command -v php >/dev/null 2>&1 || fail 'PHP CLI is required; tests were not started.'
[[ -f "${PROJECT_ROOT}/artisan" ]] || fail 'Laravel project root was not found.'
[[ -d "${PROJECT_ROOT}/vendor" ]] || fail 'vendor is missing; run composer install in a release directory first.'

TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/partner-api-verify.XXXXXXXX")" || fail 'Temporary directory could not be created; tests were not started.'
[[ -d "${TEMP_ROOT}" && -w "${TEMP_ROOT}" ]] || fail 'Temporary directory is not writable; tests were not started.'
TEMP_PROJECT="${TEMP_ROOT}/api.ss.ru"
mkdir -p -- "${TEMP_PROJECT}"

# Production .env, caches, storage, Git metadata and frontend dependencies are never copied.
tar --dereference -C "${PROJECT_ROOT}" \
    --exclude='.env' --exclude='.env.*' --exclude='.git' --exclude='storage' \
    --exclude='bootstrap/cache' --exclude='node_modules' \
    -cf - app artisan bootstrap composer.json composer.lock config database phpunit.xml resources routes scripts tests vendor \
    | tar -C "${TEMP_PROJECT}" -xf -
[[ -f "${TEMP_PROJECT}/vendor/autoload.php" && ! -L "${TEMP_PROJECT}/vendor" ]] || fail 'An independent vendor copy could not be created; tests were not started.'

mkdir -p -- "${TEMP_PROJECT}/bootstrap/cache" \
    "${TEMP_PROJECT}/storage/framework/cache/data" \
    "${TEMP_PROJECT}/storage/framework/sessions" \
    "${TEMP_PROJECT}/storage/framework/views" \
    "${TEMP_PROJECT}/storage/logs"
find "${TEMP_PROJECT}/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -delete

cat > "${TEMP_PROJECT}/.env.testing" <<'ENV'
APP_NAME=SkateAndSnowPartnerApiTest
APP_ENV=testing
APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
APP_DEBUG=false
APP_URL=http://localhost
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
LOG_CHANNEL=stderr
ENV

readonly TEST_APP_ENV='testing'
readonly TEST_DB_CONNECTION='sqlite'
readonly TEST_DB_DATABASE=':memory:'

[[ "${TEST_APP_ENV}" == 'testing' ]] || fail 'APP_ENV is not testing.'
[[ "${TEST_DB_CONNECTION}" == 'sqlite' ]] || fail 'DB_CONNECTION is not sqlite.'
[[ "${TEST_DB_DATABASE}" == ':memory:' ]] || fail 'DB_DATABASE is not :memory:.'
if [[ "${TEST_DB_CONNECTION}" =~ ^(mysql|mariadb|pgsql)$ ]] || [[ "${TEST_DB_DATABASE}" != ':memory:' ]]; then
    fail 'A production-capable database configuration was detected; tests were not started.'
fi

cd -- "${TEMP_PROJECT}"
printf 'Isolated test environment:\n'
env -i PATH="${PATH}" HOME="${TEMP_ROOT}" \
    APP_ENV="${TEST_APP_ENV}" DB_CONNECTION="${TEST_DB_CONNECTION}" DB_DATABASE="${TEST_DB_DATABASE}" \
    php -r 'printf("APP_ENV=%s\nDB_CONNECTION=%s\nDB_DATABASE=%s\n", getenv("APP_ENV"), getenv("DB_CONNECTION"), getenv("DB_DATABASE"));'

env -i PATH="${PATH}" HOME="${TEMP_ROOT}" \
    APP_ENV="${TEST_APP_ENV}" DB_CONNECTION="${TEST_DB_CONNECTION}" DB_DATABASE="${TEST_DB_DATABASE}" \
    CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
    APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' APP_DEBUG=false APP_URL=http://localhost LOG_CHANNEL=stderr \
    php artisan test tests/Feature/PartnerApi tests/Unit/Partner

printf 'Partner API verification completed in an isolated temporary copy.\n'
