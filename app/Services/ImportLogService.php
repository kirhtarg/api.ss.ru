<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ImportLogService
{
    private $logDir;
    
    public function __construct()
    {
        $this->logDir = storage_path('app/public/import-logs');
        
        // Создаем директорию если не существует
        if (!\App\Helpers\StorageHelper::createDirectory($this->logDir)) {
            \Log::error("Failed to create import logs directory: {$this->logDir}");
        }
    }
    
    /**
     * Очистить все лог-файлы перед новым импортом
     */
    public function clearAllLogs()
    {
        $logTypes = ['import-load', 'import-skip', 'import-update', 'import-error', 'import-variation'];
        
        foreach ($logTypes as $type) {
            $this->clearLog($type);
        }
        
    }
    
    /**
     * Очистить конкретный лог-файл
     */
    public function clearLog($type)
    {
        try {
            $logPath = $this->getLogPath($type);
            
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                \App\Helpers\StorageHelper::createDirectory($this->logDir);
            }
            
            File::put($logPath, '');
        } catch (\Exception $e) {
            \Log::error("Failed to clear import log {$type}: " . $e->getMessage());
        }
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
     * Записать успешную загрузку изображения
     */
    public function logImageLoadingSuccess($imageUrl, $filePath, $goodSku = null)
    {
        $imageUrl = $imageUrl ? " ({$imageUrl})" : '';
        $filePath = $filePath ? " - Файл: {$filePath}" : '';
        $goodSku = $goodSku ? " - Товар: {$goodSku}" : '';
        $this->writeLog('import-load', "IMAGE_SUCCESS{$imageUrl}{$filePath}{$goodSku}");
    }
    
    /**
     * Пакетная запись успешных загрузок изображений
     */
    public function logImageLoadingSuccessBatch($items)
    {
        if (empty($items)) {
            return;
        }
        
        try {
            $logPath = $this->getLogPath('import-load');
            $timestamp = now()->format('Y-m-d H:i:s');
            
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                if (!\App\Helpers\StorageHelper::createDirectory($this->logDir)) {
                    throw new \Exception("Не удалось создать директорию для логов: {$this->logDir}");
                }
            }
            
            // Проверяем права на запись
            if (!is_writable($this->logDir)) {
                throw new \Exception("Директория для логов не доступна для записи: {$this->logDir}");
            }
            
            // Собираем все строки в буфер для пакетной записи (быстрее чем построчная запись)
            $logEntries = [];
            foreach ($items as $item) {
                try {
                    $imageUrl = $item['imageUrl'] ?? '';
                    $filePath = $item['filePath'] ?? '';
                    $goodSku = $item['goodSku'] ?? '';
                    
                    // Ограничиваем длину URL и пути для избежания проблем
                    $imageUrl = mb_substr($imageUrl, 0, 500);
                    $filePath = mb_substr($filePath, 0, 500);
                    $goodSku = mb_substr($goodSku, 0, 100);
                    
                    $logEntries[] = "[{$timestamp}] IMAGE_SUCCESS ({$imageUrl}) - Файл: {$filePath}" . ($goodSku ? " - Товар: {$goodSku}" : '');
                } catch (\Exception $e) {
                    \Log::warning("Ошибка при подготовке элемента для лога успешных изображений", [
                        'error' => $e->getMessage(),
                        'item' => $item
                    ]);
                    // Продолжаем обработку остальных элементов
                    continue;
                }
            }
            
            // Записываем все строки одним вызовом (намного быстрее)
            if (!empty($logEntries)) {
                $logContent = implode(PHP_EOL, $logEntries) . PHP_EOL;
                File::append($logPath, $logContent);
            }
        } catch (\Exception $e) {
            \Log::error("Критическая ошибка при пакетной записи успешных изображений", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'items_count' => count($items)
            ]);
            // Пробрасываем исключение дальше, чтобы контроллер мог его обработать
            throw $e;
        }
    }
    
    /**
     * Пакетная запись ошибок загрузки изображений
     */
    public function logImageLoadingErrorBatch($items)
    {
        if (empty($items)) {
            return;
        }
        
        try {
            $logPath = $this->getLogPath('import-error');
            $timestamp = now()->format('Y-m-d H:i:s');
            
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                if (!\App\Helpers\StorageHelper::createDirectory($this->logDir)) {
                    throw new \Exception("Не удалось создать директорию для логов: {$this->logDir}");
                }
            }
            
            // Проверяем права на запись
            if (!is_writable($this->logDir)) {
                throw new \Exception("Директория для логов не доступна для записи: {$this->logDir}");
            }
            
            // Собираем все строки в буфер для пакетной записи (быстрее чем построчная запись)
            $logEntries = [];
            foreach ($items as $item) {
                try {
                    $error = $item['error'] ?? 'Неизвестная ошибка';
                    $imageUrl = $item['imageUrl'] ?? '';
                    $goodSku = $item['goodSku'] ?? '';
                    
                    // Ограничиваем длину для избежания проблем
                    $error = mb_substr($error, 0, 500);
                    $imageUrl = mb_substr($imageUrl, 0, 500);
                    $goodSku = mb_substr($goodSku, 0, 100);
                    
                    $logEntries[] = "[{$timestamp}] IMAGE_ERROR ({$imageUrl})" . ($goodSku ? " - Товар: {$goodSku}" : '') . " - {$error}";
                } catch (\Exception $e) {
                    \Log::warning("Ошибка при подготовке элемента для лога ошибок изображений", [
                        'error' => $e->getMessage(),
                        'item' => $item
                    ]);
                    // Продолжаем обработку остальных элементов
                    continue;
                }
            }
            
            // Записываем все строки одним вызовом (намного быстрее)
            if (!empty($logEntries)) {
                $logContent = implode(PHP_EOL, $logEntries) . PHP_EOL;
                File::append($logPath, $logContent);
            }
        } catch (\Exception $e) {
            \Log::error("Критическая ошибка при пакетной записи ошибок изображений", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'items_count' => count($items)
            ]);
            // Пробрасываем исключение дальше, чтобы контроллер мог его обработать
            throw $e;
        }
    }
    
    /**
     * Записать действие с вариацией
     */
    public function logVariation($action, $goodName, $goodSku, $variationAttributes, $variationId = null, $details = null)
    {
        $goodSkuStr = $goodSku ? " - SKU: {$goodSku}" : '';
        $variationIdStr = $variationId ? " - ID вариации: {$variationId}" : '';
        $detailsStr = $details ? " - {$details}" : '';

        // Формируем строку атрибутов
        $attributesStr = '';
        if (is_array($variationAttributes) && count($variationAttributes) > 0) {
            $attrParts = [];
            foreach ($variationAttributes as $attr) {
                if (is_array($attr) && isset($attr['name']) && isset($attr['value'])) {
                    $attrParts[] = "{$attr['name']}:{$attr['value']}";
                } elseif (is_string($attr)) {
                    $attrParts[] = $attr;
                }
            }
            $attributesStr = ' - Атрибуты: ' . implode(', ', $attrParts);
        }

        $message = "{$action} - Товар: {$goodName}{$goodSkuStr}{$variationIdStr}{$attributesStr}{$detailsStr}";
        $this->writeLog('import-variation', $message);
    }

    /**
     * Обновляет ID вариации в последней записи лога вариаций
     */
    public function updateLastLogVariationId($variationId)
    {
        try {
            $logPath = $this->getLogPath('import-variation');

            if (!File::exists($logPath)) {
                return;
            }

            $content = File::get($logPath);
            $lines = explode("\n", $content);

            // Находим последнюю непустую строку
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if (!empty($line)) {
                    // Если в строке еще нет ID вариации, добавляем его
                    if (strpos($line, ' - ID вариации: ') === false) {
                        $lines[$i] = str_replace(' - ID вариации: null', " - ID вариации: {$variationId}", $line);
                        $lines[$i] = str_replace('ID вариации: null', "ID вариации: {$variationId}", $line);
                    }
                    break;
                }
            }

            File::put($logPath, implode("\n", $lines));
        } catch (\Exception $e) {
            \Log::error("Failed to update last log variation ID: " . $e->getMessage());
        }
    }
    
    /**
     * Пакетная запись действий с вариациями
     */
    public function logVariationBatch($items)
    {
        $lines = [];
        foreach ($items as $item) {
            $action = $item['action'] ?? 'Действие';
            $goodName = $item['goodName'] ?? 'неизвестно';
            $goodSku = $item['goodSku'] ?? '';
            $variationAttributes = $item['variationAttributes'] ?? [];
            $variationId = $item['variationId'] ?? null;
            $details = $item['details'] ?? null;
            
            $goodSkuStr = $goodSku ? " - SKU: {$goodSku}" : '';
            $variationIdStr = $variationId ? " - ID вариации: {$variationId}" : '';
            $detailsStr = $details ? " - {$details}" : '';
            
            // Формируем строку атрибутов
            $attributesStr = '';
            if (is_array($variationAttributes) && count($variationAttributes) > 0) {
                $attrParts = [];
                foreach ($variationAttributes as $attr) {
                    if (is_array($attr) && isset($attr['name']) && isset($attr['value'])) {
                        $attrParts[] = "{$attr['name']}:{$attr['value']}";
                    } elseif (is_string($attr)) {
                        $attrParts[] = $attr;
                    }
                }
                $attributesStr = ' - Атрибуты: ' . implode(', ', $attrParts);
            }
            
            $lines[] = "{$action} - Товар: {$goodName}{$goodSkuStr}{$variationIdStr}{$attributesStr}{$detailsStr}";
        }
        
        if (!empty($lines)) {
            $this->writeLog('import-variation', implode("\n", $lines));
        }
    }
    
    /**
     * Записать в лог-файл
     */
    private function writeLog($type, $message)
    {
        try {
            $logPath = $this->getLogPath($type);
            $timestamp = now()->format('Y-m-d H:i:s');
            $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
            
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                \App\Helpers\StorageHelper::createDirectory($this->logDir);
            }
            
            File::append($logPath, $logEntry);
        } catch (\Exception $e) {
            \Log::error("Failed to write to import log: " . $e->getMessage());
        }
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
            $goodId = $item['good_id'] ?? '';
            $goodIdStr = $goodId ? " - ID: {$goodId}" : '';
            $lines[] = "{$item['count']} - {$item['sku']} - {$item['name']}{$goodIdStr} - Лист: {$sheet}";
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
        if (empty($items)) {
            return;
        }
        
        try {
            $logPath = $this->getLogPath('import-error');
            $timestamp = now()->format('Y-m-d H:i:s');
            
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                if (!\App\Helpers\StorageHelper::createDirectory($this->logDir)) {
                    throw new \Exception("Не удалось создать директорию для логов: {$this->logDir}");
                }
            }
            
            // Проверяем права на запись
            if (!is_writable($this->logDir)) {
                throw new \Exception("Директория для логов не доступна для записи: {$this->logDir}");
            }
            
            // Собираем все строки в буфер для пакетной записи (быстрее чем построчная запись)
            $logEntries = [];
            foreach ($items as $item) {
                try {
                    // Поддерживаем разные форматы данных (для совместимости)
                    if (isset($item['count']) && isset($item['sku']) && isset($item['name'])) {
                        // Старый формат: ошибки импорта товаров
                        $sheet = $item['sheet'] ?? 'неизвестно';
                        $logEntries[] = "{$item['count']} - {$item['sku']} - {$item['name']} - Лист: {$sheet} - {$item['error']}";
                    } elseif (isset($item['error'])) {
                        // Новый формат: общие ошибки
                        $imageUrl = $item['imageUrl'] ?? '';
                        $goodSku = $item['goodSku'] ?? '';
                        $type = $item['type'] ?? 'general';
                        
                        // Ограничиваем длину для избежания проблем
                        $error = mb_substr($item['error'], 0, 500);
                        $imageUrl = mb_substr($imageUrl, 0, 500);
                        $goodSku = mb_substr($goodSku, 0, 100);
                        
                        if ($type === 'image_loading' && $imageUrl) {
                            $logEntries[] = "IMAGE_ERROR ({$imageUrl})" . ($goodSku ? " - Товар: {$goodSku}" : '') . " - {$error}";
                        } else {
                            $logEntries[] = "ERROR - {$error}" . ($goodSku ? " - Товар: {$goodSku}" : '');
                        }
                    }
                } catch (\Exception $e) {
                    \Log::warning("Ошибка при подготовке элемента для лога ошибок", [
                        'error' => $e->getMessage(),
                        'item' => $item
                    ]);
                    // Продолжаем обработку остальных элементов
                    continue;
                }
            }
            
            // Записываем все строки одним вызовом (намного быстрее)
            if (!empty($logEntries)) {
                $logContent = implode(PHP_EOL, $logEntries) . PHP_EOL;
                File::append($logPath, "[{$timestamp}] " . $logContent);
            }
        } catch (\Exception $e) {
            \Log::error("Критическая ошибка при пакетной записи ошибок", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'items_count' => count($items)
            ]);
            // Пробрасываем исключение дальше, чтобы контроллер мог его обработать
            throw $e;
        }
    }

    /**
     * Получить статистику логов
     */
    public function getLogStats()
    {
        $stats = [];
        $logTypes = ['import-load', 'import-skip', 'import-update', 'import-error', 'import-variation'];
        
        try {
            // Убеждаемся, что директория существует
            if (!File::exists($this->logDir)) {
                \App\Helpers\StorageHelper::createDirectory($this->logDir);
            }
            
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
        } catch (\Exception $e) {
            \Log::error("Failed to get log stats: " . $e->getMessage());
            
            // Возвращаем пустую статистику в случае ошибки
            foreach ($logTypes as $type) {
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
