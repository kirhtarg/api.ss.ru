<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    private const DISK = 'local';
    private const DIRECTORY = 'backups/database';

    public function listBackups(): array
    {
        $this->ensureDirectoryExists();

        $files = Storage::disk(self::DISK)->files(self::DIRECTORY);
        $backups = [];

        foreach ($files as $path) {
            if (! Str::endsWith($path, '.sql') && ! Str::endsWith($path, '.sqlite')) {
                continue;
            }

            $filename = basename($path);
            $metadata = $this->readMetadata($filename);

            $backups[] = array_merge([
                'filename' => $filename,
                'name' => $metadata['name'] ?? $filename,
                'comment' => $metadata['comment'] ?? '',
                'driver' => $metadata['driver'] ?? $this->connectionConfig()['driver'],
                'database' => $metadata['database'] ?? $this->connectionConfig()['database'],
                'created_by' => $metadata['created_by'] ?? null,
                'created_at' => $metadata['created_at'] ?? date('Y-m-d H:i:s', Storage::disk(self::DISK)->lastModified($path)),
                'auto_delete_at' => $metadata['auto_delete_at'] ?? $this->calculateAutoDeleteAt($metadata['created_at'] ?? date('Y-m-d H:i:s', Storage::disk(self::DISK)->lastModified($path))),
                'size' => Storage::disk(self::DISK)->size($path),
                'size_human' => $this->formatBytes(Storage::disk(self::DISK)->size($path)),
            ], $metadata);
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    public function createBackup(?string $name = null, ?string $comment = null, ?int $userId = null): array
    {
        $this->ensureDirectoryExists();

        $config = $this->connectionConfig();
        $extension = $config['driver'] === 'sqlite' ? 'sqlite' : 'sql';
        $filename = sprintf(
            'database_%s_%s.%s',
            date('Y_m_d_H_i_s'),
            Str::lower(Str::random(6)),
            $extension
        );

        $relativePath = self::DIRECTORY.'/'.$filename;
        $absolutePath = Storage::disk(self::DISK)->path($relativePath);

        match ($config['driver']) {
            'mysql', 'mariadb' => $this->dumpMysql($absolutePath, $config),
            'pgsql' => $this->dumpPostgres($absolutePath, $config),
            'sqlite' => $this->dumpSqlite($absolutePath, $config),
            default => throw new RuntimeException("Драйвер {$config['driver']} не поддерживается для бэкапа"),
        };

        $metadata = [
            'filename' => $filename,
            'name' => $name ?: 'Бэкап БД '.date('d.m.Y H:i'),
            'comment' => $comment ?: '',
            'driver' => $config['driver'],
            'database' => $config['database'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'auto_delete_at' => $this->calculateAutoDeleteAt(date('Y-m-d H:i:s')),
            'size' => Storage::disk(self::DISK)->size($relativePath),
            'size_human' => $this->formatBytes(Storage::disk(self::DISK)->size($relativePath)),
        ];

        $this->writeMetadata($filename, $metadata);

        return $metadata;
    }

    public function listActionLogs(int $limit = 200): array
    {
        $this->ensureDirectoryExists();
        $path = self::DIRECTORY.'/actions.log';

        if (! Storage::disk(self::DISK)->exists($path)) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim(Storage::disk(self::DISK)->get($path)));
        $items = [];

        foreach (array_reverse($lines ?: []) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function logAction(string $action, ?string $filename = null, ?int $userId = null, array $context = []): void
    {
        $this->ensureDirectoryExists();

        $record = [
            'created_at' => date('Y-m-d H:i:s'),
            'action' => $action,
            'filename' => $filename,
            'user_id' => $userId,
            'context' => $context,
        ];

        Storage::disk(self::DISK)->append(
            self::DIRECTORY.'/actions.log',
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function deleteExpiredBackups(int $retentionDays): int
    {
        $deleted = 0;
        $now = time();

        foreach ($this->listBackups() as $backup) {
            $autoDeleteAt = $backup['auto_delete_at'] ?? $this->calculateAutoDeleteAt($backup['created_at'] ?? null, $retentionDays);
            if (! $autoDeleteAt || strtotime($autoDeleteAt) > $now) {
                continue;
            }

            $this->delete($backup['filename']);
            $deleted++;
            $this->logAction('automatic_delete', $backup['filename'], null, [
                'auto_delete_at' => $autoDeleteAt,
                'retention_days' => $retentionDays,
            ]);
        }

        return $deleted;
    }

    public function restore(string $filename): void
    {
        $filename = $this->sanitizeFilename($filename);
        $relativePath = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new RuntimeException('Файл бэкапа не найден');
        }

        $config = $this->connectionConfig();
        $absolutePath = Storage::disk(self::DISK)->path($relativePath);

        match ($config['driver']) {
            'mysql', 'mariadb' => $this->restoreMysql($absolutePath, $config),
            'pgsql' => $this->restorePostgres($absolutePath, $config),
            'sqlite' => $this->restoreSqlite($absolutePath, $config),
            default => throw new RuntimeException("Драйвер {$config['driver']} не поддерживается для восстановления"),
        };
    }

    public function delete(string $filename): void
    {
        $filename = $this->sanitizeFilename($filename);
        Storage::disk(self::DISK)->delete(self::DIRECTORY.'/'.$filename);
        Storage::disk(self::DISK)->delete(self::DIRECTORY.'/'.$filename.'.json');
    }

    public function absolutePath(string $filename): string
    {
        $filename = $this->sanitizeFilename($filename);
        $relativePath = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new RuntimeException('Файл бэкапа не найден');
        }

        return Storage::disk(self::DISK)->path($relativePath);
    }

    public function defaultSchedule(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '03:30',
            'weekday' => 1,
            'retention_days' => 30,
        ];
    }

    private function dumpMysql(string $absolutePath, array $config): void
    {
        $binary = $this->findBinary(env('DB_BACKUP_MYSQLDUMP_PATH'), 'mysqldump', 'DB_BACKUP_MYSQLDUMP_PATH');
        if (! $binary) {
            $this->dumpMysqlWithPdo($absolutePath);

            return;
        }

        $command = [
            $binary,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=utf8mb4',
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--user='.$config['username'],
            '--result-file='.$absolutePath,
            $config['database'],
        ];

        $this->runProcess($command, ['MYSQL_PWD' => (string) $config['password']], 1800);
    }

    private function restoreMysql(string $absolutePath, array $config): void
    {
        $binary = $this->findBinary(env('DB_BACKUP_MYSQL_PATH'), 'mysql', 'DB_BACKUP_MYSQL_PATH');
        if (! $binary) {
            $this->restoreSqlWithPdo($absolutePath);

            return;
        }

        $safePath = str_replace('\\', '/', $absolutePath);
        $command = [
            $binary,
            '--default-character-set=utf8mb4',
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--user='.$config['username'],
            $config['database'],
            '--execute=source '.$safePath,
        ];

        $this->runProcess($command, ['MYSQL_PWD' => (string) $config['password']], 1800);
    }

    private function dumpPostgres(string $absolutePath, array $config): void
    {
        $binary = $this->resolveBinary(env('DB_BACKUP_PG_DUMP_PATH'), 'pg_dump', 'DB_BACKUP_PG_DUMP_PATH');
        $command = [
            $binary,
            '--clean',
            '--if-exists',
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            '--file='.$absolutePath,
            $config['database'],
        ];

        $this->runProcess($command, ['PGPASSWORD' => (string) $config['password']], 1800);
    }

    private function restorePostgres(string $absolutePath, array $config): void
    {
        $binary = $this->resolveBinary(env('DB_BACKUP_PSQL_PATH'), 'psql', 'DB_BACKUP_PSQL_PATH');
        $command = [
            $binary,
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            '--file='.$absolutePath,
        ];

        $this->runProcess($command, ['PGPASSWORD' => (string) $config['password']], 1800);
    }

    private function dumpMysqlWithPdo(string $absolutePath): void
    {
        $pdo = DB::connection()->getPdo();
        $handle = fopen($absolutePath, 'wb');

        if (! $handle) {
            throw new RuntimeException('Не удалось создать файл дампа');
        }

        fwrite($handle, "-- Database backup generated by Laravel\n");
        fwrite($handle, "-- Generated at: ".date('Y-m-d H:i:s')."\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($this->mysqlTables() as $table) {
            $quotedTable = $this->quoteIdentifier($table);
            $createRows = DB::select("SHOW CREATE TABLE {$quotedTable}");
            $createRow = (array) ($createRows[0] ?? []);
            $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? null;

            if (! $createSql) {
                continue;
            }

            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
            fwrite($handle, $createSql.";\n\n");

            $batch = [];
            foreach (DB::table($table)->cursor() as $row) {
                $rowArray = (array) $row;
                $batch[] = '('.implode(', ', array_map(fn ($value) => $this->quoteValue($pdo, $value), array_values($rowArray))).')';

                if (count($batch) >= 100) {
                    $this->writeInsertBatch($handle, $table, array_keys($rowArray), $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->writeInsertBatch($handle, $table, array_keys((array) DB::table($table)->first() ?: []), $batch);
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function restoreSqlWithPdo(string $absolutePath): void
    {
        $sql = File::get($absolutePath);

        DB::connection()->disableQueryLog();
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->splitSqlStatements($sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            DB::unprepared($trimmed);
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
    }

    private function mysqlTables(): array
    {
        $rows = DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');

        return array_values(array_map(function ($row) {
            return (string) array_values((array) $row)[0];
        }, $rows));
    }

    private function writeInsertBatch($handle, string $table, array $columns, array $batch): void
    {
        if ($columns === [] || $batch === []) {
            return;
        }

        $quotedColumns = implode(', ', array_map(fn ($column) => $this->quoteIdentifier($column), $columns));
        fwrite($handle, 'INSERT INTO '.$this->quoteIdentifier($table)." ({$quotedColumns}) VALUES\n");
        fwrite($handle, implode(",\n", $batch).";\n");
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?: $char);
            }

            if ($char === ';' && $quote === null) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function dumpSqlite(string $absolutePath, array $config): void
    {
        if (! File::exists($config['database'])) {
            throw new RuntimeException('Файл SQLite базы не найден');
        }

        File::copy($config['database'], $absolutePath);
    }

    private function restoreSqlite(string $absolutePath, array $config): void
    {
        if (! File::exists($absolutePath)) {
            throw new RuntimeException('Файл SQLite бэкапа не найден');
        }

        File::copy($absolutePath, $config['database']);
    }

    private function runProcess(array $command, array $env, int $timeout): void
    {
        $process = new Process($command, base_path(), $env);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = $this->normalizeProcessText($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($error ?: 'Команда бэкапа завершилась с ошибкой');
        }
    }

    private function resolveBinary(?string $configuredPath, string $defaultBinary, string $envName): string
    {
        $binary = $this->findBinary($configuredPath, $defaultBinary, $envName);
        if ($binary) {
            return $binary;
        }

        throw new RuntimeException("Не найден {$defaultBinary}. Установите MySQL/PostgreSQL client tools или укажите путь в {$envName}.");
    }

    private function findBinary(?string $configuredPath, string $defaultBinary, string $envName): ?string
    {
        if ($configuredPath) {
            if (! File::exists($configuredPath)) {
                throw new RuntimeException("Файл из {$envName} не найден: {$configuredPath}");
            }

            return $configuredPath;
        }

        if ($this->binaryExists($defaultBinary)) {
            return $defaultBinary;
        }

        return null;
    }

    private function binaryExists(string $binary): bool
    {
        $command = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? ['where.exe', $binary]
            : ['which', $binary];

        $process = new Process($command, base_path());
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function normalizeProcessText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        foreach (['CP866', 'Windows-1251', 'ISO-8859-1'] as $encoding) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $encoding);
            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function connectionConfig(): array
    {
        $connection = Config::get('database.default');
        $config = Config::get("database.connections.{$connection}", []);

        return [
            'driver' => $config['driver'] ?? $connection,
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 3306,
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
        ];
    }

    private function ensureDirectoryExists(): void
    {
        Storage::disk(self::DISK)->makeDirectory(self::DIRECTORY);
    }

    private function calculateAutoDeleteAt(?string $createdAt, ?int $retentionDays = null): ?string
    {
        $retentionDays ??= $this->currentRetentionDays();
        $timestamp = $createdAt ? strtotime($createdAt) : time();

        if (! $timestamp || $retentionDays < 1) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime("+{$retentionDays} days", $timestamp));
    }

    private function currentRetentionDays(): int
    {
        try {
            return max(1, (int) (DB::table('settings')->where('key', 'database_backup_retention_days')->value('value') ?? 30));
        } catch (\Throwable) {
            return 30;
        }
    }

    private function readMetadata(string $filename): array
    {
        $path = self::DIRECTORY.'/'.$this->sanitizeFilename($filename).'.json';

        if (! Storage::disk(self::DISK)->exists($path)) {
            return [];
        }

        $decoded = json_decode(Storage::disk(self::DISK)->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeMetadata(string $filename, array $metadata): void
    {
        Storage::disk(self::DISK)->put(
            self::DIRECTORY.'/'.$this->sanitizeFilename($filename).'.json',
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);

        if (! preg_match('/^[a-zA-Z0-9_.-]+\.(sql|sqlite)$/', $filename)) {
            throw new RuntimeException('Некорректное имя файла бэкапа');
        }

        return $filename;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 2).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}
