<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class QueueStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $connection = (string) config('queue.default');
        $pendingJobs = 0;
        $failedJobs = 0;
        $heartbeat = null;
        $diagnosticError = null;

        try {
            $pendingJobs = Queue::connection($connection)->size('default');
        } catch (\Throwable $e) {
            $diagnosticError = 'Не удалось получить размер очереди: '.$e->getMessage();
        }

        try {
            $failedJobs = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (\Throwable $e) {
            $diagnosticError ??= 'Не удалось получить список ошибок очереди: '.$e->getMessage();
        }

        try {
            $heartbeat = Cache::get('queue_worker_heartbeat');
        } catch (\Throwable $e) {
            $diagnosticError ??= 'Не удалось прочитать heartbeat worker: '.$e->getMessage();
        }

        $heartbeatTimestamp = is_array($heartbeat) ? (int) ($heartbeat['timestamp'] ?? 0) : 0;
        $heartbeatTtl = max(30, (int) config('queue.worker_heartbeat_ttl', 90));
        $workerActive = $heartbeatTimestamp > 0 && (time() - $heartbeatTimestamp) <= $heartbeatTtl;
        $status = $workerActive ? ($pendingJobs > 0 ? 'working' : 'idle') : 'inactive';

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => $pendingJobs,
                'failed' => $failedJobs,
                'status' => $status,
                'worker_active' => $workerActive,
                'connection' => $connection,
                'heartbeat_at' => $heartbeatTimestamp > 0
                    ? date(DATE_ATOM, $heartbeatTimestamp)
                    : null,
                'message' => $workerActive
                    ? null
                    : 'Worker очереди не передаёт heartbeat. Проверьте Supervisor и перезапустите worker после деплоя.',
                'diagnostic_error' => $diagnosticError,
            ],
        ]);
    }

    public function restart(): JsonResponse
    {
        try {
            Artisan::call('queue:restart');

            return response()->json([
                'success' => true,
                'message' => 'Сигнал перезапуска очереди отправлен',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перезапуске очереди: '.$e->getMessage(),
            ], 500);
        }
    }

    public function retryFailed(): JsonResponse
    {
        try {
            Artisan::call('queue:retry', ['all']);

            return response()->json([
                'success' => true,
                'message' => 'Запущен повтор всех неудачных задач',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при повторе задач: '.$e->getMessage(),
            ], 500);
        }
    }

    public function clearFailedModex(): JsonResponse
    {
        try {
            $deleted = DB::table('failed_jobs')
                ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Очищены ошибки очереди модекса',
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки ошибок очереди: '.$e->getMessage(),
            ], 500);
        }
    }

    public function clearPendingModex(): JsonResponse
    {
        try {
            $deleted = DB::table('jobs')
                ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Очищены задачи очереди модекса',
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки задач очереди: '.$e->getMessage(),
            ], 500);
        }
    }
}
