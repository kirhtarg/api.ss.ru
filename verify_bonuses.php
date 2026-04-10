<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OrderCalculationService;
use App\Models\ShopGood;

$service = new OrderCalculationService();
$good = ShopGood::first();
$good->price = 9250;
$good->sale_price = 8330;

$res = $service->calculateFinalUnitPrice($good);
$points = $service->calculateItemBonuses($res);

echo "Base Price: " . $res['base_price'] . "\n";
echo "Sale Price: " . $res['sale_price'] . "\n";
echo "Final Price: " . $res['final_price'] . "\n";
echo "Calculated Points: " . $points . "\n";
