<?php

$logFile = 'storage/logs/laravel.log';
$cleanLogFile = 'storage/logs/laravel_clean.log';

if (!file_exists($logFile)) {
    echo "Log file not found\n";
    exit(1);
}

$content = file_get_contents($logFile);
$lines = explode("\n", $content);

$cleanLines = [];
foreach ($lines as $line) {
    // Оставляем только важные строки
    if (strpos($line, 'ProcessExportJob') !== false ||
        strpos($line, 'ShopGoodsController.*selected_ids') !== false ||
        strpos($line, 'Export completed successfully') !== false ||
        strpos($line, 'Found selected_ids') !== false ||
        strpos($line, 'Applying selected_ids') !== false ||
        strpos($line, 'formatExportData') !== false ||
        strpos($line, 'Processing good') !== false) {
        $cleanLines[] = $line;
    }
}

file_put_contents($cleanLogFile, implode("\n", $cleanLines));
echo "Clean log saved to: $cleanLogFile\n";
echo "Original lines: " . count($lines) . "\n";
echo "Clean lines: " . count($cleanLines) . "\n";