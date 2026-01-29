<?php

$logFile = 'storage/logs/laravel.log';
$cleanLogFile = 'storage/logs/laravel_clean_final.log';

if (!file_exists($logFile)) {
    echo "Log file not found\n";
    exit(1);
}

$content = file_get_contents($logFile);
$lines = explode("\n", $content);

$cleanLines = [];
foreach ($lines as $line) {
    // Пропускаем CORS логи
    if (strpos($line, '=== CORS') !== false) {
        continue;
    }

    // Пропускаем отладочные логи ProcessExportJob
    if (strpos($line, 'ProcessExportJob: formatExportData') !== false ||
        strpos($line, 'ProcessExportJob: Processing good') !== false ||
        strpos($line, 'ProcessExportJob: getExportData completed') !== false ||
        strpos($line, 'ProcessExportJob: About to generate') !== false ||
        strpos($line, 'ProcessExportJob: generateFileContent completed') !== false ||
        strpos($line, 'findPropertyByIdOrName:') !== false ||
        strpos($line, 'findVariationAttributeByIdOrName:') !== false) {
        continue;
    }

    // Оставляем только важные логи экспорта
    if (strpos($line, 'ProcessExportJob: ===== STARTING EXPORT =====') !== false ||
        strpos($line, 'ProcessExportJob: Found selected_ids') !== false ||
        strpos($line, 'ProcessExportJob: Applying') !== false ||
        strpos($line, 'ProcessExportJob: Filters applied') !== false ||
        strpos($line, 'Export completed successfully') !== false ||
        strpos($line, 'ShopGoodsController: selected_ids received') !== false) {
        $cleanLines[] = $line;
    }
}

file_put_contents($cleanLogFile, implode("\n", $cleanLines));
echo "Clean log saved to: $cleanLogFile\n";
echo "Original lines: " . count($lines) . "\n";
echo "Clean lines: " . count($cleanLines) . "\n";