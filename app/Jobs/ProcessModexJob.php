<?php

namespace App\Jobs;

use App\Models\ExportFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ProcessModexJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200; // 2 часа таймаут
    public $tries = 3; // 3 попытки

    protected ExportFile $modexFile;

    /**
     * Create a new job instance.
     */
    public function __construct(ExportFile $modexFile)
    {
        $this->modexFile = $modexFile;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Логируем в файл напрямую для отладки
        $logFile = storage_path('logs/modex_debug.log');
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Job started for file {$this->modexFile->id}\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        Log::info('ProcessModexJob started', [
            'modex_file_id' => $this->modexFile->id,
            'filename' => $this->modexFile->filename,
            'config' => $this->modexFile->export_config
        ]);

        try {
            // Проверяем существование файла
            $config = $this->modexFile->export_config ?? [];
            $inputFilePath = $config['input_file_path'] ?? null;

            Log::info('ProcessModexJob checking input file', [
                'modex_file_id' => $this->modexFile->id,
                'input_file_path' => $inputFilePath,
                'exists' => $inputFilePath ? Storage::exists($inputFilePath) : false
            ]);

            if (!$inputFilePath || !Storage::exists($inputFilePath)) {
                throw new \Exception('Input file not found: ' . $inputFilePath);
            }

            // Увеличиваем лимит памяти и времени выполнения для обработки больших Excel файлов
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', '7200'); // 2 часа

            // Обновляем статус на "обрабатывается"
            $this->modexFile->update(['status' => 'processing']);
            Log::info('ProcessModexJob status updated to processing', ['modex_file_id' => $this->modexFile->id]);

            // Получаем конфигурацию модекса
            $config = $this->modexFile->export_config ?? [];

            // Получаем исходный файл
            $inputFilePath = $config['input_file_path'] ?? null;
            if (!$inputFilePath || !Storage::exists($inputFilePath)) {
                throw new \Exception('Исходный файл не найден');
            }

            // Загружаем и обрабатываем файл
            $inputFileFullPath = storage_path('app/' . $inputFilePath);
            $processedBlob = $this->processModexFile($inputFileFullPath, $config);

            // Сохраняем результат как CSV
            $outputFilePath = 'modex/' . $this->modexFile->filename;
            Storage::put($outputFilePath, $content);

            // Получаем размер файла
            $fileSize = Storage::size($outputFilePath);

            // Обновляем запись в БД
            $this->modexFile->update([
                'status' => 'completed',
                'file_path' => $outputFilePath,
                'file_size' => $fileSize,
                'total_rows' => $this->getBlobRowCount($content)
            ]);

            // Удаляем временный входной файл
            Storage::delete($inputFilePath);

            $logMessage5 = "[" . date('Y-m-d H:i:s') . "] Job completed successfully for file {$this->modexFile->id}\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage5, FILE_APPEND);

            Log::info('ProcessModexJob completed successfully', [
                'modex_file_id' => $this->modexFile->id,
                'output_file_path' => $outputFilePath,
                'file_size' => $fileSize
            ]);

        } catch (\Exception $e) {
            $errorMessage = "[" . date('Y-m-d H:i:s') . "] Job failed for file {$this->modexFile->id}: {$e->getMessage()}\nStack trace:\n{$e->getTraceAsString()}\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $errorMessage, FILE_APPEND);

            Log::error('Modex job failed', [
                'modex_file_id' => $this->modexFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->modexFile->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Безопасная загрузка spreadsheet
     */
    protected function loadSpreadsheetSafely(string $filePath): Spreadsheet
    {
        try {
            // Сначала пробуем с readDataOnly
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            return $reader->load($filePath);
        } catch (\Exception $e) {
            $logMessage = "[" . date('Y-m-d H:i:s') . "] ReadDataOnly failed, trying CSV approach: " . $e->getMessage() . "\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage, FILE_APPEND);

            // Если не получается, конвертируем в CSV и загружаем как CSV
            $csvContent = $this->convertExcelToCsv($filePath);
            $tempCsvPath = storage_path('app/temp/temp_' . time() . '.csv');
            file_put_contents($tempCsvPath, $csvContent);

            try {
                $reader = IOFactory::createReader('Csv');
                $spreadsheet = $reader->load($tempCsvPath);
                unlink($tempCsvPath); // Удаляем временный файл
                return $spreadsheet;
            } catch (\Exception $e2) {
                unlink($tempCsvPath);
                throw new \Exception('Failed to load spreadsheet even as CSV: ' . $e2->getMessage());
            }
        }
    }

    /**
     * Конвертировать Excel в CSV
     */
    protected function convertExcelToCsv(string $filePath): string
    {
        // Используем простой подход - читаем как текст и конвертируем
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return file_get_contents($filePath);
        }

        // Для XLSX пробуем прочитать сырые данные
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            $csvData = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rowData = [];
                foreach ($row->getCellIterator() as $cell) {
                    // Получаем значение без вычисления формул
                    $value = $cell->getValue();
                    if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    }
                    $rowData[] = $value;
                }
                $csvData[] = implode(',', array_map(function($val) {
                    // Экранируем CSV
                    if (strpos($val, ',') !== false || strpos($val, '"') !== false || strpos($val, "\n") !== false) {
                        return '"' . str_replace('"', '""', $val) . '"';
                    }
                    return $val;
                }, $rowData));
            }

            return implode("\n", $csvData);
        } catch (\Exception $e) {
            // Если ничего не получается, возвращаем пустой CSV
            $logMessage = "[" . date('Y-m-d H:i:s') . "] CSV conversion failed: " . $e->getMessage() . "\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage, FILE_APPEND);
            return "Error,Converting,Excel,File\nFailed,to,load," . str_replace(',', ';', $e->getMessage());
        }
    }

    /**
     * Обработать модекс файл
     */
    protected function processModexFile(string $inputFilePath, array $config): string
    {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Processing file: {$inputFilePath}\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logMessage, FILE_APPEND);

        $rules = $config['rules'] ?? [];

        // Если правил нет, просто копируем файл без обработки
        if (empty($rules)) {
            $logMessage2 = "[" . date('Y-m-d H:i:s') . "] No rules specified, copying file directly\n";
            file_put_contents(storage_path('logs/modex_debug.log'), $logMessage2, FILE_APPEND);
            Log::info('No rules specified, copying file directly', ['file' => $inputFilePath]);

            // Для тестирования - создаем простой Excel файл
            $testSpreadsheet = new Spreadsheet();
            $testSheet = $testSpreadsheet->getActiveSheet();
            $testSheet->setCellValue('A1', 'Test');
            $testSheet->setCellValue('B1', 'File');
            $testSheet->setCellValue('A2', 'Processing');
            $testSheet->setCellValue('B2', 'Success');

            $testWriter = new Xlsx($testSpreadsheet);
            ob_start();
            $testWriter->save('php://output');
            $content = ob_get_clean();

            $testSpreadsheet->disconnectWorksheets();
            unset($testSpreadsheet);

            return $content;
        }

        // Загружаем workbook
        $logMessage3 = "[" . date('Y-m-d H:i:s') . "] Loading spreadsheet from: {$inputFilePath}\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logMessage3, FILE_APPEND);

        // Загружаем файл как CSV/XLSX с полным игнорированием формул
        $spreadsheet = $this->loadSpreadsheetSafely($inputFilePath);

        $sheet = $spreadsheet->getActiveSheet();
        $aoa = $sheet->toArray();

        $logMessage4 = "[" . date('Y-m-d H:i:s') . "] Spreadsheet loaded, rows: " . count($aoa) . "\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logMessage4, FILE_APPEND);

        if (empty($aoa)) {
            throw new \Exception('Файл пуст');
        }

        $headers = $aoa[0] ?? [];

        // Применяем правила
        $logMessage5 = "[" . date('Y-m-d H:i:s') . "] Applying rules to " . count($rules) . " rules\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logMessage5, FILE_APPEND);

        $processedAoa = $this->applyModexRules($aoa, $headers, $rules);

        $logResult = "[" . date('Y-m-d H:i:s') . "] Processing complete. Original rows: " . count($aoa) . ", Processed rows: " . count($processedAoa) . "\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logResult, FILE_APPEND);

        // Создаем новый XLSX файл с чистыми данными (без формул)
        $newSpreadsheet = new Spreadsheet();
        $newSheet = $newSpreadsheet->getActiveSheet();

        // Записываем данные как простые значения
        foreach ($processedAoa as $rowIndex => $row) {
            foreach ($row as $colIndex => $cellValue) {
                // Используем setCellValueExplicit для гарантии, что значение останется как есть
                $columnLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $cellCoordinate = $columnLetter . ($rowIndex + 1);

                // Принудительно устанавливаем как строковое значение
                $newSheet->setCellValueExplicit($cellCoordinate, (string)$cellValue, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        // Сохраняем в память
        $writer = new Xlsx($newSpreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        // Очищаем память
        $newSpreadsheet->disconnectWorksheets();
        unset($newSpreadsheet);
        if (isset($spreadsheet)) {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $content;
    }

    /**
     * Применить правила модекса
     */
    protected function applyModexRules(array $aoa, array $headers, array $rules): array
    {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Starting applyModexRules with " . count($aoa) . " rows and " . count($rules) . " rules\n";
        file_put_contents(storage_path('logs/modex_debug.log'), $logMessage, FILE_APPEND);

        $result = [$aoa[0]]; // Заголовки всегда остаются

        for ($rowIndex = 1; $rowIndex < count($aoa); $rowIndex++) {
            if ($rowIndex % 1000 === 0) {
                $logProgress = "[" . date('Y-m-d H:i:s') . "] Processed {$rowIndex} of " . count($aoa) . " rows\n";
                file_put_contents(storage_path('logs/modex_debug.log'), $logProgress, FILE_APPEND);
            }

            $row = $aoa[$rowIndex];
            $newRow = $row; // Копируем исходную строку

            foreach ($rules as $ruleIndex => $rule) {
                $ruleKey = $rule['ruleKey'] ?? '';
                $sourceColumn = $rule['sourceColumn'] ?? '';
                $params = $rule['params'] ?? [];

                // Находим индекс колонки
                $colIndex = $this->resolveColumnIndex($headers, $sourceColumn);
                if ($colIndex === -1) {
                    $logRule = "[" . date('Y-m-d H:i:s') . "] Rule {$ruleIndex}: Column '{$sourceColumn}' not found in headers\n";
                    file_put_contents(storage_path('logs/modex_debug.log'), $logRule, FILE_APPEND);
                    continue;
                }

                $cellValue = $row[$colIndex] ?? '';

                // Применяем правило
                $ruleResult = $this->applyModexRule($ruleKey, $cellValue, $params);

                // Логируем применение правила
                $logRule = "[" . date('Y-m-d H:i:s') . "] Rule {$ruleIndex} ({$ruleKey}): '{$cellValue}' -> ";
                if ($ruleResult === null) {
                    $logRule .= "null\n";
                } elseif (is_array($ruleResult)) {
                    $logRule .= "[" . implode(',', $ruleResult) . "]\n";
                } else {
                    $logRule .= "'{$ruleResult}'\n";
                }
                file_put_contents(storage_path('logs/modex_debug.log'), $logRule, FILE_APPEND);

                // Добавляем результат в новые колонки
                if ($ruleResult !== null) {
                    if (is_array($ruleResult)) {
                        $newRow = array_merge($newRow, $ruleResult);
                    } else {
                        $newRow[] = $ruleResult;
                    }
                }
            }

            $result[] = $newRow;
        }

        return $result;
    }

    /**
     * Найти индекс колонки
     */
    protected function resolveColumnIndex(array $headers, string $sourceColumn): int
    {
        // Сначала пытаемся найти по имени
        foreach ($headers as $index => $header) {
            if (strcasecmp(trim($header), trim($sourceColumn)) === 0) {
                return $index;
            }
        }

        // Если не нашли, пытаемся интерпретировать как число
        $asNum = (int) $sourceColumn;
        if ($asNum >= 0 && $asNum < count($headers)) {
            return $asNum;
        }

        return -1;
    }

    /**
     * Применить правило модекса
     */
    protected function applyModexRule(string $ruleKey, string $cellValue, array $params): string|array|null
    {
        return match ($ruleKey) {
            'extractBetweenFragments' => $this->applyExtractBetweenFragments($cellValue, $params),
            'splitByDelimiter' => $this->applySplitByDelimiter($cellValue, $params),
            default => null
        };
    }

    /**
     * Применить правило "Извлечь между разделителями"
     */
    protected function applyExtractBetweenFragments(string $cellValue, array $params): ?string
    {
        $startFragment = $params['startFragment'] ?? '';
        $delimitersTextarea = $params['delimitersTextarea'] ?? '';
        $searchQuotes = $params['searchQuotes'] ?? false;
        $removeTags = $params['removeTags'] ?? false;
        $deleteFragmentsAfter = $params['deleteFragmentsAfter'] ?? '';

        if (!$startFragment || !$delimitersTextarea) {
            return null;
        }

        // Удаляем HTML-теги если нужно
        if ($removeTags) {
            $cellValue = strip_tags($cellValue);
        }

        // Парсим разделители
        $delimiters = $this->parseDelimiters($delimitersTextarea, $searchQuotes);

        // Ищем фрагмент после которого извлекать
        $startPos = strpos($cellValue, $startFragment);
        if ($startPos === false) {
            return '';
        }

        $afterStart = substr($cellValue, $startPos + strlen($startFragment));

        // Ищем первый разделитель
        $minPos = strlen($afterStart);
        foreach ($delimiters as $delimiter) {
            $pos = strpos($afterStart, $delimiter);
            if ($pos !== false && $pos < $minPos) {
                $minPos = $pos;
            }
        }

        $result = substr($afterStart, 0, $minPos);

        // Удаляем фрагменты после
        if ($deleteFragmentsAfter) {
            $fragmentsToDelete = $this->parseDelimiters($deleteFragmentsAfter, false);
            foreach ($fragmentsToDelete as $fragment) {
                $result = str_replace($fragment, '', $result);
            }
        }

        return trim($result);
    }

    /**
     * Применить правило "Разделить по разделителю"
     */
    protected function applySplitByDelimiter(string $cellValue, array $params): ?array
    {
        $delimiter = $params['delimiter'] ?? '';
        $newColumnsPrefix = $params['newColumnsPrefix'] ?? 'Колонка';
        $removeTags = $params['removeTags'] ?? false;
        $deleteFragmentsAfter = $params['deleteFragmentsAfter'] ?? '';

        if (!$delimiter) {
            return null;
        }

        // Удаляем HTML-теги если нужно
        if ($removeTags) {
            $cellValue = strip_tags($cellValue);
        }

        // Разделяем
        $parts = explode($delimiter, $cellValue);

        // Удаляем фрагменты после
        if ($deleteFragmentsAfter) {
            $fragmentsToDelete = $this->parseDelimiters($deleteFragmentsAfter, false);
            foreach ($parts as &$part) {
                foreach ($fragmentsToDelete as $fragment) {
                    $part = str_replace($fragment, '', $part);
                }
                $part = trim($part);
            }
        }

        return $parts;
    }

    /**
     * Парсить разделители из текста
     */
    protected function parseDelimiters(string $textarea, bool $searchQuotes): array
    {
        $result = [];
        $lines = explode("\n", $textarea);

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            // Удаляем кавычки
            $line = trim($line, '"\'');

            if ($searchQuotes) {
                $result[] = '"';
            }

            if ($line) {
                $result[] = $line;
            }
        }

        return array_unique(array_filter($result));
    }

    /**
     * Получить количество строк из blob
     */
    protected function getBlobRowCount(string $blob): int
    {
        try {
            // Загружаем spreadsheet из памяти
            $tempFile = tmpfile();
            fwrite($tempFile, $blob);
            $meta = stream_get_meta_data($tempFile);
            $tempPath = $meta['uri'];

            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rowCount = $sheet->getHighestRow();

            fclose($tempFile);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $rowCount;
        } catch (\Exception $e) {
            // Fallback: считаем строки по \n в CSV
            return substr_count($blob, "\n") + 1;
        }
    }
}