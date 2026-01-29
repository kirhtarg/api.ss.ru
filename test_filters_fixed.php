<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing filters after fixes...\n";

// Создаем экспорт с фильтрами, которые вызывали ошибку
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Тест фильтров после исправлений ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'filters' => [
            'has_variations' => 'false',  // Товары БЕЗ вариаций (этот фильтр вызывал ошибку)
            'is_active' => '1'  // Активные товары
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

echo "Running ProcessExportJob...\n";
try {
    ProcessExportJob::dispatchSync($exportFile);
    echo "Job completed successfully\n";
} catch (Exception $e) {
    echo "Job failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Final status: {$updatedFile->status}\n";

// Проверим количество товаров без вариаций
use App\Models\ShopGood;

$withVariations = ShopGood::whereHas('variations')->count();
$withoutVariations = ShopGood::whereDoesntHave('variations')->count();
$total = ShopGood::count();

echo "\nStats:\n";
echo "Total goods: {$total}\n";
echo "With variations: {$withVariations}\n";
echo "Without variations: {$withoutVariations}\n";

if (file_exists(storage_path('app/' . $updatedFile->file_path))) {
    $filePath = storage_path('app/' . $updatedFile->file_path);
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $dataLines = count($lines) - 1;
    echo "Lines in exported file: " . count($lines) . " (header + {$dataLines} data rows)\n";
    echo "Filter applied correctly: " . ($dataLines == $withoutVariations ? 'YES' : 'NO') . "\n";
}