<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Проверяем товар 29764
$good = App\Models\ShopGood::with(['label'])->find(29764);
if ($good) {
    echo 'Good ID: ' . $good->id . PHP_EOL;
    echo 'Has label_id: ' . ($good->label_id ? 'yes (' . $good->label_id . ')' : 'no') . PHP_EOL;
    echo 'Label data: ' . ($good->label ? json_encode(['id' => $good->label->id, 'name' => $good->label->name, 'color' => $good->label->color]) : 'null') . PHP_EOL;
    echo 'Good is_active: ' . ($good->is_active ? 'yes' : 'no') . PHP_EOL;
} else {
    echo 'Good not found' . PHP_EOL;
}

// Также проверим публичный API
echo PHP_EOL . 'Testing public API...' . PHP_EOL;
$query = App\Models\ShopGood::with([
    'variations' => function($query) {
        $query->where('is_active', true);
    },
    'images' => function($query) {
        $query->whereNull('variation_id')->orderBy('sort_order');
    },
    'videos' => function($query) {
        $query->whereNull('variation_id')->orderBy('sort_order');
    },
    'properties' => function($query) {
        $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug')
            ->withPivot(['shop_property_value_id']);
    },
    'categories' => function($query) {
        $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
    },
    'brands' => function($query) {
        $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
    },
    'label' => function($query) {
        $query->select('shop_labels.id', 'shop_labels.name', 'shop_labels.color');
    },
])
->where('is_active', true)
->where('id', 29764);

$goods = $query->get();

if ($goods->count() > 0) {
    $good = $goods->first();
    echo 'Public API - Good ID: ' . $good->id . PHP_EOL;
    echo 'Public API - Has label: ' . ($good->label ? 'yes' : 'no') . PHP_EOL;
    if ($good->label) {
        echo 'Public API - Label: ' . json_encode(['id' => $good->label->id, 'name' => $good->label->name, 'color' => $good->label->color]) . PHP_EOL;
    }
} else {
    echo 'Good not found in public API (maybe not active)' . PHP_EOL;
}
