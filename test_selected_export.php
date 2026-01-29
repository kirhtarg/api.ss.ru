<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing export with selected items...\n";

// Создаем экспорт с выбранными товарами (первые 3 товара)
$selectedIds = [90068, 90069, 90070]; // Примеры ID товаров

$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Экспорт выбранных товаров ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'selected_ids' => implode(',', $selectedIds), // Выбранные товары как строка
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара'
        ],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created file ID: {$exportFile->id}\n";
echo "Selected IDs: " . implode(', ', $selectedIds) . "\n";

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
    $filePath = storage_path('app/' . $updatedFile->file_path);
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $dataLines = count($lines) - 1;
    echo "Lines in exported file: " . count($lines) . " (header + {$dataLines} data rows)\n";
    echo "Expected selected items: " . count($selectedIds) . "\n";

    if ($dataLines === count($selectedIds)) {
        echo "✅ Selected items exported correctly!\n";
    } else {
        echo "❌ Wrong number of items exported\n";
    }

    // Покажем первые несколько строк
    echo "\nFirst few lines:\n";
    for ($i = 0; $i < min(5, count($lines)); $i++) {
        echo "  " . trim($lines[$i]) . "\n";
    }
} else {
    echo "File does not exist\n";
}