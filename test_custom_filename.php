<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;

// Создаем тестовый экспорт файл с пользовательским именем без расширения
$exportFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'export_' . time() . '_' . uniqid() . '.xlsx',
    'original_filename' => 'Мой тестовый экспорт.xlsx', // Пользовательское имя с расширением
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

// Симулируем логику контроллера для пользовательского имени без расширения
$customFilename = 'Мой экспорт без расширения';
$format = 'excel';
$extension = match($format) {
    'excel' => 'xlsx',
    'csv' => 'csv',
    'txt' => 'txt',
    default => 'xlsx'
};

$originalFilename = $customFilename;
// Добавляем расширение, если его нет
if (!preg_match('/\.(xlsx?|csv|txt)$/i', $originalFilename)) {
    $originalFilename .= '.' . $extension;
}
$filename = 'export_' . time() . '_' . uniqid() . '.' . $extension;

echo "Simulated controller logic:\n";
echo "Custom filename: '$customFilename'\n";
echo "Extension: '$extension'\n";
echo "Original filename: '$originalFilename'\n";
echo "System filename: '$filename'\n";