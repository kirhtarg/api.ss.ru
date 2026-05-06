<?php

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ShopGood;
use App\Services\AvitoFeedService;

// Находим товар с вариациями для теста
$good = ShopGood::has('variations')->with('variations')->first();

if (!$good) {
    echo "No goods with variations found for test.\n";
    exit;
}

$service = app(AvitoFeedService::class);

echo "Testing Good ID: {$good->id} ({$good->name})\n";
echo "Main Good Stock: {$good->stock_quantity}, Remote: {$good->remote_stock_quantity}, Fast: {$good->fast_remote_stock_quantity}\n";
echo "Main Good Price: {$good->price}\n\n";

foreach ($good->variations as $v) {
    echo "Variation ID: {$v->id}, Name: {$v->name}\n";
    echo "Stock: {$v->stock_quantity}, Remote: {$v->remote_stock_quantity}, Fast: {$v->fast_remote_stock_quantity}\n";
    echo "Price: {$v->price}, Sale: {$v->sale_price}\n";
    echo "Is Active: " . ($v->is_active ? 'Yes' : 'No') . "\n";
    echo "-------------------\n";
}

// Вызываем приватный метод через Reflection для теста
$reflection = new \ReflectionClass(get_class($service));
$method = $reflection->getMethod('getMinPrice');
$method->setAccessible(true);

$minPrice = $method->invoke($service, $good);
echo "\nCalculated Min Price for Avito: {$minPrice}\n";

// Тестируем логику isInStock отдельно
$isInStockMethod = $reflection->getMethod('isInStock');
$isInStockMethod->setAccessible(true);

echo "\nStock Check for Main Good: " . ($isInStockMethod->invoke($service, $good) ? 'IN STOCK' : 'OUT OF STOCK') . "\n";
foreach ($good->variations as $v) {
    echo "Stock Check for Var {$v->id}: " . ($isInStockMethod->invoke($service, $v) ? 'IN STOCK' : 'OUT OF STOCK') . "\n";
}
