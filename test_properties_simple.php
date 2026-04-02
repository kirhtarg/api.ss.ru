<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ShopGood;
use Illuminate\Http\Request;

$request = Request::create("/api/public/shop/goods", "GET", ["limit" => 10]);
$controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);
$response = $controller->index($request);
$data = json_decode($response->getContent(), true)["data"];

foreach ($data as $good) {
    if (isset($good["properties"]) && count($good["properties"]) > 0) {
        echo "Good: " . $good["name"] . "\n";
        foreach ($good["properties"] as $p) {
             echo "  - " . $p["name"] . ": " . ($p["value"] ?? "NULL") . "\n";
        }
        break; // Show only one for brevity
    }
}

