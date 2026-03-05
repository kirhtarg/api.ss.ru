<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $pendingJobs = DB::table('jobs')
            ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
            ->count();
        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => $pendingJobs,
                'failed' => $failedJobs,
                'status' => $pendingJobs > 0 ? 'working' : 'idle',
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
