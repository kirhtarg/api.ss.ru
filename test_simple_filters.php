<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing simple filters...\n";

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Тест простых фильтров ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'filters' => [
            'is_active' => '1'  // Только активные товары
        ],
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара'
        ],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed successfully\n";
} catch (Exception $e) {
    echo "Job failed: " . $e->getMessage() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Status: {$updatedFile->status}\n";

if (file_exists(storage_path('app/' . $updatedFile->file_path))) {
    echo "File exists!\n";
} else {
    echo "File does not exist\n";
}