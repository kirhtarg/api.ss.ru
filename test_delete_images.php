<?php

require_once 'vendor/autoload.php';

use Illuminate\Http\Request;
use App\Http\Controllers\Admin\ShopGoodsController;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;

// Создаем тестовый запрос
$testRequest = new Request();

// Тестовые данные
$testData = [
    'ids' => [1], // ID товара для тестирования
    'action' => 'delete_images',
    'data' => [
        'delete_type' => 'goods' // 'goods', 'variations', 'goods_and_variations'
    ]
];

$testRequest->merge($testData);

// Создаем контроллер
$controller = new ShopGoodsController();

// Вызываем метод bulkUpdate
try {
    $response = $controller->bulkUpdate($testRequest);
    $result = json_decode($response->getContent(), true);

    echo "Результат теста:\n";
    echo "Success: " . ($result['success'] ? 'true' : 'false') . "\n";
    echo "Message: " . ($result['message'] ?? 'No message') . "\n";

    if (isset($result['errors'])) {
        echo "Errors: " . json_encode($result['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "\nТест завершен.\n";
