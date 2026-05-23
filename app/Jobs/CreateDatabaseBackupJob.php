<?php

namespace App\Jobs;

use App\Services\DatabaseBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200;

    public $tries = 1;

    public function __construct(
        public string $taskId,
        public ?string $name = null,
        public ?string $comment = null,
        public ?int $userId = null
    ) {
    }

    public function handle(DatabaseBackupService $backupService): void
    {
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '7200');

        $backupService->putTaskStatus($this->taskId, [
            'status' => 'running',
            'progress' => 3,
            'stage' => 'Запуск задачи',
            'message' => 'Задача выполняется',
        ]);

        try {
            $backup = $backupService->createBackup($this->name, $this->comment, $this->userId, $this->taskId);
            $backupService->logAction('manual_create', $backup['filename'] ?? null, $this->userId, [
                'size' => $backup['size'] ?? null,
                'auto_delete_at' => $backup['auto_delete_at'] ?? null,
                'task_id' => $this->taskId,
                'verification' => $backup['verification'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Database backup job failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            $backupService->putTaskStatus($this->taskId, [
                'status' => 'failed',
                'progress' => 100,
                'stage' => 'Ошибка',
                'message' => $e->getMessage(),
            ]);

            $backupService->logAction('failed_create', null, $this->userId, [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
