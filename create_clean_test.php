<?php

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Создаем очень простой файл без формул
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Заголовки
$sheet->setCellValue('A1', 'ID');
$sheet->setCellValue('B1', 'Name');
$sheet->setCellValue('C1', 'Description');

// Добавляем простые текстовые данные
$sheet->setCellValue('A2', '1');
$sheet->setCellValue('B2', 'Test Product 1');
$sheet->setCellValue('C2', 'Simple description without formulas');

$sheet->setCellValue('A3', '2');
$sheet->setCellValue('B3', 'Test Product 2');
$sheet->setCellValue('C3', 'Another simple description');

// Сохраняем файл
$writer = new Xlsx($spreadsheet);
$writer->save('storage/app/temp/clean_test.xlsx');

echo "Clean test file created: storage/app/temp/clean_test.xlsx\n";