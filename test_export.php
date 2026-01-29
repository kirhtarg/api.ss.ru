<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

// Создаем тестовый экспорт файл
$exportFile = ExportFile::create([
    'created_by' => 1, // Предполагаем, что пользователь с ID 1 существует
    'filename' => 'test_export_' . time(),
    'original_filename' => 'Тестовый экспорт ' . date('d.m.Y H:i'),
    'file_path' => 'exports/test_export_' . time() . '.xlsx',
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

// Запускаем Job синхронно
$job = new ProcessExportJob($exportFile);
$job->handle();

echo "Job completed\n";
echo "File status: {$exportFile->fresh()->status}\n";
echo "File path: {$exportFile->fresh()->file_path}\n";
echo "File exists: " . (file_exists($exportFile->fresh()->getFullPath()) ? 'yes' : 'no') . "\n";