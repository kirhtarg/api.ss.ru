<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::capture());

use App\Models\ShopGoodVariation;
use Illuminate\Support\Facades\DB;

// Ищем вариацию без названия, но с атрибутами
$variation = ShopGoodVariation::whereHas('attributeValues')
    ->where(function($q) {
        $q->whereNull('name')->orWhere('name', '');
    })->first();

if (!$variation) {
    echo "No variations without names and with attributes found! Trying any variation with attributes...\n";
    $variation = ShopGoodVariation::whereHas('attributeValues')->first();
}

echo "Testing variation ID: " . $variation->id . "\n";
echo "Original variation name: " . ($variation->name ?? 'NULL') . "\n";

// Имитируем логику из контроллера
$variationName = $variation->name;
if (empty($variationName)) {
    $attributes = DB::table('shop_variation_attributes_values as vav')
        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
        ->where('vav.variation_id', $variation->id)
        ->select('a.name as attr_name', 'av.value as attr_value')
        ->get();

    if ($attributes->count() > 0) {
        $variationName = $attributes->map(function ($a) {
            return $a->attr_name . ': ' . $a->attr_value;
        })->implode(', ');
    }
}

echo "Fetched variation name: " . $variationName . "\n";
echo "Variation SKU: " . $variation->sku . "\n";
