<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Тест нашего API /api/phone/send-code ===\n";

// Тестовые данные
$testPhones = [
    '+79991234567',
    '79991234567', 
    '89991234567',
    '+7 (999) 123-45-67'
];

foreach ($testPhones as $phone) {
    echo "\n--- Тестируем номер: $phone ---\n";
    
    $requestData = ['phone' => $phone];
    
    echo "Отправляем запрос:\n";
    echo "URL: https://ss75-api.kirhtarg.ru/api/phone/send-code\n";
    echo "Данные: " . json_encode($requestData, JSON_PRETTY_PRINT) . "\n\n";

    try {
        // Отправляем запрос к нашему API
        $response = Http::timeout(30)->withOptions([
            'verify' => false
        ])->withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ])->post('https://ss75-api.kirhtarg.ru/api/phone/send-code', $requestData);

        echo "=== ОТВЕТ ОТ НАШЕГО API ===\n";
        echo "HTTP Status: " . $response->status() . "\n";
        echo "Response Headers:\n";
        foreach ($response->headers() as $key => $value) {
            echo "  $key: " . (is_array($value) ? implode(', ', $value) : $value) . "\n";
        }
        
        $data = $response->json();
        echo "\nResponse Body:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        if ($response->successful()) {
            if (isset($data['success']) && $data['success'] === true) {
                echo "✅ УСПЕХ! Код отправлен\n";
                echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
                echo "Phone: " . ($data['phone'] ?? 'N/A') . "\n";
            } else {
                echo "❌ ОШИБКА: " . ($data['message'] ?? 'Неизвестная ошибка') . "\n";
                if (isset($data['errors'])) {
                    echo "Validation Errors: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        } else {
            echo "❌ HTTP ОШИБКА: " . $response->status() . "\n";
            echo "Error: " . ($data['message'] ?? 'Неизвестная ошибка') . "\n";
            if (isset($data['errors'])) {
                echo "Validation Errors: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE) . "\n";
            }
        }

    } catch (Exception $e) {
        echo "❌ ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
}

echo "\n=== КОНЕЦ ТЕСТА ===\n";
