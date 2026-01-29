<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

// Имитируем ситуацию, когда field_labels содержит null значения
$fieldLabels = [
    'id' => 'ID',
    'name' => null,  // Имитируем undefined из JavaScript
    'sku' => 'Артикул'
];

echo "Testing with field_labels containing null values...\n";
echo "field_labels: " . json_encode($fieldLabels) . "\n";

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Тест null labels ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name', 'sku'],
        'filters' => [],
        'field_labels' => $fieldLabels,
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created file ID: {$exportFile->id}\n";

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed successfully\n";
} catch (Exception $e) {
    echo "Job failed with error: " . $e->getMessage() . "\n";
    echo "Error class: " . get_class($e) . "\n";
    if ($e instanceof \Error) {
        echo "This is a PHP Error (not Exception)\n";
    }
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";