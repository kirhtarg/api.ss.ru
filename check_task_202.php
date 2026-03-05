<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$file = ExportFile::find(202);

if ($file) {
    echo "File ID: {$file->id}\n";
    echo "Input path: {$file->export_config['input_file_path']}\n";
    $fullPath = storage_path('app/'.$file->export_config['input_file_path']);
    echo "Full path: {$fullPath}\n";
    echo 'File exists: '.(file_exists($fullPath) ? 'YES' : 'NO')."\n";
} else {
    echo "File not found\n";
}
