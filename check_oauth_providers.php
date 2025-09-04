<?php

/**
 * Скрипт для проверки настроек OAuth провайдеров
 * Запуск: php check_oauth_providers.php
 */

require_once __DIR__ . '/vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== ПРОВЕРКА OAuth ПРОВАЙДЕРОВ ===\n\n";

// Проверяем переменные окружения
echo "1. ПЕРЕМЕННЫЕ ОКРУЖЕНИЯ:\n";
echo "----------------------------------------\n";

$envVars = [
    'GOOGLE_CLIENT_ID' => env('GOOGLE_CLIENT_ID'),
    'GOOGLE_CLIENT_SECRET' => env('GOOGLE_CLIENT_SECRET'),
    'GOOGLE_REDIRECT_URI' => env('GOOGLE_REDIRECT_URI'),
    'VK_CLIENT_ID' => env('VK_CLIENT_ID'),
    'VK_CLIENT_SECRET' => env('VK_CLIENT_SECRET'),
    'VK_REDIRECT_URI' => env('VK_REDIRECT_URI'),
    'VK_APP_ID' => env('VK_APP_ID'),
    'VK_SDK_REDIRECT_URI' => env('VK_SDK_REDIRECT_URI'),
    'YANDEX_CLIENT_ID' => env('YANDEX_CLIENT_ID'),
    'YANDEX_CLIENT_SECRET' => env('YANDEX_CLIENT_SECRET'),
    'YANDEX_REDIRECT_URI' => env('YANDEX_REDIRECT_URI'),
    'FRONTEND_URL' => env('FRONTEND_URL'),
];

foreach ($envVars as $key => $value) {
    $status = $value ? '✅' : '❌';
    $displayValue = $value ? (strlen($value) > 20 ? substr($value, 0, 20) . '...' : $value) : 'НЕ УСТАНОВЛЕНО';
    echo "{$status} {$key}: {$displayValue}\n";
}

echo "\n2. КОНФИГУРАЦИЯ СЕРВИСОВ:\n";
echo "----------------------------------------\n";

$services = [
    'Google' => config('services.google'),
    'VKontakte' => config('services.vkontakte'),
    'Yandex' => config('services.yandex'),
];

foreach ($services as $name => $config) {
    echo "\n--- {$name} ---\n";
    if ($config) {
        foreach ($config as $key => $value) {
            $status = $value ? '✅' : '❌';
            $displayValue = $value ? (strlen($value) > 20 ? substr($value, 0, 20) . '...' : $value) : 'НЕ УСТАНОВЛЕНО';
            echo "{$status} {$key}: {$displayValue}\n";
        }
    } else {
        echo "❌ Конфигурация не найдена\n";
    }
}

echo "\n3. FRONTEND URL:\n";
echo "----------------------------------------\n";
$frontendUrl = config('app.frontend_url');
$status = $frontendUrl ? '✅' : '❌';
echo "{$status} Frontend URL: " . ($frontendUrl ?: 'НЕ УСТАНОВЛЕНО') . "\n";

echo "\n4. ПРОВЕРКА РОУТОВ:\n";
echo "----------------------------------------\n";

$routes = [
    'GET  /api/auth/google' => 'Google redirect',
    'GET  /api/auth/google/callback' => 'Google callback',
    'GET  /api/auth/vk' => 'VK redirect',
    'GET  /api/auth/vk/callback' => 'VK callback',
    'POST /api/auth/vk/sdk-callback' => 'VK SDK callback',
    'GET  /api/auth/yandex' => 'Yandex redirect',
    'GET  /api/auth/yandex/callback' => 'Yandex callback',
];

foreach ($routes as $route => $description) {
    echo "✅ {$route} - {$description}\n";
}

echo "\n5. РЕКОМЕНДАЦИИ:\n";
echo "----------------------------------------\n";

$issues = [];

if (!env('GOOGLE_CLIENT_ID')) {
    $issues[] = "❌ Google Client ID не установлен";
}
if (!env('VK_CLIENT_ID')) {
    $issues[] = "❌ VK Client ID не установлен";
}
if (!env('YANDEX_CLIENT_ID')) {
    $issues[] = "❌ Yandex Client ID не установлен";
}
if (!env('FRONTEND_URL')) {
    $issues[] = "❌ Frontend URL не установлен";
}

if (empty($issues)) {
    echo "✅ Все основные настройки выглядят корректно!\n";
    echo "✅ Проверьте настройки в консолях разработчиков провайдеров\n";
    echo "✅ Убедитесь, что redirect URI совпадают с настройками\n";
} else {
    echo "❌ Найдены проблемы:\n";
    foreach ($issues as $issue) {
        echo "   {$issue}\n";
    }
}

echo "\n6. КОМАНДЫ ДЛЯ ИСПРАВЛЕНИЯ:\n";
echo "----------------------------------------\n";
echo "php artisan config:clear\n";
echo "php artisan cache:clear\n";
echo "php artisan route:clear\n";

echo "\n=== ПРОВЕРКА ЗАВЕРШЕНА ===\n";
