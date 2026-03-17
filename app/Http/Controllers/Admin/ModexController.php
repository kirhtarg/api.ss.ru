<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ModexController extends Controller
{
    /**
     * Анализировать файл перед обработкой
     */
    public function analyze(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:51200',
            ]);

            $file = $request->file('file');
            $path = $file->getRealPath();

            // Увеличиваем лимит памяти для анализа
            ini_set('memory_limit', '512M');

            try {
                // Используем IOFactory для автоматического определения типа
                $spreadsheet = IOFactory::load($path);
                $worksheet = $spreadsheet->getActiveSheet();

                $rows = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $columns = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'rows' => $rows,
                        'columns' => $columns,
                        'size' => $file->getSize(),
                        'filename' => $file->getClientOriginalName(),
                    ],
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка чтения Excel файла: '.$e->getMessage(),
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка анализа файла: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обработать файл модекса
     */
    public function process(Request $request): JsonResponse
    {
        return $this->handleProcess($request);
    }

    /**
     * Основная логика обработки POST запроса
     */
    private function handleProcess(Request $request): JsonResponse
    {
        try {
            // Увеличиваем лимит памяти для обработки больших файлов
            ini_set('memory_limit', '1024M');

            // Декодируем rules из JSON строки в массив
            $rulesData = $request->rules;
            if (is_string($rulesData)) {
                $rulesData = json_decode($rulesData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw ValidationException::withMessages([
                        'rules' => 'Неверный формат правил: '.json_last_error_msg(),
                    ]);
                }
            }

            // Создаем массив данных для валидации
            $validationData = [
                'file' => $request->file('file'),
                'rules' => $rulesData,
                'output_filename' => $request->output_filename,
            ];

            $validator = \Illuminate\Support\Facades\Validator::make($validationData, [
                'file' => 'required|file|mimes:xlsx,xls|max:51200', // 50MB max
                'rules' => 'required|array|min:1',
                'output_filename' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // Дополнительная валидация правил
            foreach ($rulesData as $index => $rule) {
                if (! isset($rule['ruleKey']) || ! is_string($rule['ruleKey'])) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.ruleKey" => 'ruleKey обязателен и должен быть строкой',
                    ]);
                }
                if (! isset($rule['sourceColumn']) || ! is_string($rule['sourceColumn']) || trim($rule['sourceColumn']) === '') {
                    throw ValidationException::withMessages([
                        "rules.{$index}.sourceColumn" => 'sourceColumn обязателен и не может быть пустым',
                    ]);
                }
            }
        } catch (ValidationException $e) {
            // Для отладки вернем детальную информацию
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
                'debug' => [
                    'has_file' => $request->hasFile('file'),
                    'file_keys' => array_keys($request->allFiles()),
                    'all_keys' => array_keys($request->all()),
                    'rules' => $rulesData ?? $request->rules,
                    'rules_type' => gettype($rulesData ?? $request->rules),
                    'rules_raw' => $request->get('rules'),
                ],
            ], 422);
        }

        try {
            // Сохраняем входной файл временно
            $inputFile = $request->file('file');
            $tempFilename = 'temp_modex_'.time().'_'.uniqid().'.'.$inputFile->getClientOriginalExtension();
            $tempPath = $inputFile->storeAs('temp', $tempFilename);

            // Определяем имя выходного файла
            $customFilename = trim($request->output_filename ?? '');
            if ($customFilename) {
                $originalFilename = $customFilename;
                if (! preg_match('/\.(xlsx?|csv|txt)$/i', $originalFilename)) {
                    $originalFilename .= '.xlsx';
                }
                $filename = 'modex_'.time().'_'.uniqid().'.xlsx';
            } else {
                $baseName = pathinfo($inputFile->getClientOriginalName(), PATHINFO_FILENAME);
                $originalFilename = $baseName.'_modified.xlsx';
                $filename = 'modex_'.time().'_'.uniqid().'.xlsx';
            }

            // Создаем запись в БД
            $removeTagsGlobal = (bool) ($request->get('removeTags') ?? $request->get('remove_tags') ?? false);
            $modexFile = ExportFile::create([
                'created_by' => Auth::id(),
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'format' => 'excel',
                'status' => 'pending',
                'export_config' => [
                    'type' => 'modex',
                    'input_file_path' => $tempPath,
                    'rules' => $rulesData,
                    'removeTags' => $removeTagsGlobal,
                ],
            ]);

            // Логируем создание файла
            Log::info('Modex file created', [
                'file_id' => $modexFile->id,
                'filename' => $filename,
                'input_path' => $tempPath,
                'file_exists' => Storage::exists($tempPath),
            ]);

            // Быстро и безопасно определяем количество строк для отображения прогресса на фронте
            try {
                $reader = IOFactory::createReaderForFile(storage_path('app/'.$tempPath));
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load(storage_path('app/'.$tempPath));
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = max($worksheet->getHighestRow() - 1, 0);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                $conf = $modexFile->export_config ?? [];
                $conf['total_rows'] = $rows;
                $conf['progress_rows'] = 0;
                $modexFile->update(['export_config' => $conf]);
            } catch (\Throwable $e) {
                // ignore
            }

            // Запускаем задачу обработки в фоне (асинхронно)
            $modexFile->update(['status' => 'processing']);
            \App\Jobs\ProcessModexJob::dispatch($modexFile);

            Log::info('ProcessModexJob dispatched', ['file_id' => $modexFile->id]);

            return response()->json([
                'success' => true,
                'data' => $modexFile->load('creator'),
                'message' => 'Процесс обработки файла запущен! Результаты будут доступны во вкладке "Файлы модекса"',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обработки файла: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Скачать результат модекса
     */
    public function download(Request $request, $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $exportFile = ExportFile::findOrFail($id);

        // Log download attempt
        \Illuminate\Support\Facades\Log::info('ModexController::download HIT', [
            'file_id' => $exportFile->id,
            'user' => Auth::user() ? Auth::id() : 'guest',
            'token_query' => $request->query('token'),
        ]);

        // Проверяем, что это файл модекса и он готов
        if (($exportFile->export_config['type'] ?? null) !== 'modex' || $exportFile->status !== 'completed') {
            abort(404, 'Файл не найден или не готов');
        }

        $user = Auth::user();

        if (! $user) {
            abort(401, 'Пользователь не авторизован');
        }

        // Проверяем, что пользователь имеет доступ к файлу
        if ($user->id !== $exportFile->created_by && ! $user->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'Доступ запрещен');
        }

        // Проверяем, что файл готов к скачиванию
        if (! $exportFile->isDownloadable()) {
            Log::error('File not downloadable', [
                'file_id' => $exportFile->id,
                'status' => $exportFile->status,
                'file_path' => $exportFile->file_path,
                'full_path' => $exportFile->getFullPath(),
                'exists' => file_exists($exportFile->getFullPath()),
            ]);
            abort(404, 'Файл не готов к скачиванию');
        }

        if (! Storage::exists($exportFile->file_path)) {
            abort(404, 'Файл не найден');
        }

        return Storage::download($exportFile->file_path, $exportFile->original_filename);
    }
}
