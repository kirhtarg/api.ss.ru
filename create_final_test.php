<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessModexJob;

// Создаем финальную тестовую задачу - просто копирование файла
$modexFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'final_test_' . time() . '.xlsx',
    'original_filename' => 'final_test.xlsx',
    'format' => 'excel',
    'status' => 'pending',
    'export_config' => [
        'type' => 'modex',
        'rules' => [], // Без правил - просто копирование
        'input_file_path' => 'temp/test_small.xlsx'
    ]
]);

echo "Created final test modex file: {$modexFile->id}\n";

// Запускаем обработку
ProcessModexJob::dispatch($modexFile);

echo "Job dispatched - should complete quickly\n";