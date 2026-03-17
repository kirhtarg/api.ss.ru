<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessModexJob;
use App\Models\ExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Фильтр по типу экспорта
        if ($request->has('type')) {
            $type = $request->get('type');
            if ($type === 'modex') {
                $query->where('export_config->type', 'modex');
            } elseif ($type === 'export') {
                $query->where(function ($q) {
                    $q->whereNull('export_config->type')
                        ->orWhere('export_config->type', '!=', 'modex');
                });
            }
        }

        // Большое количество файлов на странице по умолчанию
        $perPage = $request->get('per_page', 100);

        $files = $query->paginate($perPage);
        foreach ($files->items() as $model) {
            $arr = $model->toArray();
            $conf = $arr['export_config'] ?? [];
            if (! is_array($conf)) {
                $conf = [];
            }
            $progress = (int) ($conf['progress_rows'] ?? 0);
            $total = (int) ($conf['total_rows'] ?? ($arr['total_rows'] ?? 0));
            if (($arr['status'] ?? '') === 'processing' && $total > 0 && $progress >= $total) {
                $expectedPath = 'modex/'.$arr['filename'];
                if (! Storage::exists($expectedPath)) {
                    $temp = $conf['temp_output_path'] ?? null;
                    if ($temp && Storage::exists($temp)) {
                        try {
                            $contents = Storage::get($temp);
                            Storage::put($expectedPath, $contents);
                            Storage::delete($temp);
                        } catch (\Throwable $e) {
                        }
                    }
                }
                if (Storage::exists($expectedPath)) {
                    try {
                        $size = Storage::size($expectedPath);
                        $model->update([
                            'status' => 'completed',
                            'file_path' => $expectedPath,
                            'file_size' => $size,
                            'total_rows' => $total,
                            'error_message' => null,
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
        $normalized = array_map(function ($file) {
            $arr = $file->toArray();
            $conf = $arr['export_config'] ?? [];
            if (! is_array($conf)) {
                $conf = [];
            }
            $conf['progress_rows'] = (int) ($conf['progress_rows'] ?? 0);
            $conf['total_rows'] = (int) ($conf['total_rows'] ?? ($arr['total_rows'] ?? 0));
            if (($arr['status'] ?? '') === 'processing' && $conf['total_rows'] > 0 && $conf['progress_rows'] === 0) {
                $conf['progress_rows'] = 1;
            }
            $arr['export_config'] = $conf;
            $arr['progress_rows'] = $conf['progress_rows'];
            $arr['rows'] = $conf['total_rows'];

            return $arr;
        }, $files->items());

        return response()->json([
            'success' => true,
            'data' => $normalized,
            'pagination' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ]);
    }

    /**
     * Получить информацию о файле экспорта
     */
    public function show($id): JsonResponse
    {
        $file = ExportFile::with('creator')->findOrFail($id);
        $arr = $file->toArray();
        $conf = $arr['export_config'] ?? [];
        if (! is_array($conf)) {
            $conf = [];
        }
        $conf['progress_rows'] = (int) ($conf['progress_rows'] ?? 0);
        $conf['total_rows'] = (int) ($conf['total_rows'] ?? ($arr['total_rows'] ?? 0));
        if (($arr['status'] ?? '') === 'processing' && $conf['total_rows'] > 0 && $conf['progress_rows'] === 0) {
            $conf['progress_rows'] = 1;
        }
        $arr['export_config'] = $conf;
        $arr['progress_rows'] = $conf['progress_rows'];
        $arr['rows'] = $conf['total_rows'];
        if (($arr['status'] ?? '') === 'processing') {
            $path = $arr['file_path'] ?? null;
            $expectedPath = $path ?: ('modex/'.($arr['filename'] ?? ''));
            $exists = $expectedPath ? Storage::exists($expectedPath) : false;
            $progress = $conf['progress_rows'] ?? 0;
            $total = $conf['total_rows'] ?? ($arr['total_rows'] ?? 0);
            if (! $exists && $total > 0 && $progress >= $total) {
                $temp = $conf['temp_output_path'] ?? null;
                if ($temp && Storage::exists($temp)) {
                    try {
                        $contents = Storage::get($temp);
                        Storage::put($expectedPath, $contents);
                        Storage::delete($temp);
                        $exists = true;
                    } catch (\Throwable $e) {
                    }
                }
            }
            if ($exists && $total > 0 && $progress >= $total) {
                $size = Storage::size($expectedPath);
                $file->update([
                    'status' => 'completed',
                    'file_path' => $expectedPath,
                    'file_size' => $size,
                    'total_rows' => $total,
                    'error_message' => null,
                ]);
                $arr = $file->fresh()->toArray();
                $c2 = $arr['export_config'] ?? [];
                if (! is_array($c2)) {
                    $c2 = [];
                }
                $c2['progress_rows'] = (int) ($c2['progress_rows'] ?? $total);
                $c2['total_rows'] = (int) ($c2['total_rows'] ?? $total);
                $arr['export_config'] = $c2;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $arr,
        ]);
    }

    /**
     * Создать новый файл экспорта (запустить процесс)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'format' => ['required', Rule::in(['excel', 'csv', 'txt', 'avito_xml'])],
            'filename' => 'nullable|string|max:100',
            'export_config' => 'nullable|array',
        ]);

        // Определяем расширение файла
        $format = $request->format;
        $extension = match ($format) {
            'excel' => 'xlsx',  // Настоящий Excel файл
            'csv' => 'csv',
            'txt' => 'txt',
            'avito_xml' => 'xml',
            default => 'xlsx'
        };

        // Определяем название файла
        $customFilename = trim($request->filename ?? '');
        if ($customFilename) {
            // Если указано пользовательское название, используем его
            $originalFilename = $customFilename;
            // Добавляем расширение, если его нет
            if (! preg_match('/\.(xlsx?|csv|txt|xml)$/i', $originalFilename)) {
                $originalFilename .= '.'.$extension;
            } else {
                // Если расширение уже есть, заменяем его на правильное
                $originalFilename = preg_replace('/\.(xlsx?|csv|txt|xml)$/i', '.'.$extension, $originalFilename);
            }
            // Генерируем системное имя файла
            $filename = 'export_'.time().'_'.uniqid().'.'.$extension;
        } else {
            // Автоматическое название
            $originalFilename = 'Экспорт товаров '.now()->format('d.m.Y H:i').'.'.$extension;
            $filename = 'export_'.time().'_'.uniqid().'.'.$extension;
        }

        $exportConfig = $request->export_config ?? [];

        // Обработка загруженного шаблона
        if (isset($exportConfig['excel_template_data'])) {
            try {
                $templateData = base64_decode($exportConfig['excel_template_data']);
                if ($templateData) {
                    $templateHash = md5($templateData);
                    $templatePath = 'export_templates/'.$templateHash.'.xlsx';

                    if (! Storage::exists($templatePath)) {
                        Storage::put($templatePath, $templateData);
                    }

                    $exportConfig['template_path'] = $templatePath;
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error saving export template: '.$e->getMessage());
            }
            // Удаляем данные шаблона из конфига, чтобы не сохранять в БД огромную строку
            unset($exportConfig['excel_template_data']);
        }

        $file = ExportFile::create([
            'created_by' => Auth::id(),
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'format' => $format, // Сохраняем оригинальный format (excel, csv, txt, avito_xml)
            'status' => 'pending',
            'export_config' => $exportConfig,
        ]);

        // Запускаем задачу экспорта (асинхронно через очередь)
        \App\Jobs\ProcessExportJob::dispatch($file);

        return response()->json([
            'success' => true,
            'data' => $file->load('creator'),
            'message' => 'Процесс экспорта запущен',
        ]);
    }

    /**
     * Тестовый метод для завершения экспорта (для тестирования)
     */
    public function completeTest(ExportFile $exportFile): JsonResponse
    {
        // Создаем тестовый файл
        $testContent = "id,name,sku,price\n1,Тестовый товар 1,TEST001,100\n2,Тестовый товар 2,TEST002,200\n";
        $filePath = 'exports/'.$exportFile->filename;

        Storage::put($filePath, $testContent);

        $exportFile->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_size' => strlen($testContent),
            'total_rows' => 2,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Экспорт завершен (тест)',
        ]);
    }

    /**
     * Скачать файл экспорта
     */
    public function download(Request $request, ExportFile $exportFile): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Проверяем аутентификацию через token в query параметре
        $token = $request->query('token');

        if ($token) {
            // Токен может иметь формат "id|token", извлекаем token часть
            $tokenParts = explode('|', $token);
            $tokenValue = end($tokenParts);

            // Ищем токен в базе
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($tokenValue);

            if (! $accessToken) {
                // Если не нашли, пробуем с полным токеном
                $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            }

            if (! $accessToken || ! $accessToken->tokenable) {
                abort(401, 'Неверный токен');
            }

            $user = $accessToken->tokenable;

            // Проверяем, что пользователь имеет доступ к файлу
            if ($user->id !== $exportFile->created_by && ! $user->hasAnyRole(['admin', 'manager'])) {
                abort(403, 'Доступ запрещен');
            }
        } else {
            // Стандартная аутентификация
            if (! Auth::check()) {
                abort(401, 'Не авторизован');
            }

            $user = Auth::user();

            // Проверяем доступ
            if ($user->id !== $exportFile->created_by && ! $user->hasAnyRole(['admin', 'manager'])) {
                abort(403, 'Доступ запрещен');
            }
        }
        // TODO: Восстановить после исправления проблемы с токенами

        // Проверяем, что файл готов к скачиванию
        if (! $exportFile->isDownloadable()) {
            \Log::error('File not downloadable', [
                'file_id' => $exportFile->id,
                'status' => $exportFile->status,
                'file_path' => $exportFile->file_path,
                'full_path' => $exportFile->getFullPath(),
                'file_exists' => file_exists($exportFile->getFullPath()),
            ]);
            abort(404, 'Файл не найден или еще не готов');
        }

        $filename = $exportFile->original_filename;

        \Log::info('Starting file download', [
            'file_id' => $exportFile->id,
            'file_path' => $exportFile->file_path,
            'filename' => $filename,
            'exists' => Storage::exists($exportFile->file_path),
        ]);

        if (! Storage::exists($exportFile->file_path)) {
            \Log::error('File does not exist for download', ['path' => $exportFile->file_path]);
            abort(404, 'Файл не найден');
        }

        return Storage::download($exportFile->file_path, $filename);
    }

    /**
     * Удалить файл экспорта
     */
    public function destroy(ExportFile $exportFile): JsonResponse
    {
        // Удаляем задания очереди, связанные с этим файлом
        $this->cancelQueueJobsForFile($exportFile);

        // Удаляем физический файл, если он существует
        if ($exportFile->file_path && Storage::exists($exportFile->file_path)) {
            Storage::delete($exportFile->file_path);
        }

        // Удаляем запись из базы данных
        $exportFile->delete();

        return response()->json([
            'success' => true,
            'message' => 'Файл удален',
        ]);
    }

    /**
     * Очистить все файлы экспорта
     */
    public function clearAll(Request $request): JsonResponse
    {
        // Учитываем тип, если передан (например, type=modex)
        $type = $request->get('type');
        $query = ExportFile::query();
        if ($type === 'modex') {
            $query->where('export_config->type', 'modex');
        } elseif ($type === 'export') {
            $query->where(function ($q) {
                $q->whereNull('export_config->type')
                    ->orWhere('export_config->type', '!=', 'modex');
            });
        }
        $files = $query->get();

        $deletedCount = 0;
        foreach ($files as $file) {
            // Удаляем задания очереди, связанные с этим файлом
            $this->cancelQueueJobsForFile($file);
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
            'message' => "Удалено {$deletedCount} файл(ов)",
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
            'data' => $stats,
        ]);
    }

    /**
     * Удалить задания из очереди, связанные с конкретным ExportFile
     */
    private function cancelQueueJobsForFile(ExportFile $exportFile): void
    {
        try {
            // Удаляем задания для ProcessModexJob
            DB::table('jobs')
                ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
                ->where(function ($q) use ($exportFile) {
                    $patterns = [
                        '%"id";i:'.$exportFile->id.'%',
                        '%"id";s:%"'.$exportFile->id.'"%',
                        '%"id":'.$exportFile->id.'%',
                        '%;i:'.$exportFile->id.'%',
                    ];
                    foreach ($patterns as $p) {
                        $q->orWhere('payload', 'like', $p);
                    }
                })
                ->delete();

            // Удаляем задания для ProcessExportJob (на случай общего экспорта)
            DB::table('jobs')
                ->where('payload', 'like', '%App\\\\Jobs\\\\ProcessExportJob%')
                ->where(function ($q) use ($exportFile) {
                    $patterns = [
                        '%"id";i:'.$exportFile->id.'%',
                        '%"id";s:%"'.$exportFile->id.'"%',
                        '%"id":'.$exportFile->id.'%',
                        '%;i:'.$exportFile->id.'%',
                    ];
                    foreach ($patterns as $p) {
                        $q->orWhere('payload', 'like', $p);
                    }
                })
                ->delete();

            // Также очищаем failed_jobs
            DB::table('failed_jobs')
                ->where(function ($q) {
                    $q->where('payload', 'like', '%App\\\\Jobs\\\\ProcessModexJob%')
                        ->orWhere('payload', 'like', '%App\\\\Jobs\\\\ProcessExportJob%');
                })
                ->where(function ($q) use ($exportFile) {
                    $patterns = [
                        '%"id";i:'.$exportFile->id.'%',
                        '%"id";s:%"'.$exportFile->id.'"%',
                        '%"id":'.$exportFile->id.'%',
                        '%;i:'.$exportFile->id.'%',
                    ];
                    foreach ($patterns as $p) {
                        $q->orWhere('payload', 'like', $p);
                    }
                })
                ->delete();
        } catch (\Throwable $e) {
            // Игнорируем ошибки удаления из очереди
        }
    }
}
