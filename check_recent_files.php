<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::where('status', 'completed')->latest()->take(3)->get();

echo "Последние 3 завершенных файла:\n";
foreach ($files as $file) {
    $exists = file_exists(storage_path('app/' . $file->file_path));
    echo "ID {$file->id}: {$file->file_path} (exists: " . ($exists ? 'YES' : 'NO') . ")\n";
    if ($exists) {
        $size = filesize(storage_path('app/' . $file->file_path));
        echo "  Size: " . number_format($size) . " bytes\n";
    }
    echo "  Created: {$file->created_at}\n";
    echo "  ---\n";
}

echo "\nВсе файлы в папке exports:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    $count = 0;
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            echo "  {$file} - " . number_format($size) . " bytes\n";
            $count++;
        }
    }
    echo "Всего файлов: $count\n";
}