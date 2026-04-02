<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create("/api/public/shop/goods", "GET", ["sort_by" => "price", "sort_order" => "asc", "limit" => 10, "categories" => [242]]); // category 242
$controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);
$response = $controller->index($request);
$data = json_decode($response->getContent(), true)["data"];
if (!$data) { echo "No data!\n"; }
foreach ($data as $item) {
    if (isset($item["price"])) {
        echo $item["id"] . " - " . $item["name"] . " - " . $item["price"] . "\n";
    }
}

