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
    'original_filename' => 'Тест сериализации.xlsx',
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

echo "Created export file ID: {$exportFile->id}\n";

// Пробуем сериализовать Job
try {
    $job = new ProcessExportJob($exportFile);
    $serialized = serialize($job);
    echo "Job serialized successfully\n";

    // Пробуем десериализовать
    $unserialized = unserialize($serialized);
    echo "Job unserialized successfully\n";

    // Запускаем Job
    $unserialized->handle();
    echo "Job executed successfully\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}