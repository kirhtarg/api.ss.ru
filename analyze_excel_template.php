<?php

/**
 * Скрипт для анализа структуры Excel-шаблона счета
 * 
 * Использование: php analyze_excel_template.php
 */

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$templatePath = __DIR__ . '/schet-na-oplatu-blank-dlya-ip.xls';

if (!file_exists($templatePath)) {
    die("Файл шаблона не найден: $templatePath\n");
}

try {
    echo "Загрузка Excel-файла...\n";
    $spreadsheet = IOFactory::load($templatePath);
    
    echo "Файл успешно загружен!\n\n";
    
    // Получаем все листы
    $sheetNames = $spreadsheet->getSheetNames();
    echo "Количество листов: " . count($sheetNames) . "\n";
    echo "Названия листов: " . implode(', ', $sheetNames) . "\n\n";
    
    // Анализируем первый лист
    $worksheet = $spreadsheet->getActiveSheet();
    $sheetName = $worksheet->getTitle();
    
    echo "Анализ листа: $sheetName\n";
    echo str_repeat('=', 80) . "\n\n";
    
    // Получаем размеры листа
    $highestRow = $worksheet->getHighestRow();
    $highestColumn = $worksheet->getHighestColumn();
    $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
    
    echo "Размеры листа:\n";
    echo "  Максимальная строка: $highestRow\n";
    echo "  Максимальная колонка: $highestColumn (индекс: $highestColumnIndex)\n\n";
    
    // Анализируем содержимое
    echo "Содержимое листа (первые 50 строк):\n";
    echo str_repeat('-', 80) . "\n";
    
    $data = [];
    $emptyRows = 0;
    $maxRowsToShow = 50;
    
    for ($row = 1; $row <= min($highestRow, $maxRowsToShow); $row++) {
        $rowData = [];
        $isEmpty = true;
        
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellAddress = Coordinate::stringFromColumnIndex($col) . $row;
            $cellValue = $worksheet->getCell($cellAddress)->getValue();
            
            if ($cellValue !== null && $cellValue !== '') {
                $isEmpty = false;
            }
            
            $rowData[] = $cellValue;
        }
        
        if (!$isEmpty) {
            echo "Строка $row: ";
            $nonEmptyCells = array_filter($rowData, function($val) {
                return $val !== null && $val !== '';
            });
            echo implode(' | ', array_map(function($val) {
                return is_string($val) ? substr($val, 0, 30) : (string)$val;
            }, array_slice($nonEmptyCells, 0, 5)));
            echo "\n";
            
            // Сохраняем данные для дальнейшего анализа
            $data[$row] = $rowData;
        } else {
            $emptyRows++;
        }
    }
    
    echo "\nПустых строк (из первых $maxRowsToShow): $emptyRows\n\n";
    
    // Ищем ячейки с формулами
    echo "Поиск формул...\n";
    $formulas = [];
    for ($row = 1; $row <= $highestRow; $row++) {
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellAddress = Coordinate::stringFromColumnIndex($col) . $row;
            $cell = $worksheet->getCell($cellAddress);
            
            if ($cell->getDataType() === 'f') {
                $formula = $cell->getValue();
                $formulas[] = [
                    'cell' => $cellAddress,
                    'formula' => $formula
                ];
            }
        }
    }
    
    if (count($formulas) > 0) {
        echo "Найдено формул: " . count($formulas) . "\n";
        foreach (array_slice($formulas, 0, 10) as $formula) {
            echo "  {$formula['cell']}: {$formula['formula']}\n";
        }
        if (count($formulas) > 10) {
            echo "  ... и еще " . (count($formulas) - 10) . " формул\n";
        }
    } else {
        echo "Формулы не найдены\n";
    }
    
    echo "\n";
    
    // Ищем ячейки с форматированием (жирный, курсив и т.д.)
    echo "Анализ форматирования...\n";
    $formattedCells = [];
    for ($row = 1; $row <= min($highestRow, 30); $row++) {
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellAddress = Coordinate::stringFromColumnIndex($col) . $row;
            $cell = $worksheet->getCell($cellAddress);
            $style = $worksheet->getStyle($cellAddress);
            $font = $style->getFont();
            
            $formatting = [];
            if ($font->getBold()) {
                $formatting[] = 'bold';
            }
            if ($font->getItalic()) {
                $formatting[] = 'italic';
            }
            if ($font->getUnderline() !== 'none') {
                $formatting[] = 'underline';
            }
            
            if (count($formatting) > 0) {
                $cellValue = $cell->getValue();
                $formattedCells[] = [
                    'cell' => $cellAddress,
                    'value' => $cellValue !== null ? (string)$cellValue : '',
                    'formatting' => implode(', ', $formatting)
                ];
            }
        }
    }
    
    if (count($formattedCells) > 0) {
        echo "Найдено отформатированных ячеек: " . count($formattedCells) . "\n";
        foreach (array_slice($formattedCells, 0, 10) as $cell) {
            echo "  {$cell['cell']}: " . substr($cell['value'], 0, 30) . " [{$cell['formatting']}]\n";
        }
    }
    
    echo "\n";
    
    // Сохраняем структуру в JSON для дальнейшего использования
    $structure = [
        'sheet_name' => $sheetName,
        'dimensions' => [
            'max_row' => $highestRow,
            'max_column' => $highestColumn,
            'max_column_index' => $highestColumnIndex
        ],
        'sample_data' => array_slice($data, 0, 20, true), // Первые 20 непустых строк
        'formulas' => $formulas,
        'formatted_cells' => array_slice($formattedCells, 0, 20)
    ];
    
    $jsonPath = __DIR__ . '/excel_template_structure.json';
    file_put_contents($jsonPath, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Структура сохранена в: $jsonPath\n";
    
    echo "\nАнализ завершен!\n";
    
} catch (\Exception $e) {
    die("Ошибка: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
}

