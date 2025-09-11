<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ImportLogService
{
    private $logDir;
    
    public function __construct()
    {
        $this->logDir = public_path('logs');
        
        // Создаем директорию если не существует
        if (!File::exists($this->logDir)) {
            File::makeDirectory($this->logDir, 0755, true);
        }
    }
    
    /**
     * Очистить все лог-файлы перед новым импортом
     */
    public function clearAllLogs()
    {
        $logTypes = ['import-load', 'import-skip', 'import-update', 'import-error'];
        
        foreach ($logTypes as $type) {
            $this->clearLog($type);
        }
        
    }
    
    /**
     * Очистить конкретный лог-файл
     */
    public function clearLog($type)
    {
        $logPath = $this->getLogPath($type);
        File::put($logPath, '');
    }
    
    /**
     * Записать успешно загруженную строку
     */
    public function logLoaded($count, $sku, $name)
    {
        $this->writeLog('import-load', "{$count} - {$sku} - {$name}");
    }
    
    /**
     * Записать обновленную строку
     */
    public function logUpdated($count, $sku, $name)
    {
        $this->writeLog('import-update', "{$count} - {$sku} - {$name}");
    }
    
    /**
     * Записать пропущенную строку
     */
    public function logSkipped($count, $sku, $name, $reason)
    {
        $this->writeLog('import-skip', "{$count} - {$sku} - {$name} - {$reason}");
    }
    
    /**
     * Записать ошибку
     */
    public function logError($count, $sku, $name, $error)
    {
        $this->writeLog('import-error', "{$count} - {$sku} - {$name} - {$error}");
    }
    
    /**
     * Записать общую ошибку импорта
     */
    public function logGeneralError($error)
    {
        $this->writeLog('import-error', "GENERAL - GENERAL - GENERAL - {$error}");
    }
    
    /**
     * Записать ошибку загрузки файла
     */
    public function logFileLoadingError($error, $fileName = null, $sheetName = null)
    {
        $fileName = $fileName ? " ({$fileName})" : '';
        $sheetName = $sheetName ? " - Лист: {$sheetName}" : '';
        $this->writeLog('import-error', "FILE_LOADING{$fileName}{$sheetName} - FILE_LOADING - FILE_LOADING - {$error}");
    }
    
    /**
     * Записать ошибку загрузки изображения
     */
    public function logImageLoadingError($error, $imageUrl = null, $goodSku = null)
    {
        $imageUrl = $imageUrl ? " ({$imageUrl})" : '';
        $goodSku = $goodSku ? " - Товар: {$goodSku}" : '';
        $this->writeLog('import-error', "IMAGE_LOADING{$imageUrl}{$goodSku} - IMAGE_LOADING - IMAGE_LOADING - {$error}");
    }
    
    /**
     * Записать в лог-файл
     */
    private function writeLog($type, $message)
    {
        $logPath = $this->getLogPath($type);
        $timestamp = now()->format('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        
        File::append($logPath, $logEntry);
    }
    
    /**
     * Получить путь к лог-файлу
     */
    private function getLogPath($type)
    {
        return $this->logDir . "/{$type}.log";
    }
    
    /**
     * Пакетная запись в лог загрузки
     */
    public function logLoadedBatch($items)
    {
        $lines = [];
        foreach ($items as $item) {
            $sheet = $item['sheet'] ?? 'неизвестно';
            $lines[] = "{$item['count']} - {$item['sku']} - {$item['name']} - Лист: {$sheet}";
        }
        
        if (!empty($lines)) {
            $this->writeLog('import-load', implode("\n", $lines));
        }
    }
    
    /**
     * Пакетная запись в лог обновления
     */
    public function logUpdatedBatch($items)
    {
        $lines = [];
        foreach ($items as $item) {
            $sheet = $item['sheet'] ?? 'неизвестно';
            $lines[] = "{$item['count']} - {$item['sku']} - {$item['name']} - Лист: {$sheet}";
        }
        
        if (!empty($lines)) {
            $this->writeLog('import-update', implode("\n", $lines));
        }
    }
    
    /**
     * Пакетная запись в лог пропуска
     */
    public function logSkippedBatch($items)
    {
        $lines = [];
        foreach ($items as $item) {
            $sheet = $item['sheet'] ?? 'неизвестно';
            $lines[] = "{$item['count']} - {$item['sku']} - {$item['name']} - Лист: {$sheet} - {$item['reason']}";
        }
        
        if (!empty($lines)) {
            $this->writeLog('import-skip', implode("\n", $lines));
        }
    }
    
    /**
     * Пакетная запись в лог ошибок
     */
    public function logErrorBatch($items)
    {
        $lines = [];
        foreach ($items as $item) {
            $sheet = $item['sheet'] ?? 'неизвестно';
            $lines[] = "{$item['count']} - {$item['sku']} - {$item['name']} - Лист: {$sheet} - {$item['error']}";
        }
        
        if (!empty($lines)) {
            $this->writeLog('import-error', implode("\n", $lines));
        }
    }

    /**
     * Получить статистику логов
     */
    public function getLogStats()
    {
        $stats = [];
        $logTypes = ['import-load', 'import-skip', 'import-update', 'import-error'];
        
        foreach ($logTypes as $type) {
            $logPath = $this->getLogPath($type);
            
            if (File::exists($logPath)) {
                $content = File::get($logPath);
                $lines = array_filter(explode("\n", trim($content)));
                $stats[$type] = [
                    'count' => count($lines),
                    'lastModified' => File::lastModified($logPath),
                    'size' => File::size($logPath)
                ];
            } else {
                $stats[$type] = [
                    'count' => 0,
                    'lastModified' => null,
                    'size' => 0
                ];
            }
        }
        
        return $stats;
    }
}
