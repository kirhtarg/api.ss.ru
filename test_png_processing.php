<?php

// Тест логирования Laravel
echo "Testing Laravel logging...\n";

try {
    require_once 'bootstrap/app.php';

    // Тестируем логирование
    Log::info('TEST: Laravel logging works', [
        'timestamp' => now(),
        'test' => 'png_processing_debug',
        'message' => 'This is a test log entry'
    ]);

    echo "✓ Log entry written\n";

    // Проверим файл логов
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath)) {
        echo "✓ Log file exists: $logPath\n";
        $size = filesize($logPath);
        echo "✓ Log file size: $size bytes\n";

        // Прочитаем последние строки
        $lines = file($logPath);
        $lastLines = array_slice($lines, -5);
        echo "✓ Last 5 lines of log:\n";
        foreach ($lastLines as $line) {
            echo "  " . trim($line) . "\n";
        }
    } else {
        echo "✗ Log file does not exist\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>