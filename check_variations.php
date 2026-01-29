<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopGood;

$goods = ShopGood::whereIn('id', [90068, 90069, 90070])->with('variations')->get();

echo "Variations for selected goods:\n";
foreach ($goods as $good) {
    echo "Good ID {$good->id}: {$good->variations->count()} variations\n";
    if ($good->variations->count() > 0) {
        echo "  Variation IDs: " . $good->variations->pluck('id')->join(', ') . "\n";
    }
}

echo "\nTotal variations across all selected goods: ";
$totalVariations = $goods->sum(function($good) {
    return $good->variations->count();
});
echo $totalVariations . "\n";

echo "Expected total rows in export: " . $totalVariations . " (since each variation becomes a row)\n";