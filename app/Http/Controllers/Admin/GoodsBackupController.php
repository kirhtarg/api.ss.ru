<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsImportBackup;
use App\Services\GoodsBackupService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GoodsBackupController extends Controller
{
    private GoodsBackupService $backupService;

    public function __construct(GoodsBackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Получить список резервных копий
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $backups = GoodsImportBackup::with('user')
                ->where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $backups
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения списка резервных копий', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка резервных копий'
            ], 500);
        }
    }

    /**
     * Создать резервную копию
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Неверные данные',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $backup = $this->backupService->createBackup(
                $request->input('name'),
                auth()->id()
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $backup,
                'message' => 'Резервная копия успешно создана'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Ошибка создания резервной копии', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания резервной копии: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Показать информацию о резервной копии
     */
    public function show(GoodsImportBackup $backup): JsonResponse
    {
        // Проверяем, что пользователь может видеть эту копию
        if ($backup->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $backup->load('user')
        ]);
    }

    /**
     * Восстановить из резервной копии
     */
    public function restore(GoodsImportBackup $backup, Request $request): JsonResponse
    {
        // Проверяем, что пользователь может восстанавливать эту копию
        if ($backup->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $this->backupService->restoreBackup($backup);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Резервная копия успешно восстановлена'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Ошибка восстановления резервной копии', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка восстановления резервной копии: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить резервную копию
     */
    public function destroy(GoodsImportBackup $backup): JsonResponse
    {
        // Проверяем, что пользователь может удалять эту копию
        if ($backup->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен'
            ], 403);
        }

        try {
            // Удаляем файл
            $backup->deleteFile();

            // Удаляем запись из базы
            $backup->delete();

            return response()->json([
                'success' => true,
                'message' => 'Резервная копия удалена'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления резервной копии', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления резервной копии'
            ], 500);
        }
    }
}
