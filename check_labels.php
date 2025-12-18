<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'Labels:' . PHP_EOL;
$labels = App\Models\ShopLabel::all();
foreach($labels as $label) {
    echo $label->id . ': ' . $label->name . ' (color: ' . $label->color . ')' . PHP_EOL;
}

echo PHP_EOL . 'Goods with labels:' . PHP_EOL;
$goods = App\Models\ShopGood::with('label')->whereNotNull('label_id')->get();
foreach($goods as $good) {
    echo $good->id . ': ' . ($good->label ? $good->label->name : 'no label') . PHP_EOL;
}

echo PHP_EOL . 'Total goods: ' . App\Models\ShopGood::count() . PHP_EOL;
echo 'Goods with labels: ' . App\Models\ShopGood::whereNotNull('label_id')->count() . PHP_EOL;
