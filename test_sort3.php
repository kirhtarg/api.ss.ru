<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$row = \Illuminate\Support\Facades\DB::table("shop_good_categories")->find(1) ?? \Illuminate\Support\Facades\DB::table("shop_good_categories")->first();
if (!$row) die("no rows");
$catId = $row->category_id;

$request = Illuminate\Http\Request::create("/api/public/shop/goods", "GET", ["sort_by" => "price", "sort_order" => "desc", "limit" => 5, "categories" => [$catId]]); 
$controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);
$response = $controller->index($request);
$data = json_decode($response->getContent(), true)["data"];
if (!$data) { echo "No data!\n"; }
foreach ($data as $item) {
    if (isset($item["price"])) {
        echo $item["id"] . " - " . mb_substr($item["name"], 0, 30) . " - " . $item["price"] . "\n";
    }
}

