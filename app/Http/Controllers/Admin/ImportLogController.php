<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class ImportLogController extends Controller
{
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
}
