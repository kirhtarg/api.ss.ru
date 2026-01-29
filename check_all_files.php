<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::all();

foreach ($files as $file) {
    echo "ID: {$file->id}, filename: {$file->filename}, original: {$file->original_filename}, format: {$file->format}\n";
}