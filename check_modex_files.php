<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

$modexFiles = ExportFile::where('export_config->type', 'modex')->get();

echo 'Modex files in database: '.$modexFiles->count()."\n";

foreach ($modexFiles as $file) {
    echo "- ID: {$file->id}, Status: {$file->status}, Filename: {$file->filename}, Created: {$file->created_at}\n";
}

echo "\nAll export files:\n";
$allFiles = ExportFile::all();
foreach ($allFiles as $file) {
    $type = $file->export_config['type'] ?? 'export';
    echo "- ID: {$file->id}, Type: {$type}, Status: {$file->status}, Filename: {$file->filename}\n";
}
