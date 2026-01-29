<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Testing count calculation with selected items...\n";

// Тестируем запрос к API для подсчета строк
$url = 'http://localhost:8000/api/admin/shop/goods?for_export=1&count_only=1&selected_ids=90068,90069,90070';

echo "Requesting: $url\n";

try {
    // Имитируем запрос с заголовками авторизации
    $response = Http::withHeaders([
        'Authorization' => 'Bearer test-token', // Здесь должен быть настоящий токен
        'Accept' => 'application/json',
    ])->get($url);

    echo "Response status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}