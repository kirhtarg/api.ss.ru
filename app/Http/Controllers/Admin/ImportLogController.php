<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class ImportLogController extends Controller
{
    protected $importLogService;
    
    public function __construct(ImportLogService $importLogService)
    {
        $this->importLogService = $importLogService;
    }
    /**
     * Получить содержимое лог-файла
     */
    public function getLog(Request $request, $type)
    {
        $allowedTypes = ['import-load', 'import-skip', 'import-update', 'import-error'];
        
        if (!in_array($type, $allowedTypes)) {
            return response()->json(['error' => 'Invalid log type'], 400);
        }
        
        $logPath = public_path("logs/{$type}.log");
        
        if (!File::exists($logPath)) {
            return response()->json(['content' => '', 'lines' => 0]);
        }
        
        $content = File::get($logPath);
        $lines = explode("\n", trim($content));
        
        return response()->json([
            'content' => $content,
            'lines' => count($lines),
            'lastModified' => File::lastModified($logPath)
        ]);
    }
    
    /**
     * Очистить лог-файл
     */
    public function clearLog(Request $request, $type)
    {
        $allowedTypes = ['import-load', 'import-skip', 'import-update', 'import-error'];
        
        if (!in_array($type, $allowedTypes)) {
            return response()->json(['error' => 'Invalid log type'], 400);
        }
        
        $logPath = public_path("logs/{$type}.log");
        
        // Создаем директорию если не существует
        $logDir = dirname($logPath);
        if (!File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }
        
        // Очищаем файл
        File::put($logPath, '');
        
        return response()->json(['success' => true]);
    }
    
    /**
     * Получить статистику логов
     */
    public function getLogStats()
    {
        $stats = [];
        $logTypes = ['import-load', 'import-skip', 'import-update', 'import-error'];
        
        foreach ($logTypes as $type) {
            $logPath = public_path("logs/{$type}.log");
            
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
        
        return response()->json($stats);
    }
    
    /**
     * Записать строку в лог загрузки
     */
    public function logLoad(Request $request)
    {
        $count = $request->input('count');
        $sku = $request->input('sku');
        $name = $request->input('name');
        
        $this->importLogService->logLoaded($count, $sku, $name);
        
        return response()->json(['success' => true]);
    }
    
    /**
     * Записать строку в лог обновления
     */
    public function logUpdate(Request $request)
    {
        $count = $request->input('count');
        $sku = $request->input('sku');
        $name = $request->input('name');
        
        $this->importLogService->logUpdated($count, $sku, $name);
        
        return response()->json(['success' => true]);
    }
    
    /**
     * Записать строку в лог пропуска
     */
    public function logSkip(Request $request)
    {
        $count = $request->input('count');
        $sku = $request->input('sku');
        $name = $request->input('name');
        $reason = $request->input('reason');
        
        $this->importLogService->logSkipped($count, $sku, $name, $reason);
        
        return response()->json(['success' => true]);
    }
    
    /**
     * Записать строку в лог ошибок
     */
    public function logError(Request $request)
    {
        $count = $request->input('count');
        $sku = $request->input('sku');
        $name = $request->input('name');
        $error = $request->input('error');
        $type = $request->input('type');
        
        // Если передана только строка ошибки (общая ошибка)
        if ($error && !$count && !$sku && !$name) {
            if ($type === 'image_loading') {
                $imageUrl = $request->input('imageUrl');
                $goodSku = $request->input('goodSku');
                $this->importLogService->logImageLoadingError($error, $imageUrl, $goodSku);
            } else {
                $this->importLogService->logGeneralError($error);
            }
        } else {
            // Конкретная ошибка товара
            $this->importLogService->logError($count, $sku, $name, $error);
        }
        
        return response()->json(['success' => true]);
    }
    
    /**
     * Пакетная запись в лог загрузки
     */
    public function logLoadBatch(Request $request)
    {
        $items = $request->input('items', []);
        
        $this->importLogService->logLoadedBatch($items);
        
        return response()->json(['success' => true, 'count' => count($items)]);
    }
    
    /**
     * Пакетная запись в лог обновления
     */
    public function logUpdateBatch(Request $request)
    {
        $items = $request->input('items', []);
        
        $this->importLogService->logUpdatedBatch($items);
        
        return response()->json(['success' => true, 'count' => count($items)]);
    }
    
    /**
     * Пакетная запись в лог пропуска
     */
    public function logSkipBatch(Request $request)
    {
        $items = $request->input('items', []);
        
        $this->importLogService->logSkippedBatch($items);
        
        return response()->json(['success' => true, 'count' => count($items)]);
    }
    
    /**
     * Пакетная запись в лог ошибок
     */
    public function logErrorBatch(Request $request)
    {
        $items = $request->input('items', []);
        
        $this->importLogService->logErrorBatch($items);
        
        return response()->json(['success' => true, 'count' => count($items)]);
    }
}
