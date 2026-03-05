<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Jobs\ProcessModexJob;
use App\Models\ExportFile;

// Создаем тестовый файл БЕЗ правил обработки
$modexFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'simple_test_'.time().'.xlsx',
    'original_filename' => 'simple_test.xlsx',
    'format' => 'excel',
    'status' => 'pending',
    'export_config' => [
        'type' => 'modex',
        'rules' => [], // Пустой массив правил
        'input_file_path' => 'temp/test_small.xlsx',
    ],
]);

echo "Created simple test modex file: {$modexFile->id}\n";

// Запускаем обработку
ProcessModexJob::dispatch($modexFile);

echo "Job dispatched for simple file\n";
