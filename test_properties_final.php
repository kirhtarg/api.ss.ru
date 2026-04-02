<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ShopGood;
use Illuminate\Http\Request;

$goodId = 93201;
$goodModel = ShopGood::find($goodId);
if ($goodModel) {
    $request = Request::create("/api/public/shop/goods", "GET", ["limit" => 1, "search" => $goodModel->sku ?: $goodModel->name]);
    $controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true)["data"];
    
    foreach ($data as $good) {
        if ($good["id"] == $goodId) {
            echo "Good: " . $good["name"] . "\n";
            echo "  Properties:\n";
            foreach ($good["properties"] as $prop) {
                $value = isset($prop["value"]) ? $prop["value"] : "MISSING";
                echo "    - " . $prop["name"] . ": " . $value . "\n";
            }
        }
    }
}

