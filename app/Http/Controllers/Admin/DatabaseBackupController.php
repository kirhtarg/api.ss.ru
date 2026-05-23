<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatabaseBackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backupService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'backups' => $this->backupService->listBackups(),
                'schedule' => $this->getScheduleData(),
                'logs' => $this->backupService->listActionLogs(),
                'environment' => $this->backupService->environmentStatus(),
            ],
        ]);
    }

    public function logs(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->backupService->listActionLogs(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $task = $this->backupService->createQueuedBackup(
                $validated['name'] ?? null,
                $validated['comment'] ?? null,
                $request->user()?->id
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка постановки бэкапа в очередь: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Создание бэкапа поставлено в очередь',
            'data' => $task,
        ], 202);
    }

    public function currentTask(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->backupService->currentTask(),
        ]);
    }

    public function taskStatus(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->backupService->taskStatus($taskId),
        ]);
    }

    public function restore(string $filename, Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => 'required|accepted',
        ]);

        try {
            $this->backupService->restore($filename);
            $this->backupService->logAction('restore', $filename, $request->user()?->id);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка восстановления базы: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'База данных восстановлена из выбранного бэкапа',
        ]);
    }

    public function destroy(string $filename, Request $request): JsonResponse
    {
        try {
            $this->backupService->delete($filename);
            $this->backupService->logAction('manual_delete', $filename, $request->user()?->id);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка удаления бэкапа: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Бэкап удален',
        ]);
    }

    public function download(string $filename)
    {
        $path = $this->backupService->absolutePath($filename);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function schedule(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getScheduleData(),
        ]);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'frequency' => 'required|string|in:hourly,daily,weekly,monthly',
            'time' => 'required|string|regex:/^[0-9]{2}:[0-9]{2}$/',
            'weekday' => 'nullable|integer|min:0|max:6',
            'retention_days' => 'required|integer|min:1|max:3650',
        ]);

        foreach ($validated as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'database_backup_'.$key],
                [
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    'type' => is_bool($value) ? 'boolean' : 'string',
                    'group' => 'system',
                    'description' => 'Настройка расписания бэкапов базы данных',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Расписание бэкапов сохранено',
            'data' => $this->getScheduleData(),
        ]);
    }

    private function getScheduleData(): array
    {
        $defaults = $this->backupService->defaultSchedule();
        $settings = DB::table('settings')
            ->whereIn('key', array_map(fn ($key) => 'database_backup_'.$key, array_keys($defaults)))
            ->pluck('value', 'key')
            ->toArray();

        return [
            'enabled' => ($settings['database_backup_enabled'] ?? ($defaults['enabled'] ? '1' : '0')) === '1',
            'frequency' => $settings['database_backup_frequency'] ?? $defaults['frequency'],
            'time' => $settings['database_backup_time'] ?? $defaults['time'],
            'weekday' => (int) ($settings['database_backup_weekday'] ?? $defaults['weekday']),
            'retention_days' => (int) ($settings['database_backup_retention_days'] ?? $defaults['retention_days']),
        ];
    }

    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => mb_convert_encoding($message, 'UTF-8', 'UTF-8'),
        ], $status);
    }
}
