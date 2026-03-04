<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$orders = App\Models\ShopOrder::latest()->limit(5)->get();
foreach ($orders as $order) {
    echo "Order ID: " . $order->id . " Num: " . $order->order_number . "\n";
    echo "Items: " . json_encode($order->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}
