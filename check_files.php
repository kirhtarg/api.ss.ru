<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::all();

echo "Total files: " . $files->count() . "\n";

foreach ($files as $file) {
    echo "ID: {$file->id}\n";
    echo "Status: {$file->status}\n";
    echo "Path: {$file->file_path}\n";
    echo "Full path: " . storage_path('app/' . $file->file_path) . "\n";
    echo "Exists: " . (file_exists(storage_path('app/' . $file->file_path)) ? 'yes' : 'no') . "\n";
    echo "Downloadable: " . ($file->isDownloadable() ? 'yes' : 'no') . "\n";
    echo "---\n";
}