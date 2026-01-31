<?php

namespace App\Jobs;

use App\Models\ExportFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Settings;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;

class ProcessModexJob implements ShouldQueue
{
    use Queueable, SerializesModels;

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
        // Debug Log at very start
        Log::info("[" . date('Y-m-d H:i:s') . "] START ProcessModexJob handle() for file: " . $this->modexFile->id);

        try {
            // Проверяем существование файла
            $config = $this->modexFile->export_config ?? [];
            $inputFilePath = $config['input_file_path'] ?? null;

            if (!$inputFilePath || !Storage::exists($inputFilePath)) {
                throw new \Exception('Input file not found: ' . $inputFilePath);
            }

            // Увеличиваем лимит памяти и времени выполнения для обработки больших Excel файлов
            ini_set('memory_limit', '3072M');
            ini_set('max_execution_time', '7200'); // 2 часа

            // Обновляем статус на "обрабатывается"
            $this->modexFile->update(['status' => 'processing']);

            // Получаем конфигурацию модекса
            $config = $this->modexFile->export_config ?? [];

            // Получаем исходный файл
            $inputFilePath = $config['input_file_path'] ?? null;
            if (!$inputFilePath || !Storage::exists($inputFilePath)) {
                throw new \Exception('Исходный файл не найден');
            }

            // Загружаем и обрабатываем файл
            $inputFileFullPath = Storage::path($inputFilePath);
            $tempOutputPath = $this->processModexFile($inputFileFullPath, $config);

            // Clear stat cache to ensure file existence check is fresh
            clearstatcache();

            if (!$tempOutputPath || (!Storage::exists($tempOutputPath) && !file_exists(Storage::path($tempOutputPath)))) {
                Log::error('Temp output file not created', [
                    'path' => $tempOutputPath,
                    'full_path' => $tempOutputPath ? Storage::path($tempOutputPath) : null,
                    'storage_exists' => $tempOutputPath ? Storage::exists($tempOutputPath) : false,
                    'file_exists' => $tempOutputPath ? file_exists(Storage::path($tempOutputPath)) : false
                ]);
                throw new \Exception('Failed to create temp output file: ' . ($tempOutputPath ?? 'null'));
            }
            try {
                $conf = $this->modexFile->export_config ?? [];
                $conf['temp_output_path'] = $tempOutputPath;
                $this->modexFile->update(['export_config' => $conf]);
            } catch (\Throwable $e) {}

            // Сохраняем результат как XLSX
            $outputFilePath = 'modex/' . $this->modexFile->filename;
            if (Storage::exists($outputFilePath)) {
                Storage::delete($outputFilePath);
            }
            Storage::makeDirectory('modex');
            try {
                $src = Storage::path($tempOutputPath);
                $dst = Storage::path($outputFilePath);
                if (!is_dir(dirname($dst))) {
                    mkdir(dirname($dst), 0755, true);
                }
                if (!copy($src, $dst)) {
                    throw new \Exception('copy() failed');
                }
            } catch (\Throwable $e) {
                throw new \Exception('Failed to save output file: ' . $e->getMessage());
            }
            Storage::delete($tempOutputPath);

            // Получаем размер файла
            clearstatcache();
            $fileSize = Storage::size($outputFilePath);
            Log::info('File processed. Size: ' . $fileSize . ' bytes');
            
            // Get final row count from config (updated in processModexFile)
            $this->modexFile->refresh();
            $conf = $this->modexFile->export_config ?? [];
            $dataRows = (int)($conf['progress_rows'] ?? 0);
            
            // If we have headers, actual data rows is count - 1
            if ($dataRows > 0) {
                 $dataRows--; 
            }

            $conf['total_rows'] = $dataRows;
            $this->modexFile->update(['export_config' => $conf]);

            // Обновляем запись в БД
            $this->modexFile->update([
                'status' => 'completed',
                'file_path' => $outputFilePath,
                'file_size' => $fileSize,
                'total_rows' => $dataRows,
                'error_message' => null
            ]);

            // Удаляем временный входной файл
            Storage::delete($inputFilePath);

        } catch (\Throwable $e) {
            $this->modexFile->update([
                'status' => 'failed',
                'error_message' => 'Job Failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            ]);

            Log::error('ProcessModexJob Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }


    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            $this->modexFile->update([
                'status' => 'failed',
                'error_message' => 'Job Failed (Max Attempts): ' . $exception->getMessage()
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update status in failed(): ' . $e->getMessage());
        }

        Log::error('ProcessModexJob Final Failure', [
            'file_id' => $this->modexFile->id,
            'error' => $exception->getMessage()
        ]);
    }

    /**
     * Обработать модекс файл (Optimized with OpenSpout)
     */
    protected function processModexFile(string $inputFilePath, array $config): string
    {
        $rules = $config['rules'] ?? [];

        if (empty($rules)) {
            $tempOut = 'temp/modex_out_' . time() . '_' . uniqid() . '.xlsx';
            // Since inputFilePath is absolute, we can just read it and write to storage
            Storage::put($tempOut, file_get_contents($inputFilePath));
            return $tempOut;
        }

        // --- PHASE 1: Analyze & Write to Temp CSV ---
        if (!Storage::exists('temp')) {
            Storage::makeDirectory('temp');
        }
        $tempCsvPath = Storage::path('temp/modex_temp_' . uniqid() . '.csv');
        $csvHandle = fopen($tempCsvPath, 'w');
        fwrite($csvHandle, "\xEF\xBB\xBF"); // BOM

        // Update Phase to collecting
        try {
            $conf = $this->modexFile->export_config ?? [];
            $conf['phase'] = 'collecting';
            $this->modexFile->update(['export_config' => $conf]);
        } catch (\Throwable $e) {}
        
        $ruleMaxCols = [];
        $headers = [];
        $progressRows = 0;
        
        // Open Reader for Pass 1
        $reader = $this->getReaderForFile($inputFilePath);
        
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getIndex() > 0) break; // Only first sheet

            foreach ($sheet->getRowIterator() as $row) {
                if ($progressRows % 100 === 0) echo ">>> P1 Row $progressRows\n";
                try {
                    // Extract row data
                    $rowData = [];
                    foreach ($row->cells as $cell) {
                        $val = $cell->getValue();
                        if ($val instanceof \DateTimeInterface) {
                            $val = $val->format('Y-m-d H:i:s');
                        }
                        $rowData[] = (string)$val;
                    }
                    
                    // Headers (Row 1)
                    if ($progressRows === 0) {
                        $headers = $rowData;
                        $progressRows++;
                        
                        $this->debugLog('Headers found: ' . implode(',', $headers));

                        // Skip DB update for headers to avoid "1" progress confusion
                        continue;
                    }

                    // Analyze Rules
                    foreach ($rules as $ruleIndex => $rule) {
                        $ruleKey = $rule['ruleKey'] ?? '';
                        $sourceColumn = $rule['sourceColumn'] ?? '';
                        $params = $rule['params'] ?? [];

                        $colIndex = $this->resolveColumnIndex($headers, $sourceColumn);
                        $cellValue = ($colIndex !== -1) ? ($rowData[$colIndex] ?? '') : '';

                        $ruleResult = $this->applyModexRule($ruleKey, $cellValue, $params);
                        
                        if ($progressRows < 5) {
                             $this->debugLog("Row $progressRows Rule $ruleIndex: key=$ruleKey col=$sourceColumn resolved=$colIndex val=" . substr($cellValue, 0, 50));
                        }

                        $count = 0;
                        if ($ruleResult !== null) {
                            $count = is_array($ruleResult) ? count($ruleResult) : 1;
                        }
                        
                        if (!isset($ruleMaxCols[$ruleIndex])) {
                            $ruleMaxCols[$ruleIndex] = 0;
                        }
                        if ($count > $ruleMaxCols[$ruleIndex]) {
                            $ruleMaxCols[$ruleIndex] = $count;
                        }
                    }
                    
                    $progressRows++;
                    // UPDATE FREQUENTLY AT START, THEN EVERY 10 ROWS
                    if ($progressRows <= 100 || $progressRows % 10 === 0) {
                         try {
                            $currentConfig = $this->modexFile->export_config ?? [];
                            $currentConfig['progress_rows'] = $progressRows;
                            $this->modexFile->update(['export_config' => $currentConfig]);
                        } catch (\Throwable $e) {}
                    }
                } catch (\Throwable $e) {
                    $this->debugLog("Error in Phase 1 Row $progressRows: " . $e->getMessage());
                    throw $e;
                }
            }
        }
        $reader->close();
        
        $this->debugLog('Phase 1 completed. Rows: ' . $progressRows);

        // Calculate New Headers
        $newHeaders = $headers;
        foreach ($rules as $ruleIndex => $rule) {
            $params = $rule['params'] ?? [];
            $maxCols = $ruleMaxCols[$ruleIndex] ?? 0;
            if ($maxCols === 0) continue;

            $ruleKey = $rule['ruleKey'] ?? '';
            if ($ruleKey === 'extractBetweenFragments') {
                $name = $params['newColumnName'] ?? 'New Column';
                if ($maxCols > 1) {
                    for ($i = 0; $i < $maxCols; $i++) $newHeaders[] = $name . ' ' . ($i + 1);
                } else {
                    $newHeaders[] = $name;
                }
            } elseif ($ruleKey === 'splitByDelimiter') {
                $prefix = $params['newColumnsPrefix'] ?? 'Column';
                for ($i = 0; $i < $maxCols; $i++) $newHeaders[] = $prefix . ' ' . ($i + 1);
            } else {
                for ($i = 0; $i < $maxCols; $i++) $newHeaders[] = 'Rule ' . $ruleIndex . ' - ' . ($i + 1);
            }
        }

        // Pass 2: Write to CSV
        $this->debugLog('Phase 2 Start (Write CSV)');
        fputcsv($csvHandle, $newHeaders);
        
        $removeTagsGlobal = (bool)($config['removeTags'] ?? $config['removeTagsAll'] ?? false);
        $progressRows = 0;
        
        // Re-open Reader for Pass 2
        $reader = $this->getReaderForFile($inputFilePath);
        
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getIndex() > 0) break; 

            foreach ($sheet->getRowIterator() as $row) {
                // Extract row data
                    $rowData = [];
                    foreach ($row->cells as $cell) {
                        $val = $cell->getValue();
                    if ($val instanceof \DateTimeInterface) {
                        $val = $val->format('Y-m-d H:i:s');
                    }
                    $rowData[] = (string)$val;
                }
                
                if ($progressRows === 0) { // Skip Header
                    $progressRows++;
                    continue;
                }

                // Pad rowData to match original headers count to ensure rules start at correct column
                $headerCount = count($headers);
                while (count($rowData) < $headerCount) {
                    $rowData[] = '';
                }

                // Apply Rules
                $extraColumns = [];
                foreach ($rules as $ruleIndex => $rule) {
                    $maxCols = $ruleMaxCols[$ruleIndex] ?? 0;
                    if ($maxCols === 0) continue;

                    $ruleKey = $rule['ruleKey'] ?? '';
                    $sourceColumn = $rule['sourceColumn'] ?? '';
                    $params = $rule['params'] ?? [];

                    $colIndex = $this->resolveColumnIndex($headers, $sourceColumn);
                    $cellValue = ($colIndex !== -1) ? ($rowData[$colIndex] ?? '') : '';

                    $ruleResult = $this->applyModexRule($ruleKey, $cellValue, $params);
                    
                    $data = [];
                    if ($ruleResult !== null) {
                        $data = is_array($ruleResult) ? $ruleResult : [$ruleResult];
                    }
                    
                    while (count($data) < $maxCols) {
                        $data[] = '';
                    }
                    
                    foreach ($data as $val) {
                        $extraColumns[] = (string)$val;
                    }
                }
                
                $finalRow = array_merge($rowData, $extraColumns);

                if ($removeTagsGlobal) {
                    foreach ($finalRow as &$v) {
                        $v = strip_tags($v);
                    }
                }

                fputcsv($csvHandle, $finalRow);

                $progressRows++;
                if ($progressRows <= 100 || $progressRows % 10 === 0) {
                     try {
                        $currentConfig = $this->modexFile->export_config ?? [];
                        $currentConfig['progress_rows'] = $progressRows;
                        $this->modexFile->update(['export_config' => $currentConfig]);
                    } catch (\Throwable $e) {}
                }
            }
        }
        $reader->close();
        fclose($csvHandle);
        
        $this->debugLog('Phase 2 completed.');

        // Save CSV path
        try {
            $currentConfig = $this->modexFile->export_config ?? [];
            $currentConfig['temp_csv_path'] = str_replace(storage_path('app/'), '', $tempCsvPath);
            $this->modexFile->update(['export_config' => $currentConfig]);
        } catch (\Throwable $e) {}

        // --- PHASE 3: CSV to XLSX ---
        $this->debugLog('Phase 3 Start (CSV to XLSX)');
        
        // Update Phase to generating
        try {
            $conf = $this->modexFile->export_config ?? [];
            $conf['phase'] = 'generating';
            $this->modexFile->update(['export_config' => $conf]);
        } catch (\Throwable $e) {}

        gc_collect_cycles();
        
        $tempOut = 'temp/modex_out_' . time() . '_' . uniqid() . '.xlsx';
        $fullOutputPath = Storage::path($tempOut);
        
        $dir = dirname($fullOutputPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        try {
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($fullOutputPath);

            $csvHandle = fopen($tempCsvPath, 'r');
            $bom = fread($csvHandle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($csvHandle);

            $rowIndex = 0;
            while (($data = fgetcsv($csvHandle)) !== false) {
                 $cells = array_map(function($value) {
                     $strVal = (string)$value;
                     if (str_starts_with($strVal, '=')) $strVal = ' ' . $strVal;
                     return Cell::fromValue($strVal);
                 }, $data);
                 
                 $row = new Row($cells);
                 $writer->addRow($row);
                 
                 $rowIndex++;
                 if ($rowIndex % 5000 === 0) gc_collect_cycles();
            }
            fclose($csvHandle);
            $writer->close();
            
            @unlink($tempCsvPath);
            $this->debugLog('Phase 3 completed. Rows: ' . $rowIndex);

            // Update model immediately
            try {
                $finalSize = filesize($fullOutputPath);
                $this->modexFile->update([
                    'status' => 'completed',
                    'file_path' => $tempOut,
                    'file_size' => $finalSize,
                    'total_rows' => $rowIndex,
                    'export_config' => array_merge($this->modexFile->export_config ?? [], ['phase' => 'completed', 'progress_rows' => $rowIndex])
                ]);
            } catch (\Throwable $e) {
                $this->debugLog('Final update failed: ' . $e->getMessage());
            }

            return $tempOut;

        } catch (\Throwable $e) {
            $this->debugLog('Spout write failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function debugLog($message)
    {
        Log::info("[ProcessModexJob] " . $message);
    }

    protected function getReaderForFile(string $filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            $options = new CsvOptions();
            $reader = new CsvReader($options);
        } else {
            $options = new XlsxOptions();
            $reader = new XlsxReader($options);
        }
        $reader->open($filePath);
        return $reader;
    }


    /**
     * Найти индекс колонки
     */
    protected function resolveColumnIndex(array $headers, string $sourceColumn): int
    {
        // 1. Try column letters (A, B, C...)
        if (preg_match('/^[A-Z]+$/i', $sourceColumn)) {
             try {
                 return Coordinate::columnIndexFromString($sourceColumn) - 1;
             } catch (\Exception $e) {}
        }
        
        // 2. Try header name (case-insensitive)
        foreach ($headers as $index => $header) {
            if (strcasecmp(trim($header), trim($sourceColumn)) === 0) {
                return $index;
            }
        }
        
        // 3. Try numeric index
        if (is_numeric($sourceColumn)) {
             $asNum = (int)$sourceColumn;
             if ($asNum >= 0 && $asNum < count($headers)) {
                 return $asNum;
             }
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
        $delimiters = $this->parseDelimiters($delimitersTextarea);
        
        // Если нужно искать кавычки, добавляем их в список разделителей
        if ($searchQuotes) {
            $delimiters[] = '"';
        }

        // Ищем фрагмент после которого извлекать
        $startPos = strpos($cellValue, $startFragment);
        if ($startPos === false) {
            return '';
        }

        $afterStart = substr($cellValue, $startPos + strlen($startFragment));
        
        // Удаляем пробелы в начале найденной строки
        $afterStart = ltrim($afterStart);

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
            $fragmentsToDelete = $this->parseDelimiters($deleteFragmentsAfter);
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
            $fragmentsToDelete = $this->parseDelimiters($deleteFragmentsAfter);
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
    protected function parseDelimiters(string $textarea): array
    {
        // Заменяем переносы строк на запятые, чтобы избежать проблем с парсингом
        // и поддержать списки разделителей с новой строки
        $normalized = str_replace(["\r", "\n"], ',', $textarea);
        
        // Парсим CSV строку
        $delimiters = str_getcsv($normalized);
        
        // Фильтруем пустые значения, но сохраняем пробелы
        return array_unique(array_filter($delimiters, function($v) {
            return $v !== null && $v !== '';
        }));
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
