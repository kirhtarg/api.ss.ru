<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$goods = DB::table('shop_goods')->whereIn('id', [2,3,4,6,7,33])->pluck('id')->toArray();
echo 'Существующие товары: ' . implode(', ', $goods) . PHP_EOL;
echo 'Всего товаров в БД: ' . DB::table('shop_goods')->count() . PHP_EOL;
?>
