<?php

// Временный скрипт для проверки вариаций товара
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopGood;

$goodId = 109; // ID товара
$good = ShopGood::with(['variations:id,good_id,name,price,sale_price,stock_quantity,is_active'])
    ->where('id', $goodId)
    ->first();

if ($good) {
    echo "Good ID: " . $good->id . "\n";
    echo "Good Name: " . $good->name . "\n";
    echo "Variations count: " . $good->variations->count() . "\n\n";
    
    foreach ($good->variations as $variation) {
        echo "Variation ID: " . $variation->id . "\n";
        echo "Variation Name: " . $variation->name . "\n";
        echo "Is Active: " . ($variation->is_active ? 'true' : 'false') . "\n";
        echo "Price: " . $variation->price . "\n";
        echo "Stock: " . $variation->stock_quantity . "\n";
        echo "---\n";
    }
} else {
    echo "Good not found!\n";
}
