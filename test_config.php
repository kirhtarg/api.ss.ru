<?php

require_once 'vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Проверка конфигурации CallService ===\n";

// Проверяем конфигурацию
$callProvider = config('services.call.provider', 'voicepassword');
$smsprofiApiKey = config('services.smsprofi.api_key');
$smsprofiApiUrl = config('services.smsprofi.api_url', 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send');

echo "CALL_PROVIDER: $callProvider\n";
echo "SMSPROFI_API_KEY: " . (empty($smsprofiApiKey) ? 'НЕ УСТАНОВЛЕН' : substr($smsprofiApiKey, 0, 8) . '...') . "\n";
echo "SMSPROFI_API_URL: $smsprofiApiUrl\n\n";

if ($callProvider !== 'smsprofi') {
    echo "❌ ПРОБЛЕМА: CALL_PROVIDER не установлен в 'smsprofi'\n";
    echo "Установите CALL_PROVIDER=smsprofi в .env файле\n\n";
}

if (empty($smsprofiApiKey)) {
    echo "❌ ПРОБЛЕМА: SMSPROFI_API_KEY не установлен\n";
    echo "Установите SMSPROFI_API_KEY в .env файле\n\n";
}

// Тестируем CallService
echo "=== Тест CallService ===\n";

try {
    $callService = new \App\Services\CallService();
    
    // Используем рефлексию для получения приватных свойств
    $reflection = new ReflectionClass($callService);
    
    $providerProperty = $reflection->getProperty('provider');
    $providerProperty->setAccessible(true);
    $provider = $providerProperty->getValue($callService);
    
    $apiKeyProperty = $reflection->getProperty('apiKey');
    $apiKeyProperty->setAccessible(true);
    $apiKey = $apiKeyProperty->getValue($callService);
    
    $apiUrlProperty = $reflection->getProperty('apiUrl');
    $apiUrlProperty->setAccessible(true);
    $apiUrl = $apiUrlProperty->getValue($callService);
    
    echo "CallService Provider: $provider\n";
    echo "CallService API Key: " . (empty($apiKey) ? 'НЕ УСТАНОВЛЕН' : substr($apiKey, 0, 8) . '...') . "\n";
    echo "CallService API URL: $apiUrl\n\n";
    
    if ($provider !== 'smsprofi') {
        echo "❌ ПРОБЛЕМА: CallService использует провайдер '$provider' вместо 'smsprofi'\n";
    }
    
    if (empty($apiKey)) {
        echo "❌ ПРОБЛЕМА: CallService не имеет API ключа\n";
    }
    
    if ($apiUrl !== 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send') {
        echo "❌ ПРОБЛЕМА: CallService использует неправильный URL: $apiUrl\n";
    }
    
} catch (Exception $e) {
    echo "❌ ОШИБКА при создании CallService: " . $e->getMessage() . "\n";
}

echo "\n=== КОНЕЦ ПРОВЕРКИ ===\n";
