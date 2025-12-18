<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Проверяем API /public/settings
$client = new \GuzzleHttp\Client([
    'base_uri' => 'http://localhost:8000',
    'timeout' => 10.0,
]);

try {
    $response = $client->get('/api/public/settings');
    $data = json_decode($response->getBody(), true);

    if ($data['success']) {
        echo 'API /public/settings response:' . PHP_EOL;
        echo 'tag_max_bonus: ' . ($data['data']['tag_max_bonus'] ?? 'NOT FOUND') . PHP_EOL;
        echo 'tag_max_bonus_tax: ' . ($data['data']['tag_max_bonus_tax'] ?? 'NOT FOUND') . PHP_EOL;
        echo 'tag_no_bonus: ' . ($data['data']['tag_no_bonus'] ?? 'NOT FOUND') . PHP_EOL;
    } else {
        echo 'API returned error' . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
