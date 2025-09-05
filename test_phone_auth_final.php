<?php

require_once 'vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

echo "=== ФИНАЛЬНЫЙ ТЕСТ АВТОРИЗАЦИИ ПО ТЕЛЕФОНУ ===\n";

$testPhone = '+79991234578';

echo "Телефон: $testPhone\n\n";

// Шаг 1: Отправляем код
echo "--- ШАГ 1: Отправка кода ---\n";
try {
    $response = Http::timeout(30)->withOptions([
        'verify' => false
    ])->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ])->post('https://ss75-api.kirhtarg.ru/api/phone/send-code', [
        'phone' => $testPhone
    ]);

    echo "HTTP Status: " . $response->status() . "\n";
    $data = $response->json();
    echo "Response: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if (!$response->successful() || !$data['success']) {
        echo "❌ ОШИБКА при отправке кода\n";
        exit(1);
    }

    echo "✅ Код отправлен успешно\n\n";

} catch (Exception $e) {
    echo "❌ ИСКЛЮЧЕНИЕ при отправке кода: " . $e->getMessage() . "\n";
    exit(1);
}

// Шаг 2: Получаем код из кеша
echo "--- ШАГ 2: Получение кода из кеша ---\n";
$cacheKey = "phone_code_{$testPhone}";
$cachedCode = Cache::get($cacheKey);

if (!$cachedCode) {
    echo "❌ Код не найден в кеше\n";
    exit(1);
}

echo "✅ Код найден в кеше: $cachedCode\n\n";

// Шаг 3: Верифицируем код
echo "--- ШАГ 3: Верификация кода ---\n";
try {
    $response = Http::timeout(30)->withOptions([
        'verify' => false
    ])->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ])->post('https://ss75-api.kirhtarg.ru/api/phone/verify-code', [
        'phone' => $testPhone,
        'code' => $cachedCode
    ]);

    echo "HTTP Status: " . $response->status() . "\n";
    $data = $response->json();
    echo "Response: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if ($response->successful() && $data['success']) {
        echo "🎉 АВТОРИЗАЦИЯ ПО ТЕЛЕФОНУ РАБОТАЕТ!\n\n";
        
        $user = $data['user'];
        echo "=== ДАННЫЕ ПОЛЬЗОВАТЕЛЯ ===\n";
        echo "ID: " . ($user['id'] ?? 'N/A') . "\n";
        echo "Name: " . ($user['name'] ?? 'N/A') . "\n";
        echo "Email: " . ($user['email'] ?? 'N/A') . "\n";
        echo "Phone: " . ($user['phone'] ?? 'N/A') . "\n";
        echo "Avatar URL: " . ($user['avatar_url'] ?? 'N/A') . "\n";
        echo "Role: " . ($user['role'] ?? 'N/A') . "\n";
        echo "Is Active: " . ($user['is_active'] ? 'ДА' : 'НЕТ') . "\n";
        echo "Token: " . (isset($data['token']) ? 'ЕСТЬ' : 'НЕТ') . "\n\n";
        
        // Проверяем, что данные правильные
        $checks = [
            'name' => $user['name'] === $testPhone,
            'avatar_url' => $user['avatar_url'] === '/ph.png',
            'phone' => $user['phone'] === $testPhone,
            'is_active' => $user['is_active'] === true,
            'token' => isset($data['token'])
        ];
        
        echo "=== ПРОВЕРКИ ===\n";
        foreach ($checks as $check => $passed) {
            echo ($passed ? '✅' : '❌') . " $check: " . ($passed ? 'ПРОЙДЕНО' : 'НЕ ПРОЙДЕНО') . "\n";
        }
        
        $allPassed = array_reduce($checks, function($carry, $item) {
            return $carry && $item;
        }, true);
        
        echo "\n" . ($allPassed ? '🎉 ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ!' : '❌ ЕСТЬ ОШИБКИ') . "\n";
        
    } else {
        echo "❌ ОШИБКА при верификации кода\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
        if (isset($data['error'])) {
            echo "Error: " . $data['error'] . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ ИСКЛЮЧЕНИЕ при верификации: " . $e->getMessage() . "\n";
}

echo "\n=== КОНЕЦ ФИНАЛЬНОГО ТЕСТА ===\n";
