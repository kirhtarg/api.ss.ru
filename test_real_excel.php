<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessExportJob;

echo "Testing real Excel file generation...\n";

// Создаем тестовый экспорт с Excel форматом
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Настоящий Excel ' . date('d.m.Y H:i') . '.xlsx',
    'file_path' => '',
    'format' => 'excel',
    'status' => 'pending',
    'total_rows' => 0,
    'file_size' => 0,
    'export_config' => [
        'fields' => ['id', 'name'],
        'field_labels' => [
            'id' => 'ID товара',
            'name' => 'Название товара'
        ],
        'filters' => [],
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
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

$updatedFile = $exportFile->fresh();
echo "Status: {$updatedFile->status}\n";
echo "File path: {$updatedFile->file_path}\n";

$fullPath = storage_path('app/' . $updatedFile->file_path);
echo "Full path: {$fullPath}\n";
echo "File exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . "\n";

if (file_exists($fullPath)) {
    $size = filesize($fullPath);
    echo "File size: " . number_format($size) . " bytes\n";

    // Проверим первые байты файла (должен быть ZIP архив для .xlsx)
    $handle = fopen($fullPath, 'rb');
    $header = fread($handle, 4);
    fclose($handle);

    $headerHex = bin2hex($header);
    echo "File header: $headerHex (" . ($headerHex === '504b0304' ? 'Valid ZIP/XLSX' : 'Not XLSX') . ")\n";

    echo "File extension in path: " . pathinfo($fullPath, PATHINFO_EXTENSION) . "\n";
}