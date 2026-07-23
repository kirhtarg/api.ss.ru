<?php

namespace App\Jobs;

use App\Services\DatabaseBackupService;
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
        public ?int $userId = null
    ) {
    }

    public function handle(DatabaseBackupService $backupService): void
    {
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '7200');

        try {
            $backupService->restoreWithSafetyBackup($this->filename, $this->taskId, $this->userId);
            $backupService->logAction('restore', $this->filename, $this->userId, [
                'task_id' => $this->taskId,
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

            throw $e;
        }
    }
}
