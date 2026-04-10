<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ShopBonusSettings;

$s1 = ShopBonusSettings::find(1);
if ($s1) {
    echo "Reverting ID 1 sale rate to 2.50\n";
    $s1->sale_price_percentage = 2.50;
    $s1->save();
}

$s3 = ShopBonusSettings::find(3);
if ($s3) {
    echo "Reverting ID 3 sale rate to 2.50\n";
    $s3->sale_price_percentage = 2.50;
    $s3->save();
}

echo "Revert successful.\n";
