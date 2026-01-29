<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModexController extends Controller
{
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
        // Временный dd для отладки
        // Декодируем rules из JSON строки в массив

        try {
            // Увеличиваем лимит памяти для обработки больших файлов
            ini_set('memory_limit', '1024M');

            // Декодируем rules из JSON строки в массив
            $rulesData = $request->rules;
            if (is_string($rulesData)) {
                $rulesData = json_decode($rulesData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw ValidationException::withMessages([
                        'rules' => 'Неверный формат правил: ' . json_last_error_msg()
                    ]);
                }
            }

            // Создаем массив данных для валидации
            $validationData = [
                'file' => $request->file('file'),
                'rules' => $rulesData,
                'output_filename' => $request->output_filename
            ];

            $validator = \Illuminate\Support\Facades\Validator::make($validationData, [
                'file' => 'required|file|mimes:xlsx,xls|max:51200', // 50MB max
                'rules' => 'required|array|min:1',
                'output_filename' => 'nullable|string|max:100'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // Дополнительная валидация правил
            foreach ($rulesData as $index => $rule) {
                if (!isset($rule['ruleKey']) || !is_string($rule['ruleKey'])) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.ruleKey" => 'ruleKey обязателен и должен быть строкой'
                    ]);
                }
                if (!isset($rule['sourceColumn']) || !is_string($rule['sourceColumn']) || trim($rule['sourceColumn']) === '') {
                    throw ValidationException::withMessages([
                        "rules.{$index}.sourceColumn" => 'sourceColumn обязателен и не может быть пустым'
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
                    'rules_raw' => $request->get('rules')
                ]
            ], 422);
        }

        try {
            // Сохраняем входной файл временно
            $inputFile = $request->file('file');
            $tempFilename = 'temp_modex_' . time() . '_' . uniqid() . '.' . $inputFile->getClientOriginalExtension();
            $tempPath = $inputFile->storeAs('temp', $tempFilename);

            // Определяем имя выходного файла
            $customFilename = trim($request->output_filename ?? '');
            if ($customFilename) {
                $originalFilename = $customFilename;
                if (!preg_match('/\.(xlsx?|csv|txt)$/i', $originalFilename)) {
                    $originalFilename .= '.xlsx';
                }
                $filename = 'modex_' . time() . '_' . uniqid() . '.xlsx';
            } else {
                $baseName = pathinfo($inputFile->getClientOriginalName(), PATHINFO_FILENAME);
                $originalFilename = $baseName . '_modified.xlsx';
                $filename = 'modex_' . time() . '_' . uniqid() . '.xlsx';
            }

            // Создаем запись в БД
            $modexFile = ExportFile::create([
                'created_by' => Auth::id(),
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'format' => 'excel',
                'status' => 'pending',
                'export_config' => [
                    'type' => 'modex',
                    'input_file_path' => $tempPath,
                    'rules' => $rulesData
                ]
            ]);

            // Логируем создание файла
            $logMessage = "[" . date('Y-m-d H:i:s') . "] Modex file created: ID={$modexFile->id}, filename={$filename}, input_path={$tempPath}, exists=" . (Storage::exists($tempPath) ? 'YES' : 'NO') . "\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage, FILE_APPEND);

            Log::info('Modex file created', [
                'file_id' => $modexFile->id,
                'filename' => $filename,
                'input_path' => $tempPath,
                'file_exists' => Storage::exists($tempPath)
            ]);

            // Запускаем задачу обработки в фоне (асинхронно)
            \App\Jobs\ProcessModexJob::dispatch($modexFile);

            $logMessage2 = "[" . date('Y-m-d H:i:s') . "] ProcessModexJob dispatched for file ID={$modexFile->id}\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage2, FILE_APPEND);

            Log::info('ProcessModexJob dispatched', ['file_id' => $modexFile->id]);

            return response()->json([
                'success' => true,
                'data' => $modexFile->load('creator'),
                'message' => 'Процесс обработки файла запущен! Результаты будут доступны во вкладке "Файлы модекса"'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обработки файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать результат модекса
     */
    public function download(Request $request, ExportFile $exportFile): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Проверяем, что это файл модекса и он готов
        if (($exportFile->export_config['type'] ?? null) !== 'modex' || $exportFile->status !== 'completed') {
            abort(404, 'Файл не найден или не готов');
        }

        $user = Auth::user();

        // Проверяем, что пользователь имеет доступ к файлу
        if ($user->id !== $exportFile->created_by && !$user->hasRole(['admin', 'manager'])) {
            abort(403, 'Доступ запрещен');
        }

        // Проверяем, что файл готов к скачиванию
        if (!$exportFile->isDownloadable()) {
            Log::error('File not downloadable', [
                'file_id' => $exportFile->id,
                'status' => $exportFile->status,
                'file_path' => $exportFile->file_path,
                'full_path' => $exportFile->getFullPath(),
                'exists' => file_exists($exportFile->getFullPath())
            ]);
            abort(404, 'Файл не готов к скачиванию');
        }

        $filePath = $exportFile->getFullPath();

        if (!file_exists($filePath)) {
            abort(404, 'Файл не найден');
        }

        return response()->download($filePath, $exportFile->original_filename);
    }
}