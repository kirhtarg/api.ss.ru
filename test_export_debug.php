<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Creating new export file...\n";

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Отладка экспорта ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'field_labels' => [
            'id' => 'ID',
            'name' => 'Название'
        ],
        'filters' => [],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created file ID: {$exportFile->id}\n";

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed\n";
} catch (Exception $e) {
    echo "Job failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";
echo "File path: {$updatedFile->file_path}\n";
echo "File exists: " . (file_exists(storage_path('app/' . $updatedFile->file_path)) ? 'yes' : 'no') . "\n";

echo "\nFiles in exports directory:\n";
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
}