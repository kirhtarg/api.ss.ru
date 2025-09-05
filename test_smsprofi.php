<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Получаем конфигурацию SMSProfi
$apiKey = '6rjglyfeei7amj1d2gpyma3jpx5yrxscokjn6qthbmmavfr2gy9xjfci8bfqupsi';
$apiUrl = 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send';

echo "=== Тест SMSProfi.ru Call Password API ===\n";
echo "API URL: $apiUrl\n";
echo "API Key: " . (empty($apiKey) ? 'НЕ УСТАНОВЛЕН' : substr($apiKey, 0, 8) . '...') . "\n\n";

if (empty($apiKey)) {
    echo "❌ ОШИБКА: API ключ не установлен!\n";
    echo "Установите SMSPROFI_API_KEY в .env файле\n";
    exit(1);
}

// Тестовые данные
$testPhone = '79991234567';
$requestId = 'test_call_' . time() . '_' . substr(md5($testPhone), 0, 8);

$requestData = [
    'recipient' => $testPhone,
    'id' => $requestId,
    'tags' => ['auth', 'callpassword']
];

echo "Отправляем запрос:\n";
echo "Телефон: $testPhone\n";
echo "ID запроса: $requestId\n";
echo "Данные: " . json_encode($requestData, JSON_PRETTY_PRINT) . "\n\n";

try {
    // Отправляем запрос (отключаем проверку SSL для тестирования)
    $response = Http::timeout(30)->withOptions([
        'verify' => false
    ])->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-Token' => $apiKey
    ])->post($apiUrl, $requestData);

    echo "=== ОТВЕТ ОТ SMSProfi.ru ===\n";
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
            echo "✅ УСПЕХ! Звонок отправлен\n";
            echo "Call ID: " . ($data['result']['id'] ?? 'N/A') . "\n";
            echo "Code: " . ($data['result']['code'] ?? 'N/A') . "\n";
        } else {
            echo "❌ ОШИБКА: " . ($data['error']['descr'] ?? 'Неизвестная ошибка') . "\n";
            echo "Error Code: " . ($data['error']['code'] ?? 'N/A') . "\n";
        }
    } else {
        echo "❌ HTTP ОШИБКА: " . $response->status() . "\n";
        echo "Error: " . ($data['error']['descr'] ?? 'Неизвестная ошибка') . "\n";
    }

} catch (Exception $e) {
    echo "❌ ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== КОНЕЦ ТЕСТА ===\n";
