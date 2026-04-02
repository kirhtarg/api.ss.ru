<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ShopGood;
use Illuminate\Http\Request;

echo "Testing enriched API response for goods...\n";

$request = Request::create("/api/public/shop/goods", "GET", ["limit" => 5]); 
$controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);
$response = $controller->index($request);
$data = json_decode($response->getContent(), true)["data"];

foreach ($data as $good) {
    echo "Good ID: " . $good["id"] . " | " . $good["name"] . "\n";
    
    // Check product properties
    echo "  Properties:\n";
    if (isset($good["properties"])) {
        foreach ($good["properties"] as $prop) {
            $value = isset($prop["value"]) ? $prop["value"] : "MISSING";
            echo "    - " . $prop["name"] . ": " . $value . "\n";
        }
    }
    
    // Check variations
    echo "  Variations:\n";
    if (isset($good["variations"])) {
        foreach ($good["variations"] as $var) {
            echo "    Var ID: " . $var["id"] . "\n";
            if (isset($var["properties"])) {
                foreach ($var["properties"] as $vProp) {
                    $attrName = isset($vProp["property"]["name"]) ? $vProp["property"]["name"] : "ATTR_MISSING";
                    echo "      - " . $attrName . ": " . $vProp["value"] . "\n";
                }
            } else {
                echo "      NO VARIATION PROPERTIES DATA\n";
            }
        }
    }
    echo "\n";
}

