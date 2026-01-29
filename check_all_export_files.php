<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::orderBy('created_at', 'desc')->get();

echo "Все файлы экспорта:\n";
foreach ($files as $file) {
    $fullPath = storage_path('app/' . $file->file_path);
    $exists = file_exists($fullPath);
    $size = $exists ? filesize($fullPath) : 0;

    echo "ID: {$file->id}\n";
    echo "  Status: {$file->status}\n";
    echo "  Filename: {$file->filename}\n";
    echo "  Original: {$file->original_filename}\n";
    echo "  File path: {$file->file_path}\n";
    echo "  Full path: {$fullPath}\n";
    echo "  Exists: " . ($exists ? 'YES' : 'NO') . "\n";
    echo "  Size: " . ($exists ? number_format($size) . ' bytes' : 'N/A') . "\n";
    echo "  Created: {$file->created_at}\n";
    echo "  ---\n";
}

echo "\nФайлы в папке exports:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            echo "  {$file} - " . number_format($size) . " bytes\n";
        }
    }
}