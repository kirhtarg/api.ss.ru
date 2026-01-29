<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$file = ExportFile::find(6);

if ($file) {
    echo "filename: {$file->filename}\n";
    echo "original_filename: {$file->original_filename}\n";
    echo "format: {$file->format}\n";
    echo "file_path: {$file->file_path}\n";
} else {
    echo "File not found\n";
}