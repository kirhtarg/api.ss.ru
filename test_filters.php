<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing filters application...\n";

// Создаем экспорт с фильтрами
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Тест фильтров ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'filters' => [
            'is_active' => true  // Только активные товары
        ],
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара'
        ],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created file ID: {$exportFile->id}\n";
echo "Filters in config: " . json_encode($exportFile->export_config['filters']) . "\n";

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed successfully\n";
} catch (Exception $e) {
    echo "Job failed: " . $e->getMessage() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";

// Проверим, что фильтр применился - посмотрим на количество товаров
if (file_exists(storage_path('app/' . $updatedFile->file_path))) {
    // Попробуем прочитать файл и посчитать строки
    $filePath = storage_path('app/' . $updatedFile->file_path);
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $dataLines = count($lines) - 1; // Минус заголовок
    echo "Lines in file: " . count($lines) . " (header + {$dataLines} data rows)\n";
} else {
    echo "File does not exist\n";
}

echo "\nTesting without filters for comparison...\n";

// Создаем экспорт без фильтров
$exportFile2 = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Без фильтров ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'filters' => [], // Без фильтров
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара'
        ],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created file ID: {$exportFile2->id} (without filters)\n";

ProcessExportJob::dispatchSync($exportFile2);
$updatedFile2 = $exportFile2->fresh();

if (file_exists(storage_path('app/' . $updatedFile2->file_path))) {
    $filePath2 = storage_path('app/' . $updatedFile2->file_path);
    $content2 = file_get_contents($filePath2);
    $lines2 = explode("\n", $content2);
    $dataLines2 = count($lines2) - 1;
    echo "Lines in file without filters: " . count($lines2) . " (header + {$dataLines2} data rows)\n";
}