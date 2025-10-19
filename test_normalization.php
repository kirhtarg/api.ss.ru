<?php

require_once 'vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "=== Тест нормализации номера ===\n";

// Тестируем разные форматы номеров
$testPhones = [
    '+79991234568',
    '79991234568',
    '89991234568',
    '+7 (999) 123-45-68'
];

foreach ($testPhones as $originalPhone) {
    echo "\n--- Тестируем: $originalPhone ---\n";
    
    // Симулируем нормализацию как в PhoneAuthController
    $phone = $originalPhone;
    
    // Убираем все символы кроме цифр и +
    $phone = preg_replace('/[^\d+]/', '', $phone);
    
    // Если номер начинается с 8, заменяем на +7
    if (str_starts_with($phone, '8')) {
        $phone = '+7' . substr($phone, 1);
    }
    
    // Если номер начинается с 7, добавляем +
    if (str_starts_with($phone, '7') && !str_starts_with($phone, '+7')) {
        $phone = '+' . $phone;
    }
    
    echo "Оригинал: $originalPhone\n";
    echo "Нормализован: $phone\n";
    
    // Проверяем, есть ли код в кеше
    $cacheKey = "phone_code_{$phone}";
    $cachedCode = Cache::get($cacheKey);
    
    if ($cachedCode) {
        echo "✅ Код найден: $cachedCode\n";
    } else {
        echo "❌ Код не найден\n";
    }
}

echo "\n=== КОНЕЦ ТЕСТА ===\n";
