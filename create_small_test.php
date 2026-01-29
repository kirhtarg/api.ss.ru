<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ExportFile;
use App\Jobs\ProcessModexJob;

// Создаем тестовый файл с меньшими данными
$modexFile = ExportFile::create([
    'created_by' => 1,
    'filename' => 'small_test_' . time() . '.xlsx',
    'original_filename' => 'small_test_modified.xlsx',
    'format' => 'excel',
    'status' => 'pending',
    'export_config' => [
        'type' => 'modex',
        'rules' => [
            [
                'id' => 'modex-rule-1',
                'ruleKey' => 'extractBetweenFragments',
                'sourceColumn' => 'Description',
                'params' => [
                    'startFragment' => 'Артикул:',
                    'delimitersTextarea' => '" "," "',
                    'searchQuotes' => false,
                    'newColumnName' => 'sku',
                    'removeTags' => false,
                    'deleteFragmentsAfter' => '"<br","</strong","</p","<br/","<strong","</li"'
                ]
            ]
        ],
        'input_file_path' => 'temp/test_small.xlsx' // Используем файл, созданный ранее
    ]
]);

echo "Created small test modex file: {$modexFile->id}\n";

// Запускаем обработку
ProcessModexJob::dispatch($modexFile);

echo "Job dispatched for small file\n";