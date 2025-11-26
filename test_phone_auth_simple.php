<?php
require_once '/var/www/api.ss.ru/vendor/autoload.php';

$app = require_once '/var/www/api.ss.ru/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Auth\PhoneAuthController;
use Illuminate\Http\Request;

echo "=== ПРОСТОЙ ТЕСТ АВТОРИЗАЦИИ ПО ТЕЛЕФОНУ ===\n\n";

// Тестовый номер телефона
$phone = '+79991234599';

echo "1. Очищаем кеш для номера $phone\n";
Cache::forget("phone_code_$phone");

echo "2. Создаем контроллер\n";
$controller = new PhoneAuthController(new \App\Services\CallService());

echo "3. Тестируем отправку кода (реальный SMSProfi)\n";
$request = new Request(['phone' => $phone]);
$response = $controller->sendPhoneCode($request);
$responseData = json_decode($response->getContent(), true);

echo "   Ответ API: " . json_encode($responseData, JSON_UNESCAPED_UNICODE) . "\n\n";

if ($responseData['success']) {
    echo "4. Проверяем, что код сохранился в кеше\n";
    $cachedCode = Cache::get("phone_code_$phone");
    echo "   Код в кеше: $cachedCode\n\n";
    
    if ($cachedCode) {
        echo "5. Тестируем верификацию с правильным кодом: $cachedCode\n";
        $verifyRequest = new Request(['phone' => $phone, 'code' => $cachedCode]);
        $verifyResponse = $controller->verifyPhoneCode($verifyRequest);
        $verifyData = json_decode($verifyResponse->getContent(), true);
        
        echo "   Ответ верификации: " . json_encode($verifyData, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if ($verifyData['success']) {
            echo "✅ УСПЕХ! Авторизация работает!\n";
            echo "   👤 Пользователь: " . $verifyData['user']['name'] . "\n";
            echo "   📧 Email: " . $verifyData['user']['email'] . "\n";
            echo "   🖼️ Avatar: " . $verifyData['user']['avatar_url'] . "\n";
            echo "   🔑 Token: " . substr($verifyData['token'], 0, 20) . "...\n";
        } else {
            echo "❌ Ошибка верификации: " . $verifyData['message'] . "\n";
        }
    } else {
        echo "❌ Код не сохранился в кеше!\n";
    }
} else {
    echo "❌ Ошибка отправки кода: " . $responseData['message'] . "\n";
}

echo "\n6. Тестируем проверку статуса кода\n";
$statusRequest = new Request(['phone' => $phone]);
$statusResponse = $controller->checkCodeStatus($statusRequest);
$statusData = json_decode($statusResponse->getContent(), true);

echo "   Статус кода: " . json_encode($statusData, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "7. Тестируем верификацию с неверным кодом\n";
$wrongCodeRequest = new Request(['phone' => $phone, 'code' => '9999']);
$wrongCodeResponse = $controller->verifyPhoneCode($wrongCodeRequest);
$wrongCodeData = json_decode($wrongCodeResponse->getContent(), true);

echo "   Ответ с неверным кодом: " . json_encode($wrongCodeData, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== ТЕСТ ЗАВЕРШЕН ===\n";
echo "\n📝 ИНСТРУКЦИЯ ДЛЯ РЕАЛЬНОГО ТЕСТИРОВАНИЯ:\n";
echo "1. Откройте admin.skateandsnow.ru\n";
echo "2. Нажмите 'Войти по телефону'\n";
echo "3. Введите номер $phone\n";
echo "4. Введите код $cachedCode (если он есть в кеше)\n";
echo "5. Должна произойти успешная авторизация!\n";
