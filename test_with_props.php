<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Creating export with properties...\n";

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Экспорт с характеристиками ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name', 'prop_1', 'prop_2'], // Добавляем характеристики
        'filters' => [],
        'field_labels' => [
            'id' => 'ID',
            'name' => 'Название',
            'prop_1' => 'Характеристика 1',
            'prop_2' => 'Характеристика 2'
        ],
        'with_characteristics' => true,
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
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";