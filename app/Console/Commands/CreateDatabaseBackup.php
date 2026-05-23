<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
class CreateDatabaseBackup extends Command
{
    protected $signature = 'database:backup {--scheduled : Бэкап запущен планировщиком}';

    protected $description = 'Создать дамп базы данных';

    public function handle(DatabaseBackupService $backupService): int
    {
        $backup = $backupService->createBackup(
            $this->option('scheduled') ? 'Плановый бэкап БД' : 'Ручной бэкап БД',
            $this->option('scheduled') ? 'Создан автоматически по расписанию' : 'Создан из консоли'
        );

        if ($this->option('scheduled')) {
            $backupService->logAction('automatic_create', $backup['filename'], null, [
                'size' => $backup['size'] ?? null,
                'auto_delete_at' => $backup['auto_delete_at'] ?? null,
            ]);
            $backupService->deleteExpiredBackups((int) $this->setting('database_backup_retention_days', 30));
        } else {
            $backupService->logAction('console_create', $backup['filename'], null, [
                'size' => $backup['size'] ?? null,
                'auto_delete_at' => $backup['auto_delete_at'] ?? null,
            ]);
        }

        $this->info('Бэкап создан: '.$backup['filename']);

        return self::SUCCESS;
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return \Illuminate\Support\Facades\DB::table('settings')->where('key', $key)->value('value') ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
