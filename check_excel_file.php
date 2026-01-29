<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    $filePath = storage_path('app/temp/test_small.xlsx');
    echo "Checking file: {$filePath}\n";
    echo "File exists: " . (file_exists($filePath) ? 'YES' : 'NO') . "\n";

    if (file_exists($filePath)) {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        echo "Active sheet: " . $sheet->getTitle() . "\n";

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        echo "Rows: {$highestRow}, Columns: {$highestColumn}\n";

        // Проверим несколько ячеек
        for ($row = 1; $row <= min(5, $highestRow); $row++) {
            for ($col = 'A'; $col <= 'C'; $col++) {
                $cell = $sheet->getCell($col . $row);
                $value = $cell->getValue();
                echo "Cell {$col}{$row}: " . (is_string($value) ? substr($value, 0, 50) : $value) . "\n";
            }
        }

        // Проверим ячейку Z1381, где была ошибка
        $cellZ1381 = $sheet->getCell('Z1381');
        if ($cellZ1381) {
            $value = $cellZ1381->getValue();
            echo "Cell Z1381: " . (is_string($value) ? substr($value, 0, 100) : $value) . "\n";
            echo "Cell Z1381 formula: " . ($cellZ1381->isFormula() ? $cellZ1381->getFormula() : 'not a formula') . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}