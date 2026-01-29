<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessModexJob;

// Создаем задачу с чистым файлом без правил
$modexFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'clean_test_' . time() . '.xlsx',
    'original_filename' => 'clean_test_processed.xlsx',
    'format' => 'excel',
    'status' => 'pending',
    'export_config' => [
        'type' => 'modex',
        'rules' => [], // Без правил - просто копирование
        'input_file_path' => 'temp/clean_test.xlsx'
    ]
]);

echo "Created clean modex file: {$modexFile->id}\n";

// Запускаем обработку
ProcessModexJob::dispatch($modexFile);

echo "Job dispatched for clean file\n";