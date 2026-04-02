<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ShopGood;
use Illuminate\Http\Request;

echo "Searching for goods with variations and properties...\n";

$controller = app()->make(\App\Http\Controllers\Api\Public\ShopGoodsController::class);

// Try to find any good with variations
$goodWithVariations = ShopGood::has("variations")->first();
if ($goodWithVariations) {
    echo "Found good with variations: ID " . $goodWithVariations->id . "\n";
    $request = Request::create("/api/public/shop/goods", "GET", ["limit" => 1, "search" => $goodWithVariations->name]);
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true)["data"];
    
    foreach ($data as $good) {
        if ($good["id"] == $goodWithVariations->id) {
            echo "Good: " . $good["name"] . "\n";
            echo "  Variations:\n";
            foreach ($good["variations"] as $var) {
                echo "    Var ID: " . $var["id"] . " | Price: " . $var["price"] . "\n";
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
    }
} else {
    echo "No goods with variations found in DB.\n";
}

// Try to find any good with properties
$goodWithProps = ShopGood::has("properties")->first();
if ($goodWithProps) {
    echo "\nFound good with properties: ID " . $goodWithProps->id . "\n";
    $request = Request::create("/api/public/shop/goods", "GET", ["limit" => 1, "search" => $goodWithProps->sku ?: $goodWithProps->name]);
    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true)["data"];
    
    foreach ($data as $good) {
        if ($good["id"] == $goodWithProps->id) {
            echo "Good: " . $good["name"] . "\n";
            echo "  Properties:\n";
            foreach ($good["properties"] as $prop) {
                $value = isset($prop["value"]) ? $prop["value"] : "MISSING";
                echo "    - " . $prop["name"] . ": " . $value . "\n";
            }
        }
    }
} else {
    echo "No goods with properties found in DB.\n";
}

