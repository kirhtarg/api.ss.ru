<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Testing final selected items count...\n";

// Создаем HTTP клиент с правильными заголовками (имитируем фронтенд)
$token = 'your-token-here'; // В реальности токен берется из localStorage

// Тестируем запрос на подсчет строк с selected_ids
$url = 'http://localhost:8000/api/admin/shop/goods?for_export=1&count_only=1&selected_ids=93201,93202,93203';

echo "Requesting count for selected items: $url\n";

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ])->get($url);

    echo "Status: " . $response->status() . "\n";
    $data = $response->json();

    if (isset($data['count'])) {
        echo "✅ Count returned: " . $data['count'] . "\n";
        echo "Expected: 2 (since 3 items have variations)\n";
        if ($data['count'] == 2) {
            echo "✅ Test PASSED!\n";
        } else {
            echo "❌ Test FAILED!\n";
        }
    } else {
        echo "❌ No count in response\n";
        print_r($data);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}