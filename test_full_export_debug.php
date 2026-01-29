<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing full export with debugging...\n";

// Создаем экспорт с полными настройками
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Отладка полного экспорта ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name', 'variation', 'prop_1', 'attr_color'],
        'filters' => [
            'is_active' => '1'  // Только активные товары
        ],
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара',
            'variation' => 'Вариация',
            'prop_1' => 'Характеристика 1',
            'attr_color' => 'Цвет'
        ],
        'with_characteristics' => true,
        'with_variation_attributes' => true
    ]
]);

echo "Created file ID: {$exportFile->id}\n";
echo "Config:\n" . json_encode($exportFile->export_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed successfully\n";
} catch (Exception $e) {
    echo "Job failed: " . $e->getMessage() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";
echo "File exists: " . (file_exists(storage_path('app/' . $updatedFile->file_path)) ? 'YES' : 'NO') . "\n";