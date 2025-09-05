<?php
require_once '/var/www/api.ss.ru/vendor/autoload.php';

$app = require_once '/var/www/api.ss.ru/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Auth\PhoneAuthController;
use Illuminate\Http\Request;

echo "=== ПОЛНЫЙ ТЕСТ АВТОРИЗАЦИИ ПО ТЕЛЕФОНУ ===\n\n";

// Тестовый номер телефона
$phone = '+79991234599';

echo "1. Очищаем кеш для номера $phone\n";
Cache::forget("phone_code_$phone");

echo "2. Тестируем отправку кода (с фейковым SMSProfi)\n";

// Создаем фейковый код заранее
$fakeCode = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
echo "   📞 Фейковый звонок отправлен на $phone\n";
echo "   🔢 Код в звонке: $fakeCode\n";

// Создаем контроллер с реальным CallService
$controller = new PhoneAuthController(new \App\Services\CallService());

// Временно перехватываем вызов sendCallCode через рефлексию
$reflection = new ReflectionClass($controller);
$callServiceProperty = $reflection->getProperty('callService');
$callServiceProperty->setAccessible(true);

// Создаем мок-объект
$mockCallService = new class($fakeCode) {
    private $fakeCode;
    
    public function __construct($fakeCode) {
        $this->fakeCode = $fakeCode;
    }
    
    public function sendCallCode($phone, $code) {
        return [
            'success' => true,
            'message' => 'Звонок отправлен',
            'data' => [
                'call_id' => 'fake_call_' . time(),
                'code' => $this->fakeCode,
                'mobile_operator' => 'Test'
            ]
        ];
    }
};

// Заменяем CallService на мок
$callServiceProperty->setValue($controller, $mockCallService);

$request = new Request(['phone' => $phone]);
$response = $controller->sendPhoneCode($request);
$responseData = json_decode($response->getContent(), true);

echo "   Ответ API: " . json_encode($responseData, JSON_UNESCAPED_UNICODE) . "\n\n";

if ($responseData['success']) {
    echo "3. Проверяем, что код сохранился в кеше\n";
    $cachedCode = Cache::get("phone_code_$phone");
    echo "   Код в кеше: $cachedCode\n\n";
    
    if ($cachedCode) {
        echo "4. Тестируем верификацию с правильным кодом: $cachedCode\n";
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

echo "\n5. Тестируем проверку статуса кода\n";
$statusRequest = new Request(['phone' => $phone]);
$statusResponse = $controller->checkCodeStatus($statusRequest);
$statusData = json_decode($statusResponse->getContent(), true);

echo "   Статус кода: " . json_encode($statusData, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "6. Тестируем верификацию с неверным кодом\n";
$wrongCodeRequest = new Request(['phone' => $phone, 'code' => '9999']);
$wrongCodeResponse = $controller->verifyPhoneCode($wrongCodeRequest);
$wrongCodeData = json_decode($wrongCodeResponse->getContent(), true);

echo "   Ответ с неверным кодом: " . json_encode($wrongCodeData, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== ТЕСТ ЗАВЕРШЕН ===\n";
