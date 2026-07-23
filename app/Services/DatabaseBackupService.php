<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
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
    private const TASK_DIRECTORY = 'backups/database/tasks';
    private const DOWNLOAD_TOKEN_PREFIX = 'database_backup_download_token:';

    public function listBackups(): array
    {
        $this->ensureDirectoryExists();

        $files = Storage::disk(self::DISK)->files(self::DIRECTORY);
        $backups = [];

        foreach ($files as $path) {
            if (! Str::endsWith($path, '.sql') && ! Str::endsWith($path, '.sql.gz') && ! Str::endsWith($path, '.sqlite')) {
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

    public function backupManifest(string $filename): array
    {
        $filename = $this->sanitizeFilename($filename);
        $relativePath = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new RuntimeException('Файл бэкапа не найден');
        }

        $metadata = $this->readMetadata($filename);
        $tables = is_array($metadata['tables'] ?? null) ? $metadata['tables'] : [];
        $groups = is_array($metadata['table_groups'] ?? null) ? $metadata['table_groups'] : $this->summarizeTableGroups($tables);

        return array_merge([
            'filename' => $filename,
            'name' => $metadata['name'] ?? $filename,
            'comment' => $metadata['comment'] ?? '',
            'driver' => $metadata['driver'] ?? $this->connectionConfig()['driver'],
            'database' => $metadata['database'] ?? $this->connectionConfig()['database'],
            'created_at' => $metadata['created_at'] ?? date('Y-m-d H:i:s', Storage::disk(self::DISK)->lastModified($relativePath)),
            'size' => Storage::disk(self::DISK)->size($relativePath),
            'size_human' => $this->formatBytes(Storage::disk(self::DISK)->size($relativePath)),
            'tables' => $tables,
            'table_groups' => $groups,
            'table_count' => count($tables),
            'has_table_manifest' => $tables !== [],
        ], $metadata);
    }

    public function createBackup(?string $name = null, ?string $comment = null, ?int $userId = null, ?string $taskId = null): array
    {
        $lock = Cache::lock('database_backup_create', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Создание бэкапа уже выполняется. Дождитесь завершения текущего процесса.');
        }

        try {
            return $this->createBackupWithoutLock($name, $comment, $userId, $taskId);
        } finally {
            optional($lock)->release();
        }
    }

    public function createQueuedBackup(?string $name = null, ?string $comment = null, ?int $userId = null): array
    {
        $activeRestore = $this->currentRestoreTask();
        if ($activeRestore && in_array($activeRestore['status'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('Сейчас выполняется восстановление базы. Дождитесь завершения перед созданием нового бэкапа.');
        }

        $active = $this->currentTask();
        if ($active && in_array($active['status'] ?? null, ['queued', 'running'], true)) {
            return $active;
        }

        $taskId = (string) Str::uuid();
        $task = [
            'id' => $taskId,
            'status' => 'queued',
            'progress' => 1,
            'stage' => 'В очереди',
            'filename' => null,
            'message' => 'Задача поставлена в очередь',
            'name' => $name,
            'comment' => $comment,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->putTaskStatus($taskId, $task);
        Cache::put('database_backup_current_task', $taskId, now()->addHours(24));
        \App\Jobs\CreateDatabaseBackupJob::dispatch($taskId, $name, $comment, $userId);

        return $task;
    }

    public function createQueuedRestore(string $filename, ?int $userId = null, array $options = []): array
    {
        $filename = $this->sanitizeFilename($filename);
        $this->absolutePath($filename);
        $restoreSelection = $this->normalizeRestoreSelection($filename, $options);

        $active = $this->currentRestoreTask();
        if ($active && in_array($active['status'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('Восстановление базы уже выполняется. Дождитесь завершения текущего процесса.');
        }

        $activeBackup = $this->currentTask();
        if ($activeBackup && in_array($activeBackup['status'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('Сейчас создается бэкап. Дождитесь завершения перед восстановлением.');
        }

        $taskId = (string) Str::uuid();
        $task = [
            'id' => $taskId,
            'type' => 'restore',
            'status' => 'queued',
            'progress' => 1,
            'stage' => 'В очереди',
            'filename' => $filename,
            'restore_mode' => $restoreSelection['mode'],
            'selected_groups' => $restoreSelection['groups'],
            'selected_tables' => $restoreSelection['tables'],
            'selected_tables_count' => count($restoreSelection['tables']),
            'safety_backup_filename' => null,
            'message' => 'Восстановление поставлено в очередь',
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->putRestoreTaskStatus($taskId, $task);
        Storage::disk(self::DISK)->put(self::TASK_DIRECTORY.'/current_restore_task.txt', $taskId);
        \App\Jobs\RestoreDatabaseBackupJob::dispatch($taskId, $filename, $userId, $restoreSelection);

        return $task;
    }

    public function currentRestoreTask(): ?array
    {
        $path = self::TASK_DIRECTORY.'/current_restore_task.txt';
        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $taskId = trim(Storage::disk(self::DISK)->get($path));
        return $taskId !== '' ? $this->restoreTaskStatus($taskId) : null;
    }

    public function restoreTaskStatus(string $taskId): ?array
    {
        $taskId = $this->sanitizeTaskId($taskId);
        $path = self::TASK_DIRECTORY.'/'.$taskId.'.json';

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $decoded = json_decode(Storage::disk(self::DISK)->get($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function putRestoreTaskStatus(string $taskId, array $data): void
    {
        $taskId = $this->sanitizeTaskId($taskId);
        Storage::disk(self::DISK)->makeDirectory(self::TASK_DIRECTORY);

        $current = $this->restoreTaskStatus($taskId) ?? [];
        $status = array_merge($current, $data, [
            'id' => $taskId,
            'type' => 'restore',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Storage::disk(self::DISK)->put(
            self::TASK_DIRECTORY.'/'.$taskId.'.json',
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if (in_array($status['status'] ?? null, ['queued', 'running'], true)) {
            Storage::disk(self::DISK)->put(self::TASK_DIRECTORY.'/current_restore_task.txt', $taskId);
        }
    }

    public function createDownloadToken(string $filename, ?int $userId = null): array
    {
        $filename = $this->sanitizeFilename($filename);
        $this->absolutePath($filename);

        $token = Str::random(64);
        Cache::put(self::DOWNLOAD_TOKEN_PREFIX.$token, [
            'filename' => $filename,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ], now()->addMinutes(5));

        return [
            'token' => $token,
            'expires_in' => 300,
        ];
    }

    public function consumeDownloadToken(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{40,100}$/', $token)) {
            throw new RuntimeException('Некорректный токен скачивания');
        }

        $cacheKey = self::DOWNLOAD_TOKEN_PREFIX.$token;
        $payload = Cache::pull($cacheKey);

        if (! is_array($payload) || empty($payload['filename'])) {
            throw new RuntimeException('Ссылка скачивания устарела. Создайте новую ссылку.');
        }

        return $this->absolutePath((string) $payload['filename']);
    }

    public function currentTask(): ?array
    {
        $taskId = Cache::get('database_backup_current_task');
        if (! $taskId) {
            return null;
        }

        return $this->taskStatus((string) $taskId);
    }

    public function taskStatus(string $taskId): ?array
    {
        $task = Cache::get($this->taskCacheKey($taskId));
        return is_array($task) ? $task : null;
    }

    public function putTaskStatus(string $taskId, array $data): void
    {
        $current = $this->taskStatus($taskId) ?? [];
        $status = array_merge($current, $data, [
            'id' => $taskId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Cache::put($this->taskCacheKey($taskId), $status, now()->addHours(24));
        if (in_array($status['status'] ?? null, ['queued', 'running'], true)) {
            Cache::put('database_backup_current_task', $taskId, now()->addHours(24));
        }
    }

    private function createBackupWithoutLock(?string $name = null, ?string $comment = null, ?int $userId = null, ?string $taskId = null): array
    {
        $this->ensureDirectoryExists();
        $this->updateTask($taskId, 'running', 5, 'Подготовка');

        $config = $this->connectionConfig();
        $extension = match ($config['driver']) {
            'mysql', 'mariadb', 'pgsql' => 'sql.gz',
            'sqlite' => 'sqlite',
            default => 'sql',
        };
        $filename = sprintf(
            'database_%s_%s.%s',
            date('Y_m_d_H_i_s'),
            Str::lower(Str::random(6)),
            $extension
        );

        $relativePath = self::DIRECTORY.'/'.$filename;
        $temporaryRelativePath = $relativePath.'.part';
        $absolutePath = Storage::disk(self::DISK)->path($relativePath);
        $temporaryAbsolutePath = Storage::disk(self::DISK)->path($temporaryRelativePath);

        Storage::disk(self::DISK)->delete($temporaryRelativePath);

        try {
            $this->updateTask($taskId, 'running', 10, 'Создание дампа');
            match ($config['driver']) {
                'mysql', 'mariadb' => $this->dumpMysql($temporaryAbsolutePath, $config),
                'pgsql' => $this->dumpPostgres($temporaryAbsolutePath, $config),
                'sqlite' => $this->dumpSqlite($temporaryAbsolutePath, $config),
                default => throw new RuntimeException("Драйвер {$config['driver']} не поддерживается для бэкапа"),
            };

            if (! Storage::disk(self::DISK)->exists($temporaryRelativePath)) {
                throw new RuntimeException('Файл дампа не был создан');
            }

            $this->updateTask($taskId, 'running', 92, 'Проверка дампа');
            $this->validateBackupFile($temporaryAbsolutePath);

            Storage::disk(self::DISK)->move($temporaryRelativePath, $relativePath);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($temporaryRelativePath);
            File::delete($temporaryAbsolutePath);
            File::delete($this->temporarySqlPath($temporaryAbsolutePath));

            throw $e;
        }

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
            'verified_at' => date('Y-m-d H:i:s'),
            'verification' => 'ok',
        ];

        $tables = $this->databaseTableManifest($config);
        $metadata['tables'] = $tables;
        $metadata['table_groups'] = $this->summarizeTableGroups($tables);
        $metadata['table_count'] = count($tables);
        $metadata['total_rows_estimate'] = array_sum(array_map(fn ($table) => (int) ($table['rows'] ?? 0), $tables));
        $metadata['manifest_version'] = 1;

        $this->writeMetadata($filename, $metadata);
        $this->updateTask($taskId, 'completed', 100, 'Готово', [
            'filename' => $filename,
            'backup' => $metadata,
            'message' => 'Бэкап успешно создан и проверен',
        ]);

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

        $this->appendUsersToActionLogs($items);

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

    public function restoreWithSafetyBackup(string $filename, string $taskId, ?int $userId = null, array $options = []): void
    {
        $filename = $this->sanitizeFilename($filename);
        $restoreSelection = $this->normalizeRestoreSelection($filename, $options);
        $this->putRestoreTaskStatus((string) $taskId, [
            'status' => 'running',
            'progress' => 5,
            'stage' => 'Проверка файла',
            'filename' => $filename,
            'restore_mode' => $restoreSelection['mode'],
            'selected_groups' => $restoreSelection['groups'],
            'selected_tables' => $restoreSelection['tables'],
            'selected_tables_count' => count($restoreSelection['tables']),
            'message' => 'Проверяем выбранный дамп перед восстановлением',
        ]);

        $absolutePath = $this->absolutePath($filename);
        $this->validateBackupFile($absolutePath);

        $this->putRestoreTaskStatus((string) $taskId, [
            'status' => 'running',
            'progress' => 12,
            'stage' => 'Страховочный бэкап',
            'message' => 'Создаем копию текущей базы перед восстановлением',
        ]);

        $safetyBackup = $this->createBackup(
            'Страховочный бэкап перед восстановлением '.date('d.m.Y H:i'),
            'Автоматически создан перед восстановлением из '.$filename,
            $userId
        );

        $this->logAction('pre_restore_backup', $safetyBackup['filename'] ?? null, $userId, [
            'restore_task_id' => $taskId,
            'restore_filename' => $filename,
            'restore_mode' => $restoreSelection['mode'],
            'selected_tables_count' => count($restoreSelection['tables']),
            'size' => $safetyBackup['size'] ?? null,
        ]);

        $this->putRestoreTaskStatus((string) $taskId, [
            'status' => 'running',
            'progress' => 35,
            'stage' => 'Импорт дампа',
            'safety_backup_filename' => $safetyBackup['filename'] ?? null,
            'message' => $restoreSelection['mode'] === 'full' ? 'Страховочный бэкап создан, начинаем восстановление базы' : 'Страховочный бэкап создан, начинаем выборочное восстановление таблиц',
        ]);

        $this->restore($filename, $restoreSelection['tables']);

        $this->putRestoreTaskStatus((string) $taskId, [
            'status' => 'running',
            'progress' => 95,
            'stage' => 'Финальная проверка',
            'message' => 'Проверяем доступность базы после восстановления',
        ]);

        DB::select('SELECT 1');

        $this->putRestoreTaskStatus((string) $taskId, [
            'status' => 'completed',
            'progress' => 100,
            'stage' => 'Готово',
            'message' => 'База восстановлена из выбранного бэкапа',
        ]);
    }

    public function restore(string $filename, array $selectedTables = []): void
    {
        $filename = $this->sanitizeFilename($filename);
        $relativePath = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new RuntimeException('Файл бэкапа не найден');
        }

        $config = $this->connectionConfig();
        $absolutePath = Storage::disk(self::DISK)->path($relativePath);

        match ($config['driver']) {
            'mysql', 'mariadb' => $this->restoreMysql($absolutePath, $config, $selectedTables),
            'pgsql' => $this->restorePostgres($absolutePath, $config, $selectedTables),
            'sqlite' => $this->restoreSqlite($absolutePath, $config, $selectedTables),
            default => throw new RuntimeException("Драйвер {$config['driver']} не поддерживается для восстановления"),
        };
    }

    public function delete(string $filename): void
    {
        $activeRestore = $this->currentRestoreTask();
        if ($activeRestore && in_array($activeRestore['status'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('Нельзя удалять бэкапы во время восстановления базы.');
        }

        $activeBackup = $this->currentTask();
        if ($activeBackup && in_array($activeBackup['status'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('Нельзя удалять бэкапы во время создания нового дампа.');
        }

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

    public function environmentStatus(): array
    {
        $config = $this->connectionConfig();
        $driver = $config['driver'];
        $dumpBinary = null;
        $restoreBinary = null;
        $dumpEnvName = null;
        $restoreEnvName = null;

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dumpEnvName = 'DB_BACKUP_MYSQLDUMP_PATH';
            $restoreEnvName = 'DB_BACKUP_MYSQL_PATH';
            $dumpBinary = $this->findBinary(env($dumpEnvName), 'mysqldump', $dumpEnvName);
            $restoreBinary = $this->findBinary(env($restoreEnvName), 'mysql', $restoreEnvName);
        } elseif ($driver === 'pgsql') {
            $dumpEnvName = 'DB_BACKUP_PG_DUMP_PATH';
            $restoreEnvName = 'DB_BACKUP_PSQL_PATH';
            $dumpBinary = $this->findBinary(env($dumpEnvName), 'pg_dump', $dumpEnvName);
            $restoreBinary = $this->findBinary(env($restoreEnvName), 'psql', $restoreEnvName);
        }

        return [
            'driver' => $driver,
            'database' => $config['database'],
            'backup_directory' => storage_path('app/'.self::DIRECTORY),
            'backup_format' => in_array($driver, ['mysql', 'mariadb', 'pgsql'], true) ? '.sql.gz' : '.sqlite',
            'dump_binary' => $dumpBinary,
            'restore_binary' => $restoreBinary,
            'dump_binary_found' => (bool) $dumpBinary,
            'restore_binary_found' => (bool) $restoreBinary,
            'dump_env_name' => $dumpEnvName,
            'restore_env_name' => $restoreEnvName,
            'uses_native_dump' => (bool) $dumpBinary,
            'fallback_mode' => in_array($driver, ['mysql', 'mariadb'], true) && ! $dumpBinary ? 'php_pdo' : null,
            'current_task' => $this->currentTask(),
            'current_restore_task' => $this->currentRestoreTask(),
        ];
    }

    private function taskCacheKey(string $taskId): string
    {
        return 'database_backup_task:'.$taskId;
    }

    private function appendUsersToActionLogs(array &$items): void
    {
        $userIds = array_values(array_unique(array_filter(array_map(
            fn ($item) => $item['user_id'] ?? null,
            $items
        ))));

        if ($userIds === []) {
            return;
        }

        try {
            $users = DB::table('users')
                ->whereIn('id', $userIds)
                ->select('id', 'name', 'first_name', 'last_name', 'email')
                ->get()
                ->keyBy('id');

            foreach ($items as &$item) {
                $userId = $item['user_id'] ?? null;
                if (! $userId || ! isset($users[$userId])) {
                    continue;
                }

                $user = $users[$userId];
                $name = trim((string) ($user->name ?? ''));
                if ($name === '') {
                    $name = trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? ''));
                }
                if ($name === '') {
                    $name = (string) ($user->email ?? '');
                }

                $item['user_name'] = $name !== '' ? $name : null;
                $item['user_label'] = ($name !== '' ? $name : 'Пользователь').' ('.$userId.')';
            }
            unset($item);
        } catch (\Throwable) {
            return;
        }
    }

    private function updateTask(?string $taskId, string $status, int $progress, string $stage, array $extra = []): void
    {
        if (! $taskId) {
            return;
        }

        $this->putTaskStatus($taskId, array_merge([
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'stage' => $stage,
        ], $extra));
    }

    private function dumpMysql(string $absolutePath, array $config): void
    {
        $sqlPath = $this->temporarySqlPath($absolutePath);
        $binary = $this->findBinary(env('DB_BACKUP_MYSQLDUMP_PATH'), 'mysqldump', 'DB_BACKUP_MYSQLDUMP_PATH');
        try {
            if (! $binary) {
                $this->dumpMysqlWithPdo($sqlPath);
                $this->updateCurrentRunningTask(75, 'Сжатие дампа');
                $this->gzipFile($sqlPath, $absolutePath);

                return;
            }

            $command = [
                $binary,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--host='.$config['host'],
                '--port='.(string) $config['port'],
                '--user='.$config['username'],
                '--result-file='.$sqlPath,
                $config['database'],
            ];

            $this->runProcess($command, ['MYSQL_PWD' => (string) $config['password']], 1800);
            $this->updateCurrentRunningTask(75, 'Сжатие дампа');
            $this->gzipFile($sqlPath, $absolutePath);
        } finally {
            File::delete($sqlPath);
        }
    }

    private function restoreMysql(string $absolutePath, array $config, array $selectedTables = []): void
    {
        $this->updateCurrentRestoreTask(40, 'Подготовка SQL-файла');
        $binary = $this->findBinary(env('DB_BACKUP_MYSQL_PATH'), 'mysql', 'DB_BACKUP_MYSQL_PATH');
        $restorePath = $this->gunzipToTemporarySqlIfNeeded($absolutePath);
        if ($selectedTables !== [] || ! $binary) {
            $this->updateCurrentRestoreTask(55, $selectedTables === [] ? 'Импорт через PHP/PDO' : 'Выборочный импорт таблиц');
            $this->restoreSqlWithPdo($restorePath, $selectedTables);
            if ($restorePath !== $absolutePath) {
                File::delete($restorePath);
            }

            return;
        }

        $safePath = str_replace('\\', '/', $restorePath);
        $command = [
            $binary,
            '--default-character-set=utf8mb4',
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--user='.$config['username'],
            $config['database'],
            '--execute=source '.$safePath,
        ];

        try {
            $this->updateCurrentRestoreTask(55, 'Импорт через mysql client');
            $this->runProcess($command, ['MYSQL_PWD' => (string) $config['password']], 1800);
        } finally {
            if ($restorePath !== $absolutePath) {
                File::delete($restorePath);
            }
        }
    }

    private function dumpPostgres(string $absolutePath, array $config): void
    {
        $sqlPath = $this->temporarySqlPath($absolutePath);
        $binary = $this->resolveBinary(env('DB_BACKUP_PG_DUMP_PATH'), 'pg_dump', 'DB_BACKUP_PG_DUMP_PATH');
        $command = [
            $binary,
            '--clean',
            '--if-exists',
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            '--file='.$sqlPath,
            $config['database'],
        ];

        try {
            $this->runProcess($command, ['PGPASSWORD' => (string) $config['password']], 1800);
            $this->updateCurrentRunningTask(75, 'Сжатие дампа');
            $this->gzipFile($sqlPath, $absolutePath);
        } finally {
            File::delete($sqlPath);
        }
    }

    private function restorePostgres(string $absolutePath, array $config, array $selectedTables = []): void
    {
        if ($selectedTables !== []) {
            throw new RuntimeException('Выборочное восстановление пока поддерживается только для MySQL/MariaDB дампов.');
        }

        $this->updateCurrentRestoreTask(40, 'Подготовка SQL-файла');
        $restorePath = $this->gunzipToTemporarySqlIfNeeded($absolutePath);
        $binary = $this->resolveBinary(env('DB_BACKUP_PSQL_PATH'), 'psql', 'DB_BACKUP_PSQL_PATH');
        $command = [
            $binary,
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            '--file='.$restorePath,
        ];

        try {
            $this->runProcess($command, ['PGPASSWORD' => (string) $config['password']], 1800);
        } finally {
            if ($restorePath !== $absolutePath) {
                File::delete($restorePath);
            }
        }
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

        $tables = $this->mysqlTables();
        $tableCount = max(1, count($tables));
        foreach ($tables as $tableIndex => $table) {
            $this->updateCurrentRunningTask(10 + (int) floor(($tableIndex / $tableCount) * 60), 'Экспорт таблицы '.$table);
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

                if (count($batch) >= 1000) {
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

    private function updateCurrentRestoreTask(int $progress, string $stage): void
    {
        $path = self::TASK_DIRECTORY.'/current_restore_task.txt';
        if (! Storage::disk(self::DISK)->exists($path)) {
            return;
        }

        $taskId = trim(Storage::disk(self::DISK)->get($path));
        if ($taskId === '') {
            return;
        }

        $task = $this->restoreTaskStatus($taskId);
        if (($task['status'] ?? null) === 'running') {
            $this->putRestoreTaskStatus($taskId, [
                'status' => 'running',
                'progress' => max(0, min(100, $progress)),
                'stage' => $stage,
            ]);
        }
    }

    private function updateCurrentRunningTask(int $progress, string $stage): void
    {
        $taskId = Cache::get('database_backup_current_task');
        if (! $taskId) {
            return;
        }
        $task = $this->taskStatus((string) $taskId);
        if (($task['status'] ?? null) === 'running') {
            $this->updateTask((string) $taskId, 'running', $progress, $stage);
        }
    }

    private function restoreSqlWithPdo(string $absolutePath, array $selectedTables = []): void
    {
        $sql = File::get($absolutePath);

        DB::connection()->disableQueryLog();
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');

        $selectedLookup = $selectedTables !== [] ? array_fill_keys($selectedTables, true) : [];
        $processed = 0;

        foreach ($this->splitSqlStatements($sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if ($selectedLookup !== [] && ! $this->statementTargetsSelectedTable($trimmed, $selectedLookup)) {
                continue;
            }

            DB::unprepared($trimmed);
            $processed++;
            if ($selectedLookup !== [] && $processed % 100 === 0) {
                $this->updateCurrentRestoreTask(55 + min(35, (int) floor($processed / 100)), 'Выборочный импорт таблиц');
            }
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
    }

    private function normalizeRestoreSelection(string $filename, array $options): array
    {
        $mode = (string) ($options['mode'] ?? 'full');
        if (! in_array($mode, ['full', 'groups', 'tables'], true)) {
            $mode = 'full';
        }

        if ($mode === 'full') {
            return [
                'mode' => 'full',
                'groups' => [],
                'tables' => [],
            ];
        }

        $manifest = $this->backupManifest($filename);
        $tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];
        if ($tables === []) {
            throw new RuntimeException('Для выбранного дампа нет манифеста таблиц. Выборочное восстановление доступно только для новых бэкапов.');
        }

        $availableTables = [];
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '') {
                $availableTables[$name] = (string) ($table['group'] ?? $this->tableGroup($name));
            }
        }

        $selectedGroups = [];
        $selectedTables = [];

        if ($mode === 'groups') {
            $requestedGroups = array_values(array_unique(array_filter(array_map('strval', $options['groups'] ?? []))));
            if ($requestedGroups === []) {
                throw new RuntimeException('Выберите хотя бы одну группу таблиц для восстановления.');
            }

            foreach ($availableTables as $table => $group) {
                if (in_array($group, $requestedGroups, true)) {
                    $selectedTables[] = $table;
                }
            }
            $selectedGroups = $requestedGroups;
        }

        if ($mode === 'tables') {
            $requestedTables = array_values(array_unique(array_filter(array_map('strval', $options['tables'] ?? []))));
            if ($requestedTables === []) {
                throw new RuntimeException('Выберите хотя бы одну таблицу для восстановления.');
            }

            foreach ($requestedTables as $table) {
                if (! array_key_exists($table, $availableTables)) {
                    throw new RuntimeException('Таблица '.$table.' отсутствует в манифесте выбранного дампа.');
                }
                $selectedTables[] = $table;
            }
        }

        $selectedTables = array_values(array_unique($selectedTables));
        sort($selectedTables);

        if ($selectedTables === []) {
            throw new RuntimeException('Не найдено таблиц для выборочного восстановления.');
        }

        $blockedTables = array_values(array_filter($selectedTables, fn ($table) => ! $this->isPartialRestoreTableAllowed($table)));
        if ($blockedTables !== []) {
            throw new RuntimeException('Выборочное восстановление системных таблиц запрещено: '.implode(', ', $blockedTables).'. Используйте полное восстановление дампа.');
        }

        return [
            'mode' => $mode,
            'groups' => $selectedGroups,
            'tables' => $selectedTables,
        ];
    }

    private function statementTargetsSelectedTable(string $statement, array $selectedLookup): bool
    {
        $normalized = ltrim($statement);

        if (preg_match('/^(SET|START TRANSACTION|COMMIT|UNLOCK TABLES)\b/i', $normalized)) {
            return true;
        }

        $patterns = [
            '/^(DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?)/i',
            '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)/i',
            '/^(INSERT\s+INTO\s+)/i',
            '/^(REPLACE\s+INTO\s+)/i',
            '/^(LOCK\s+TABLES\s+)/i',
            '/^(ALTER\s+TABLE\s+)/i',
            '/^(TRUNCATE\s+TABLE\s+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $normalized, $match)) {
                continue;
            }

            $tail = substr($normalized, strlen($match[1]));
            $table = $this->extractSqlTableName($tail);
            return $table !== null && isset($selectedLookup[$table]);
        }

        return false;
    }

    private function extractSqlTableName(string $sqlTail): ?string
    {
        $sqlTail = ltrim($sqlTail);

        if (preg_match('/^`((?:``|[^`])+)`/', $sqlTail, $match)) {
            return str_replace('``', '`', $match[1]);
        }

        if (preg_match('/^([a-zA-Z0-9_]+)/', $sqlTail, $match)) {
            return $match[1];
        }

        return null;
    }

    private function databaseTableManifest(array $config): array
    {
        try {
            return match ($config['driver']) {
                'mysql', 'mariadb' => $this->mysqlTableManifest($config),
                'pgsql' => $this->postgresTableManifest(),
                'sqlite' => $this->sqliteTableManifest(),
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }
    }

    private function mysqlTableManifest(array $config): array
    {
        $rows = DB::select('SHOW TABLE STATUS');
        $tables = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $name = (string) ($data['Name'] ?? '');
            if ($name === '') {
                continue;
            }

            $dataLength = (int) ($data['Data_length'] ?? 0);
            $indexLength = (int) ($data['Index_length'] ?? 0);
            $size = $dataLength + $indexLength;

            $tables[] = [
                'name' => $name,
                'group' => $this->tableGroup($name),
                'engine' => $data['Engine'] ?? null,
                'rows' => (int) ($data['Rows'] ?? 0),
                'rows_estimated' => true,
                'data_size' => $dataLength,
                'index_size' => $indexLength,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
            ];
        }

        usort($tables, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $tables;
    }

    private function postgresTableManifest(): array
    {
        $rows = DB::select("select relname as name, n_live_tup as rows, pg_total_relation_size(relid) as size from pg_stat_user_tables order by relname");

        return array_map(function ($row) {
            $data = (array) $row;
            $name = (string) ($data['name'] ?? '');
            $size = (int) ($data['size'] ?? 0);

            return [
                'name' => $name,
                'group' => $this->tableGroup($name),
                'engine' => null,
                'rows' => (int) ($data['rows'] ?? 0),
                'rows_estimated' => true,
                'data_size' => $size,
                'index_size' => 0,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
            ];
        }, $rows);
    }

    private function sqliteTableManifest(): array
    {
        $rows = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name");
        $tables = [];

        foreach ($rows as $row) {
            $name = (string) (((array) $row)['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $countRows = DB::select('select count(*) as count from '.$this->quoteIdentifier($name));
            $tables[] = [
                'name' => $name,
                'group' => $this->tableGroup($name),
                'engine' => 'sqlite',
                'rows' => (int) (((array) ($countRows[0] ?? []))['count'] ?? 0),
                'rows_estimated' => false,
                'data_size' => 0,
                'index_size' => 0,
                'size' => 0,
                'size_human' => '0 B',
            ];
        }

        return $tables;
    }

    private function summarizeTableGroups(array $tables): array
    {
        $groups = [];

        foreach ($tables as $table) {
            $group = (string) ($table['group'] ?? $this->tableGroup((string) ($table['name'] ?? '')));
            if (! isset($groups[$group])) {
                $groups[$group] = [
                    'key' => $group,
                    'label' => $this->tableGroupLabel($group),
                    'tables_count' => 0,
                    'rows' => 0,
                    'size' => 0,
                    'size_human' => '0 B',
                ];
            }

            $groups[$group]['tables_count']++;
            $groups[$group]['rows'] += (int) ($table['rows'] ?? 0);
            $groups[$group]['size'] += (int) ($table['size'] ?? 0);
            $groups[$group]['size_human'] = $this->formatBytes((int) $groups[$group]['size']);
        }

        return array_values($groups);
    }

    private function isPartialRestoreTableAllowed(string $table): bool
    {
        return ! Str::startsWith($table, [
            'cache',
            'jobs',
            'failed_jobs',
            'migrations',
            'settings',
        ]);
    }

    private function tableGroup(string $table): string
    {
        if (Str::startsWith($table, ['shop_', 'promocode', 'bonus_', 'absent_promocode'])) {
            return 'shop';
        }

        if (Str::startsWith($table, ['users', 'roles', 'permissions', 'model_', 'personal_access_tokens', 'sessions'])) {
            return 'users';
        }

        if (Str::startsWith($table, ['site_', 'constructor_', 'slider', 'textblocks', 'contacts', 'admin_menu'])) {
            return 'content';
        }

        if (Str::startsWith($table, ['export_', 'import_', 'ozon_', 'yandex_', 'avito_', 'sr_'])) {
            return 'integrations';
        }

        if (Str::startsWith($table, ['cache', 'jobs', 'failed_jobs', 'migrations', 'settings'])) {
            return 'system';
        }

        return 'other';
    }

    private function tableGroupLabel(string $group): string
    {
        return [
            'shop' => 'Магазин',
            'users' => 'Пользователи и доступы',
            'content' => 'Контент и настройки сайта',
            'integrations' => 'Интеграции и импорты',
            'system' => 'Системные таблицы',
            'other' => 'Прочее',
        ][$group] ?? $group;
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

    private function restoreSqlite(string $absolutePath, array $config, array $selectedTables = []): void
    {
        if ($selectedTables !== []) {
            throw new RuntimeException('Выборочное восстановление SQLite не поддерживается.');
        }

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

        return $this->findCommonWindowsBinary($defaultBinary);
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

    private function findCommonWindowsBinary(string $binary): ?string
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return null;
        }

        $exe = Str::endsWith(Str::lower($binary), '.exe') ? $binary : $binary.'.exe';
        $candidates = [
            'C:/Program Files/MySQL/MySQL Server 8.0/bin/'.$exe,
            'C:/Program Files/MySQL/MySQL Server 8.4/bin/'.$exe,
            'C:/Program Files/MariaDB 10.11/bin/'.$exe,
            'C:/Program Files/MariaDB 11.4/bin/'.$exe,
            'C:/xampp/mysql/bin/'.$exe,
            'C:/laragon/bin/mysql/mysql-8.0/bin/'.$exe,
            'C:/OpenServer/modules/database/MySQL-8.0/bin/'.$exe,
            'C:/OpenServer/modules/database/MariaDB-10.11/bin/'.$exe,
        ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function temporarySqlPath(string $absolutePath): string
    {
        return preg_replace('/\.gz$/', '', $absolutePath).'.tmp';
    }

    private function gzipFile(string $sourcePath, string $targetPath): void
    {
        $input = fopen($sourcePath, 'rb');
        $output = gzopen($targetPath, 'wb6');

        if (! $input || ! $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('Не удалось сжать файл бэкапа');
        }

        while (! feof($input)) {
            gzwrite($output, fread($input, 1024 * 1024));
        }

        fclose($input);
        gzclose($output);
    }

    private function validateBackupFile(string $absolutePath): void
    {
        if (! File::exists($absolutePath) || File::size($absolutePath) <= 0) {
            throw new RuntimeException('Проверка дампа не пройдена: файл пустой или не создан');
        }

        $handle = fopen($absolutePath, 'rb');
        $signature = $handle ? fread($handle, 2) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }

        $isGzip = $signature === "\x1f\x8b";
        if (! $isGzip) {
            return;
        }

        $gz = gzopen($absolutePath, 'rb');
        if (! $gz) {
            throw new RuntimeException('Проверка дампа не пройдена: gzip-архив не открывается');
        }

        $bytesRead = 0;
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 1024 * 1024);
            if ($chunk === false) {
                gzclose($gz);
                throw new RuntimeException('Проверка дампа не пройдена: ошибка чтения gzip-архива');
            }
            $bytesRead += strlen($chunk);
        }
        gzclose($gz);

        if ($bytesRead <= 0) {
            throw new RuntimeException('Проверка дампа не пройдена: gzip-архив не содержит SQL-данных');
        }
    }

    private function gunzipToTemporarySqlIfNeeded(string $absolutePath): string
    {
        $this->updateCurrentRestoreTask(42, 'Распаковка дампа');
        if (! Str::endsWith($absolutePath, '.gz')) {
            return $absolutePath;
        }

        $targetPath = $this->temporarySqlPath($absolutePath);
        $input = gzopen($absolutePath, 'rb');
        $output = fopen($targetPath, 'wb');

        if (! $input || ! $output) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Не удалось распаковать файл бэкапа');
        }

        while (! gzeof($input)) {
            fwrite($output, gzread($input, 1024 * 1024));
        }

        gzclose($input);
        fclose($output);

        return $targetPath;
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

    private function sanitizeTaskId(string $taskId): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $taskId)) {
            throw new RuntimeException('Некорректный идентификатор задачи');
        }

        return $taskId;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);

        if (! preg_match('/^[a-zA-Z0-9_.-]+\.(sql|sql\.gz|sqlite)$/', $filename)) {
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
