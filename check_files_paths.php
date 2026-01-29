<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::where('status', 'completed')->latest()->take(3)->get();

echo "Последние 3 завершенных файла экспорта:\n";
foreach ($files as $file) {
    $fullPath = storage_path('app/' . $file->file_path);
    $exists = file_exists($fullPath);
    echo "ID {$file->id}: {$file->file_path} -> {$fullPath} (exists: " . ($exists ? 'yes' : 'no') . ")\n";
}

echo "\nСодержимое папки exports:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            echo "  {$file} ({$size} bytes)\n";
        }
    }
} else {
    echo "Папка exports не существует\n";
}