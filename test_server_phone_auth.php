<?php
require_once '/var/www/api.ss.ru/vendor/autoload.php';

$app = require_once '/var/www/api.ss.ru/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Auth\PhoneAuthController;
use Illuminate\Http\Request;

echo "=== Тест авторизации по телефону на сервере ===\n\n";

$phone = '+79991234580';
$testCode = '1234';

echo "1. Очищаем кеш для номера $phone\n";
Cache::forget("phone_code_$phone");

echo "2. Отправляем код на номер $phone\n";
$controller = new PhoneAuthController(new \App\Services\CallService());
$request = new Request(['phone' => $phone]);
$response = $controller->sendPhoneCode($request);
$responseData = json_decode($response->getContent(), true);

echo "Ответ отправки кода: " . json_encode($responseData, JSON_UNESCAPED_UNICODE) . "\n\n";

if ($responseData['success']) {
    echo "3. Проверяем, что код сохранился в кеше\n";
    $cachedCode = Cache::get("phone_code_$phone");
    echo "Код в кеше: $cachedCode\n\n";
    
    if ($cachedCode) {
        echo "4. Тестируем верификацию с правильным кодом: $cachedCode\n";
        $verifyRequest = new Request(['phone' => $phone, 'code' => $cachedCode]);
        $verifyResponse = $controller->verifyPhoneCode($verifyRequest);
        $verifyData = json_decode($verifyResponse->getContent(), true);
        
        echo "Ответ верификации: " . json_encode($verifyData, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if ($verifyData['success']) {
            echo "✅ УСПЕХ! Авторизация работает!\n";
            echo "Пользователь: " . $verifyData['user']['name'] . "\n";
            echo "Email: " . $verifyData['user']['email'] . "\n";
            echo "Avatar: " . $verifyData['user']['avatar_url'] . "\n";
        } else {
            echo "❌ Ошибка верификации: " . $verifyData['message'] . "\n";
        }
    } else {
        echo "❌ Код не сохранился в кеше!\n";
    }
} else {
    echo "❌ Ошибка отправки кода: " . $responseData['message'] . "\n";
}

echo "\n=== Тест завершен ===\n";
