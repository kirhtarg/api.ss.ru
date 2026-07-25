<?php

namespace App\Jobs;

use App\Services\DatabaseBackupService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RestoreDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200;

    public $tries = 1;

    public function __construct(
        public string $taskId,
        public string $filename,
        public ?int $userId = null,
        public array $options = []
    ) {
    }

    public function handle(DatabaseBackupService $backupService): void
    {
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '7200');

        try {
            $this->notifyBackup('started', 'restore', [
                'filename' => $this->filename,
                'mode' => $this->options['mode'] ?? 'full',
                'selected_tables_count' => count($this->options['tables'] ?? []),
            ]);

            $backupService->restoreWithSafetyBackup($this->filename, $this->taskId, $this->userId, $this->options);
            $backupService->logAction('restore', $this->filename, $this->userId, [
                'task_id' => $this->taskId,
            ]);

            $this->notifyBackup('completed', 'restore', [
                'filename' => $this->filename,
                'mode' => $this->options['mode'] ?? 'full',
                'selected_tables_count' => count($this->options['tables'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('Database restore job failed', [
                'task_id' => $this->taskId,
                'filename' => $this->filename,
                'error' => $e->getMessage(),
            ]);

            $backupService->putRestoreTaskStatus($this->taskId, [
                'status' => 'failed',
                'progress' => 100,
                'stage' => 'Ошибка',
                'message' => $e->getMessage(),
            ]);

            $backupService->logAction('failed_restore', $this->filename, $this->userId, [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            $this->notifyBackup('failed', 'restore', [
                'filename' => $this->filename,
                'mode' => $this->options['mode'] ?? 'full',
                'selected_tables_count' => count($this->options['tables'] ?? []),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function notifyBackup(string $status, string $operation, array $data = []): void
    {
        try {
            app(NotificationService::class)->notifyBackup($status, $operation, array_merge($data, [
                'task_id' => $this->taskId,
                'user_id' => $this->userId,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Database backup notification failed', [
                'task_id' => $this->taskId,
                'status' => $status,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}