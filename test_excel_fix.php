<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

// Создаем тестовый экспорт с Excel форматом
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.csv', // Меняем на .csv
    'original_filename' => 'Тест Excel ' . date('d.m.Y H:i') . '.csv',
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

echo "Created file ID: {$exportFile->id}\n";

echo "Running ProcessExportJob...\n";
ProcessExportJob::dispatchSync($exportFile);

echo "Job completed. Checking file...\n";

$updatedFile = $exportFile->fresh();
echo "Status: {$updatedFile->status}\n";
echo "File path: {$updatedFile->file_path}\n";

$fullPath = storage_path('app/' . $updatedFile->file_path);
echo "File exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . "\n";

if (file_exists($fullPath)) {
    $size = filesize($fullPath);
    echo "File size: $size bytes\n";

    // Проверим первые байты (BOM)
    $handle = fopen($fullPath, 'rb');
    $bom = fread($handle, 3);
    fclose($handle);

    $bomHex = bin2hex($bom);
    echo "BOM: $bomHex (" . ($bomHex === 'efbbbf' ? 'UTF-8 BOM detected' : 'No BOM') . ")\n";

    // Проверим первые строки
    $lines = file($fullPath);
    echo "First line: " . trim($lines[0]) . "\n";
    if (isset($lines[1])) {
        echo "Second line: " . trim($lines[1]) . "\n";
    }
}