<?php

// Debug script for stock filter

require_once __DIR__.'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// Setup database connection like Laravel does
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'ss_db'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "=== STOCK FILTER DEBUG ===\n\n";

// Test different filter scenarios
echo "1. Testing 'IN STOCK' filter (stock_variations_not_empty=1, stock_goods_not_empty=1):\n";

$query = Capsule::table('shop_goods')
    ->select('id', 'name', 'stock_quantity', 'supplier')
    ->where(function ($mainQuery) {
        // Products with variations - at least one variation with stock > 0
        $mainQuery->whereExists(function ($subQuery) {
            $subQuery->select(Capsule::raw(1))
                ->from('shop_good_variations')
                ->whereRaw('shop_good_variations.good_id = shop_goods.id')
                ->where('stock_quantity', '>', 0);
        })
        // Products without variations - main product with stock > 0
            ->orWhere(function ($noVariationsQuery) {
                $noVariationsQuery->whereNotExists(function ($subQuery) {
                    $subQuery->select(Capsule::raw(1))
                        ->from('shop_good_variations')
                        ->whereRaw('shop_good_variations.good_id = shop_goods.id');
                })
                    ->where('stock_quantity', '>', 0);
            });
    })
    ->limit(10);

echo 'SQL: '.$query->toSql()."\n";
echo 'Bindings: '.json_encode($query->getBindings())."\n";

$results = $query->get();
echo 'Results count: '.count($results)."\n";
if (count($results) > 0) {
    echo "Sample results:\n";
    foreach ($results as $row) {
        echo "  ID: {$row->id}, Name: {$row->name}, Stock: {$row->stock_quantity}\n";
    }
}
echo "\n";

echo "2. Testing 'OUT OF STOCK' filter (stock_variations_empty=1, stock_goods_empty=1):\n";

$query2 = Capsule::table('shop_goods')
    ->select('id', 'name', 'stock_quantity', 'supplier')
    ->where(function ($mainQuery) {
        // Products with variations - sum of all variations = 0
        $mainQuery->whereExists(function ($subQuery) {
            $subQuery->select(Capsule::raw(1))
                ->from('shop_good_variations')
                ->whereRaw('shop_good_variations.good_id = shop_goods.id');
        })
            ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = 0');
        // Products without variations - main product stock = 0 or NULL
        $mainQuery->orWhere(function ($noVariationsQuery) {
            $noVariationsQuery->whereNotExists(function ($subQuery) {
                $subQuery->select(Capsule::raw(1))
                    ->from('shop_good_variations')
                    ->whereRaw('shop_good_variations.good_id = shop_goods.id');
            })
                ->where(function ($stockQuery) {
                    $stockQuery->where('stock_quantity', '=', 0)
                        ->orWhereNull('stock_quantity');
                });
        });
    })
    ->limit(10);

echo 'SQL: '.$query2->toSql()."\n";
echo 'Bindings: '.json_encode($query2->getBindings())."\n";

$results2 = $query2->get();
echo 'Results count: '.count($results2)."\n";
if (count($results2) > 0) {
    echo "Sample results:\n";
    foreach ($results2 as $row) {
        echo "  ID: {$row->id}, Name: {$row->name}, Stock: ".($row->stock_quantity ?? 'NULL')."\n";
    }
}
echo "\n";

echo "3. Checking some sample data to understand the structure:\n";

// Get some sample products with variations
$sampleWithVariations = Capsule::table('shop_goods')
    ->select('shop_goods.id', 'shop_goods.name', 'shop_goods.stock_quantity')
    ->leftJoin('shop_good_variations', 'shop_goods.id', '=', 'shop_good_variations.good_id')
    ->whereNotNull('shop_good_variations.id')
    ->groupBy('shop_goods.id', 'shop_goods.name', 'shop_goods.stock_quantity')
    ->limit(5)
    ->get();

echo "Products with variations:\n";
foreach ($sampleWithVariations as $product) {
    $variationStock = Capsule::table('shop_good_variations')
        ->where('good_id', $product->id)
        ->selectRaw('SUM(stock_quantity) as total_stock, COUNT(*) as variation_count')
        ->first();

    echo "  Product ID: {$product->id}, Name: {$product->name}, Main Stock: ".($product->stock_quantity ?? 'NULL')."\n";
    echo "    Variations: {$variationStock->variation_count} total, Sum stock: {$variationStock->total_stock}\n";
}

echo "\n";

// Get some sample products without variations
$sampleWithoutVariations = Capsule::table('shop_goods')
    ->select('shop_goods.id', 'shop_goods.name', 'shop_goods.stock_quantity')
    ->leftJoin('shop_good_variations', 'shop_goods.id', '=', 'shop_good_variations.good_id')
    ->whereNull('shop_good_variations.id')
    ->groupBy('shop_goods.id', 'shop_goods.name', 'shop_goods.stock_quantity')
    ->limit(5)
    ->get();

echo "Products without variations:\n";
foreach ($sampleWithoutVariations as $product) {
    echo "  Product ID: {$product->id}, Name: {$product->name}, Main Stock: ".($product->stock_quantity ?? 'NULL')."\n";
}

echo "\n=== END DEBUG ===\n";
