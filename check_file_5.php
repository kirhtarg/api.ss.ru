<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$file = ExportFile::find(5);

if ($file) {
    echo "File ID: {$file->id}\n";
    echo "Status: {$file->status}\n";
    echo "File path: {$file->file_path}\n";
    echo "getFullPath(): {$file->getFullPath()}\n";
    echo "file_exists(getFullPath()): " . (file_exists($file->getFullPath()) ? 'yes' : 'no') . "\n";
    echo "isDownloadable(): " . ($file->isDownloadable() ? 'yes' : 'no') . "\n";

    // Проверим также storage_path
    echo "storage_path('app'): " . storage_path('app') . "\n";
    echo "Full file path: " . storage_path('app/' . $file->file_path) . "\n";
    echo "file_exists(full path): " . (file_exists(storage_path('app/' . $file->file_path)) ? 'yes' : 'no') . "\n";
} else {
    echo "File not found\n";
}