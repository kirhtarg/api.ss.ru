<?php
/**
 * Проверка VK OAuth на сервере
 * Запуск: php server_vk_check.php
 */

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

echo "=== ПРОВЕРКА VK OAUTH НА СЕРВЕРЕ ===\n\n";

// 1. Проверяем конфигурацию
echo "1. КОНФИГУРАЦИЯ:\n";
$clientId = config('services.vkontakte.client_id');
$clientSecret = config('services.vkontakte.client_secret');
$redirectUri = config('services.vkontakte.redirect');

echo "Client ID: " . ($clientId ?: 'НЕ НАСТРОЕН') . "\n";
echo "Client Secret: " . ($clientSecret ? 'настроен' : 'НЕ НАСТРОЕН') . "\n";
echo "Redirect URI: " . ($redirectUri ?: 'НЕ НАСТРОЕН') . "\n\n";

// 2. Проверяем .env файл
echo "2. ПРОВЕРКА .env ФАЙЛА:\n";
$envFile = base_path('.env');
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    $vkClientId = null;
    $vkRedirectUri = null;
    
    foreach (explode("\n", $envContent) as $line) {
        if (strpos($line, 'VK_CLIENT_ID=') === 0) {
            $vkClientId = trim(substr($line, 13));
        }
        if (strpos($line, 'VK_REDIRECT_URI=') === 0) {
            $vkRedirectUri = trim(substr($line, 16));
        }
    }
    
    echo "VK_CLIENT_ID в .env: " . ($vkClientId ?: 'НЕ НАЙДЕН') . "\n";
    echo "VK_REDIRECT_URI в .env: " . ($vkRedirectUri ?: 'НЕ НАЙДЕН') . "\n";
} else {
    echo "❌ Файл .env не найден!\n";
}
echo "\n";

// 3. Проверяем API endpoint
echo "3. ПРОВЕРКА API ENDPOINT:\n";
try {
    $response = Http::timeout(10)->get('https://ss75-api.kirhtarg.ru/api/auth/vk/url');
    echo "Статус: " . $response->status() . "\n";
    if ($response->status() === 200) {
        $data = $response->json();
        echo "Ответ: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Ошибка: " . $response->body() . "\n";
    }
} catch (Exception $e) {
    echo "Ошибка подключения: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Генерируем тестовый URL
echo "4. ТЕСТОВЫЙ URL:\n";
$url = "https://oauth.vk.com/authorize?" . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'email',
    'response_type' => 'code',
    'v' => '5.131'
]);
echo $url . "\n\n";

// 5. Проверяем кэш
echo "5. ПРОВЕРКА КЭША:\n";
$configCache = base_path('bootstrap/cache/config.php');
$routeCache = base_path('bootstrap/cache/routes-v7.php');
echo "Config cache: " . (file_exists($configCache) ? 'существует' : 'не существует') . "\n";
echo "Route cache: " . (file_exists($routeCache) ? 'существует' : 'не существует') . "\n\n";

echo "=== КОНЕЦ ПРОВЕРКИ ===\n";
