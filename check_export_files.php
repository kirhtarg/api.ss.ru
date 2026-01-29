<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::orderBy('created_at', 'desc')->take(5)->get();

echo "Последние 5 файлов экспорта:\n";
foreach ($files as $file) {
    echo "ID: {$file->id}\n";
    echo "Status: {$file->status}\n";
    echo "Filename: {$file->filename}\n";
    echo "File path: {$file->file_path}\n";
    echo "Created: {$file->created_at}\n";
    echo "File exists: " . (file_exists(storage_path('app/' . $file->file_path)) ? 'yes' : 'no') . "\n";
    echo "---\n";
}