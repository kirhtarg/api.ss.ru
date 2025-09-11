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
