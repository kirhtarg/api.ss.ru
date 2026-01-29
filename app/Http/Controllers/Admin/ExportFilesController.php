<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExportFilesController extends Controller
{
    /**
     * Получить список всех файлов экспорта
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExportFile::with('creator')
            ->orderBy('created_at', 'desc');

        // Фильтр по статусу
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Фильтр по создателю
        if ($request->has('created_by')) {
            $query->where('created_by', $request->get('created_by'));
        }

        // Большое количество файлов на странице по умолчанию
        $perPage = $request->get('per_page', 100);

        $files = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $files->items(),
            'pagination' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total()
            ]
        ]);
    }

    /**
     * Создать новый файл экспорта (запустить процесс)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'format' => ['required', Rule::in(['excel', 'csv', 'txt'])],
            'filename' => 'nullable|string|max:100',
            'export_config' => 'nullable|array'
        ]);


        // Определяем расширение файла
        $format = $request->format;
        $extension = match($format) {
            'excel' => 'xlsx',  // Настоящий Excel файл
            'csv' => 'csv',
            'txt' => 'txt',
            default => 'xlsx'
        };

        // Определяем название файла
        $customFilename = trim($request->filename ?? '');
        if ($customFilename) {
            // Если указано пользовательское название, используем его
            $originalFilename = $customFilename;
            // Добавляем расширение, если его нет
            if (!preg_match('/\.(xlsx?|csv|txt)$/i', $originalFilename)) {
                $originalFilename .= '.' . $extension;
            } else {
                // Если расширение уже есть, заменяем его на правильное
                $originalFilename = preg_replace('/\.(xlsx?|csv|txt)$/i', '.' . $extension, $originalFilename);
            }
            // Генерируем системное имя файла
            $filename = 'export_' . time() . '_' . uniqid() . '.' . $extension;
        } else {
            // Автоматическое название
            $originalFilename = 'Экспорт товаров ' . now()->format('d.m.Y H:i') . '.' . $extension;
            $filename = 'export_' . time() . '_' . uniqid() . '.' . $extension;
        }

        $file = ExportFile::create([
            'created_by' => Auth::id(),
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'format' => $format, // Сохраняем оригинальный format (excel, csv, txt)
            'status' => 'pending',
            'export_config' => $request->export_config
        ]);

        // Запускаем задачу экспорта (синхронно для надежности)
        \App\Jobs\ProcessExportJob::dispatchSync($file);

        return response()->json([
            'success' => true,
            'data' => $file->load('creator'),
            'message' => 'Процесс экспорта запущен'
        ]);
    }

    /**
     * Тестовый метод для завершения экспорта (для тестирования)
     */
    public function completeTest(ExportFile $exportFile): JsonResponse
    {
        // Создаем тестовый файл
        $testContent = "id,name,sku,price\n1,Тестовый товар 1,TEST001,100\n2,Тестовый товар 2,TEST002,200\n";
        $filePath = 'exports/' . $exportFile->filename;

        Storage::put($filePath, $testContent);

        $exportFile->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_size' => strlen($testContent),
            'total_rows' => 2
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Экспорт завершен (тест)'
        ]);
    }

    /**
     * Скачать файл экспорта
     */
    public function download(Request $request, ExportFile $exportFile): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Проверяем аутентификацию через token в query параметре
        $token = $request->query('token');

        if ($token) {
            // Токен может иметь формат "id|token", извлекаем token часть
            $tokenParts = explode('|', $token);
            $tokenValue = end($tokenParts);

            // Ищем токен в базе
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($tokenValue);

            if (!$accessToken) {
                // Если не нашли, пробуем с полным токеном
                $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            }

            if (!$accessToken || !$accessToken->tokenable) {
                abort(401, 'Неверный токен');
            }

            $user = $accessToken->tokenable;

            // Проверяем, что пользователь имеет доступ к файлу
            if ($user->id !== $exportFile->created_by && !$user->hasRole(['admin', 'manager'])) {
                abort(403, 'Доступ запрещен');
            }
        } else {
            // Стандартная аутентификация
            if (!Auth::check()) {
                abort(401, 'Не авторизован');
            }

            $user = Auth::user();

            // Проверяем доступ
            if ($user->id !== $exportFile->created_by && !$user->hasRole(['admin', 'manager'])) {
                abort(403, 'Доступ запрещен');
            }
        }
        // TODO: Восстановить после исправления проблемы с токенами

        // Проверяем, что файл готов к скачиванию
        if (!$exportFile->isDownloadable()) {
            \Log::error('File not downloadable', [
                'file_id' => $exportFile->id,
                'status' => $exportFile->status,
                'file_path' => $exportFile->file_path,
                'full_path' => $exportFile->getFullPath(),
                'file_exists' => file_exists($exportFile->getFullPath())
            ]);
            abort(404, 'Файл не найден или еще не готов');
        }

        $fullPath = $exportFile->getFullPath();
        $filename = $exportFile->original_filename;

        \Log::info('Starting file download', [
            'file_id' => $exportFile->id,
            'full_path' => $fullPath,
            'filename' => $filename,
            'file_exists' => file_exists($fullPath),
            'is_readable' => is_readable($fullPath)
        ]);

        if (!file_exists($fullPath)) {
            \Log::error('File does not exist for download', ['path' => $fullPath]);
            abort(404, 'Файл не найден');
        }

        return response()->download($fullPath, $filename);
    }

    /**
     * Удалить файл экспорта
     */
    public function destroy(ExportFile $exportFile): JsonResponse
    {
        // Удаляем физический файл, если он существует
        if ($exportFile->file_path && Storage::exists($exportFile->file_path)) {
            Storage::delete($exportFile->file_path);
        }

        // Удаляем запись из базы данных
        $exportFile->delete();

        return response()->json([
            'success' => true,
            'message' => 'Файл удален'
        ]);
    }

    /**
     * Очистить все файлы экспорта
     */
    public function clearAll(Request $request): JsonResponse
    {
        $files = ExportFile::all();

        $deletedCount = 0;
        foreach ($files as $file) {
            // Удаляем физический файл
            if ($file->file_path && Storage::exists($file->file_path)) {
                Storage::delete($file->file_path);
            }
            // Удаляем запись
            $file->delete();
            $deletedCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Удалено {$deletedCount} файл(ов)"
        ]);
    }

    /**
     * Получить статистику файлов экспорта
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = ExportFile::selectRaw('
                COUNT(*) as total_files,
                SUM(file_size) as total_size,
                SUM(total_rows) as total_rows,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_files,
                COUNT(CASE WHEN status = "processing" THEN 1 END) as processing_files,
                COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_files
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
