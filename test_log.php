<?php
// Тестируем логирование точно так же, как в контроллере
file_put_contents('F:/Work/Projects/SS/api.ss.ru/storage/logs/laravel.log', "[" . date('Y-m-d H:i:s') . "] TEST_LOG: Testing file_put_contents from PHP script\n", FILE_APPEND);

// Тестируем с другими путями
file_put_contents(__DIR__ . '/storage/logs/laravel.log', "[" . date('Y-m-d H:i:s') . "] TEST_LOG_REL: Testing relative path\n", FILE_APPEND);

// Тестируем в другом файле
file_put_contents('F:/Work/Projects/SS/api.ss.ru/test_log_direct.txt', "[" . date('Y-m-d H:i:s') . "] TEST_DIRECT: Direct file write\n", FILE_APPEND);

echo "Log test completed\n";