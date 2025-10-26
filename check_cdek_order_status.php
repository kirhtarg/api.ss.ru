<?php

require_once 'vendor/autoload.php';

use App\Services\CdekService;
use App\Models\ShopCdekSettings;

// Загружаем настройки Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== ПРОВЕРКА СТАТУСА ЗАКАЗА СДЭК ===\n\n";

// UUID последнего заказа из логов
$orderUuid = 'bb244458-b9fd-4dc6-99e8-6fc5da9a5b93';

echo "UUID заказа: {$orderUuid}\n";
echo "API URL: https://api.cdek.ru/v2\n\n";

// Получаем настройки СДЭК
$settings = ShopCdekSettings::where('is_active', true)->first();

if (!$settings) {
    echo "❌ Ошибка: Настройки СДЭК не найдены\n";
    exit(1);
}

echo "Client ID: {$settings->client_id}\n";
echo "Client Secret: " . substr($settings->client_secret, 0, 10) . "...\n\n";

// Создаем сервис СДЭК
$cdekService = new CdekService($settings);

echo "=== ПОЛУЧЕНИЕ СТАТУСА ЗАКАЗА ===\n";

$result = $cdekService->getOrderStatus($orderUuid);

if ($result['success']) {
    echo "✅ Статус заказа получен успешно\n";
    $data = $result['data'];
    
    echo "Номер заказа: " . ($data['entity']['number'] ?? 'N/A') . "\n";
    echo "UUID: " . ($data['entity']['uuid'] ?? 'N/A') . "\n";
    echo "Тариф: " . ($data['entity']['tariff_code'] ?? 'N/A') . "\n\n";
    
    echo "=== СТАТУСЫ ЗАКАЗА ===\n";
    if (isset($data['entity']['statuses']) && is_array($data['entity']['statuses'])) {
        foreach ($data['entity']['statuses'] as $status) {
            echo "• {$status['name']} ({$status['code']}) - {$status['date_time']}\n";
        }
    }
    
    echo "\n=== ЗАПРОСЫ ===\n";
    if (isset($data['requests']) && is_array($data['requests'])) {
        foreach ($data['requests'] as $request) {
            echo "• {$request['type']} - {$request['state']} ({$request['date_time']})\n";
            if (isset($request['errors']) && is_array($request['errors'])) {
                foreach ($request['errors'] as $error) {
                    echo "  ❌ Ошибка: {$error['message']} (код: {$error['code']})\n";
                }
            }
        }
    }
    
    // Проверяем, есть ли ошибки
    $hasErrors = false;
    if (isset($data['requests']) && is_array($data['requests'])) {
        foreach ($data['requests'] as $request) {
            if (isset($request['errors']) && is_array($request['errors']) && count($request['errors']) > 0) {
                $hasErrors = true;
                break;
            }
        }
    }
    
    if (!$hasErrors) {
        echo "\n🎉 ЗАКАЗ СОЗДАН УСПЕШНО! Проверьте боевой кабинет СДЭК.\n";
        echo "🔗 Ссылка: https://lk.cdek.ru/\n";
    } else {
        echo "\n⚠️ Заказ создан, но есть ошибки валидации.\n";
    }
    
} else {
    echo "❌ Ошибка получения статуса заказа: {$result['message']}\n";
}

echo "\n=== ПРОВЕРКА ЗАВЕРШЕНА ===\n";
