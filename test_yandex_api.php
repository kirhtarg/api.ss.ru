<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Загружаем конфигурацию Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== YANDEX API TEST SCRIPT ===\n\n";

// Получаем конфигурацию Yandex
$clientId = config('services.yandex.client_id');
$clientSecret = config('services.yandex.client_secret');
$redirectUri = config('services.yandex.redirect');

echo "Client ID: " . $clientId . "\n";
echo "Client Secret: " . substr($clientSecret, 0, 10) . "...\n";
echo "Redirect URI: " . $redirectUri . "\n\n";

// Проверяем, есть ли код авторизации в параметрах
if (!isset($_GET['code'])) {
    echo "❌ Код авторизации не найден в URL\n";
    echo "Для тестирования перейдите по ссылке:\n";
    echo "https://oauth.yandex.ru/authorize?" . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'login:email login:info login:avatar',
    ]) . "\n\n";
    echo "После авторизации вы будете перенаправлены на:\n";
    echo $redirectUri . "?code=КОД_АВТОРИЗАЦИИ\n\n";
    echo "Скопируйте код из URL и добавьте его к этому скрипту:\n";
    echo "php test_yandex_api.php?code=КОД_АВТОРИЗАЦИИ\n";
    exit;
}

$code = $_GET['code'];
echo "✅ Код авторизации получен: " . $code . "\n\n";

try {
    // Получаем токен
    echo "🔄 Получаем токен доступа...\n";
    $tokenResponse = Http::asForm()->post('https://oauth.yandex.ru/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);

    if (!$tokenResponse->successful()) {
        echo "❌ Ошибка получения токена:\n";
        echo "Status: " . $tokenResponse->status() . "\n";
        echo "Response: " . $tokenResponse->body() . "\n";
        exit;
    }

    $tokenData = $tokenResponse->json();
    echo "✅ Токен получен успешно\n";
    echo "Access Token: " . substr($tokenData['access_token'], 0, 20) . "...\n\n";

    // Получаем данные пользователя
    echo "🔄 Получаем данные пользователя...\n";
    $userResponse = Http::withHeaders([
        'Authorization' => 'OAuth ' . $tokenData['access_token']
    ])->get('https://login.yandex.ru/info');

    if (!$userResponse->successful()) {
        echo "❌ Ошибка получения данных пользователя:\n";
        echo "Status: " . $userResponse->status() . "\n";
        echo "Response: " . $userResponse->body() . "\n";
        exit;
    }

    $yandexUser = $userResponse->json();
    echo "✅ Данные пользователя получены\n\n";

    // Получаем расширенные данные
    echo "🔄 Получаем расширенные данные...\n";
    $extendedUserResponse = Http::withHeaders([
        'Authorization' => 'OAuth ' . $tokenData['access_token']
    ])->get('https://api-yaru.yandex.ru/me');

    $extendedUserData = null;
    if ($extendedUserResponse->successful()) {
        $extendedUserData = $extendedUserResponse->json();
        echo "✅ Расширенные данные получены\n";
    } else {
        echo "⚠️ Расширенные данные не получены (Status: " . $extendedUserResponse->status() . ")\n";
    }

    // Получаем данные от Yandex ID
    echo "🔄 Получаем данные от Yandex ID...\n";
    $yandexIdResponse = Http::withHeaders([
        'Authorization' => 'OAuth ' . $tokenData['access_token']
    ])->get('https://id.yandex.ru/info');

    $yandexIdData = null;
    if ($yandexIdResponse->successful()) {
        $yandexIdData = $yandexIdResponse->json();
        echo "✅ Данные от Yandex ID получены\n";
    } else {
        echo "⚠️ Данные от Yandex ID не получены (Status: " . $yandexIdResponse->status() . ")\n";
    }

    echo "\n=== РЕЗУЛЬТАТЫ ===\n\n";

    // Объединяем данные
    $mergedData = $yandexUser;
    if ($extendedUserData && !isset($extendedUserData['error'])) {
        $mergedData = array_merge($mergedData, $extendedUserData);
    }
    if ($yandexIdData && !isset($yandexIdData['error'])) {
        $mergedData = array_merge($mergedData, $yandexIdData);
    }

    // Выводим все данные
    echo "📋 ОСНОВНЫЕ ДАННЫЕ (login.yandex.ru/info):\n";
    echo json_encode($yandexUser, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if ($extendedUserData) {
        echo "📋 РАСШИРЕННЫЕ ДАННЫЕ (api-yaru.yandex.ru/me):\n";
        echo json_encode($extendedUserData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    if ($yandexIdData) {
        echo "📋 ДАННЫЕ ОТ YANDEX ID (id.yandex.ru/info):\n";
        echo json_encode($yandexIdData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    echo "📋 ОБЪЕДИНЕННЫЕ ДАННЫЕ:\n";
    echo json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // Ищем поля для телефона и даты рождения
    echo "🔍 ПОИСК ПОЛЕЙ ДЛЯ ТЕЛЕФОНА И ДАТЫ РОЖДЕНИЯ:\n\n";

    $phoneFields = ['phone', 'default_phone', 'mobile_phone', 'phone_number', 'mobile', 'tel'];
    $birthdayFields = ['birthday', 'birth_date', 'date_of_birth', 'birthday_date'];

    echo "📞 ПОЛЯ ДЛЯ ТЕЛЕФОНА:\n";
    foreach ($phoneFields as $field) {
        if (isset($mergedData[$field]) && !empty($mergedData[$field])) {
            echo "✅ $field: " . $mergedData[$field] . "\n";
        } else {
            echo "❌ $field: не найдено\n";
        }
    }

    echo "\n🎂 ПОЛЯ ДЛЯ ДАТЫ РОЖДЕНИЯ:\n";
    foreach ($birthdayFields as $field) {
        if (isset($mergedData[$field]) && !empty($mergedData[$field])) {
            echo "✅ $field: " . $mergedData[$field] . "\n";
        } else {
            echo "❌ $field: не найдено\n";
        }
    }

    echo "\n📋 ВСЕ ДОСТУПНЫЕ ПОЛЯ:\n";
    foreach ($mergedData as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            echo "• $key: " . $value . "\n";
        } elseif (is_array($value)) {
            echo "• $key: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "• $key: " . gettype($value) . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== КОНЕЦ ТЕСТА ===\n";
