<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$file = ExportFile::find(197);

if ($file) {
    echo "Status: {$file->status}, Size: {$file->file_size}\n";
} else {
    echo "File not found\n";
}