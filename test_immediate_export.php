<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Creating immediate export...\n";

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Немедленный экспорт ' . date('d.m.Y H:i:s') . '.xlsx',
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

echo "File created with ID: {$exportFile->id}\n";

echo "Running ProcessExportJob...\n";
ProcessExportJob::dispatchSync($exportFile);

echo "Job completed. Checking file...\n";

$updatedFile = $exportFile->fresh();
echo "Status: {$updatedFile->status}\n";
echo "File path: {$updatedFile->file_path}\n";

$fullPath = storage_path('app/' . $updatedFile->file_path);
echo "Full path: {$fullPath}\n";
echo "File exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . "\n";

if (file_exists($fullPath)) {
    echo "File size: " . filesize($fullPath) . " bytes\n";

    // Проверим содержимое первых 200 символов
    $content = file_get_contents($fullPath, false, null, 0, 200);
    echo "Content preview: " . substr($content, 0, 100) . "...\n";
}

echo "\nFiles in exports directory after export:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    $exportFiles = [];
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            $exportFiles[] = "  {$file} - {$size} bytes";
        }
    }
    sort($exportFiles);
    foreach ($exportFiles as $fileInfo) {
        echo $fileInfo . "\n";
    }
}