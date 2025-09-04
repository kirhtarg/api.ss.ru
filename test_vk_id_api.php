<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== VK ID API Test ===\n\n";

// Тестовый id_token (замените на реальный)
$testIdToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpaXMiOiJWSyIsInN1YiI6NzkwNDY2MDM1LCJhcHAiOjU0MTE0NzE0LCJleHAiOjE3ODgxMjU0NTgsImlhdCI6MTc1NzAyMTQ1OCwianRpIjoyMX0.bdckAfIY8GmhLN2MdHlK-2PgofZMCzf4j6dxG1dDMARSupEJiGl7dwit09oxdHeQg1CC5pTYaVyeCtGaS9Aobje1GHJUl3yu6QIDoCXJ_GiBhyj1TD_kttM7Hw8K9SoO9Xvds-qSWpXKSyaprVC-rEorwmf9wC_5fGj0qZ0Hqp4cBkpWp6xdKb7fs1dJ2so_FLyPZfmN2z9MGBsyyeVZkgGbSzKbEGAvR835Xp_w6qBTpmhbtGfbMjsO7y4uMlIrxGyu3NuSRR7BCsuBiqWZYpxW13cs7NF0-cc9s578cY2vAbShoaiCWOvZ006PUPdlIXviXpfbHhBe5bXBpRnVuFLUVsazz2QYurfOC5ugFpuGQrmju6rvmxXKUjCkp6tcCOgeFs2nkmlqlUK1704rEun9XoSFbnnvlmQuxUKXdnNKTVsRp1Xmxp2wqNdk2U8uB-uYSizbfIXEh2tRq5T0oxh0Cjs4MMvl-65AVuKairYjQtcwT85njC2_HKZo8WTx-zvcpdwZlMK2mqzB-9z1RMDwp_t5PozrWAyh4KhSwkK5-u2M-awOFmrJ_QY0LnpbJc4uMs_VK3BiEcArwz8-6moktHiph2CvMbn1Qh4sfFIOsyeU_QhebDBxg-VMSQyvPAW6U5sT2cNKg5a_hsrY_XQvdbDdPuobkSkiQHDOLD8";

$clientId = config('services.vk.client_id');

echo "Client ID: $clientId\n";
echo "ID Token: " . substr($testIdToken, 0, 50) . "...\n\n";

// Тестируем VK ID API
echo "Testing VK ID API...\n";

try {
    $response = Http::asForm()->post('https://id.vk.com/oauth2/public_info', [
        'client_id' => $clientId,
        'id_token' => $testIdToken
    ]);
    
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n\n";
    
    if ($response->successful()) {
        $data = $response->json();
        echo "Parsed JSON:\n";
        print_r($data);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
