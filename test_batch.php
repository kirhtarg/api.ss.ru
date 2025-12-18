<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Проверяем batch API для товара 29764
$client = new \GuzzleHttp\Client([
    'base_uri' => 'http://localhost:8000',
    'timeout' => 10.0,
]);

try {
    $response = $client->post('/api/public/shop/goods/batch', [
        'json' => ['good_ids' => [29764]]
    ]);

    $data = json_decode($response->getBody(), true);

    if ($data['success'] && count($data['data']) > 0) {
        $good = $data['data'][0];
        echo 'Batch API - Good ID: ' . $good['id'] . PHP_EOL;
        echo 'Batch API - Has label: ' . (isset($good['label']) ? 'yes' : 'no') . PHP_EOL;
        if (isset($good['label'])) {
            echo 'Batch API - Label: ' . json_encode($good['label']) . PHP_EOL;
        }
    } else {
        echo 'No data returned from batch API' . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
