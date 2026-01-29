<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$pending = ExportFile::where('status', 'pending')->get();
echo "Pending files: " . $pending->count() . "\n";

foreach ($pending as $file) {
    echo "ID: {$file->id} - Created: {$file->created_at} - Filename: {$file->filename}\n";
}

$completed = ExportFile::where('status', 'completed')->get();
echo "\nCompleted files: " . $completed->count() . "\n";

foreach ($completed as $file) {
    echo "ID: {$file->id} - Created: {$file->created_at} - Filename: {$file->filename} - File exists: " . (file_exists(storage_path('app/' . $file->file_path)) ? 'yes' : 'no') . "\n";
}