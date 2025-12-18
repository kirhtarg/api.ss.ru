<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'Checking for nobonus tag:' . PHP_EOL;
$goodsWithNoBonus = DB::table('shop_goods')
    ->join('shop_good_tags', 'shop_goods.id', '=', 'shop_good_tags.good_id')
    ->join('shop_tags', 'shop_good_tags.tag_id', '=', 'shop_tags.id')
    ->where('shop_tags.name', 'nobonus')
    ->select('shop_goods.id', 'shop_goods.name', 'shop_tags.name as tag_name')
    ->get();

foreach ($goodsWithNoBonus as $good) {
    echo 'Good ID: ' . $good->id . ' - ' . $good->name . ' - Tag: ' . $good->tag_name . PHP_EOL;
}

echo PHP_EOL . 'Checking for maxbonus tag:' . PHP_EOL;
$goodsWithMaxBonus = DB::table('shop_goods')
    ->join('shop_good_tags', 'shop_goods.id', '=', 'shop_good_tags.good_id')
    ->join('shop_tags', 'shop_good_tags.tag_id', '=', 'shop_tags.id')
    ->where('shop_tags.name', 'maxbonus')
    ->select('shop_goods.id', 'shop_goods.name', 'shop_tags.name as tag_name')
    ->get();

foreach ($goodsWithMaxBonus as $good) {
    echo 'Good ID: ' . $good->id . ' - ' . $good->name . ' - Tag: ' . $good->tag_name . PHP_EOL;
}
