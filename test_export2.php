<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

// Создаем тестовый экспорт файл
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Тестовый экспорт ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name', 'sku', 'price'],
        'field_labels' => [
            'id' => 'ID',
            'name' => 'Название',
            'sku' => 'Артикул',
            'price' => 'Цена'
        ],
        'filters' => [],
        'with_characteristics' => false,
        'with_variation_attributes' => false
    ]
]);

echo "Created export file ID: {$exportFile->id}\n";
echo "Filename: {$exportFile->filename}\n";
echo "Original filename: {$exportFile->original_filename}\n";

// Запускаем Job синхронно
$job = new ProcessExportJob($exportFile);
$job->handle();

echo "Job completed\n";
echo "File status: {$exportFile->fresh()->status}\n";
echo "File path: {$exportFile->fresh()->file_path}\n";

// Проверим, что в директории
$files = scandir(storage_path('app/exports'));
echo "Files in exports directory:\n";
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "  $file\n";
    }
}