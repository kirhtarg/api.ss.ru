<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ShopBonusSettings;

$s = ShopBonusSettings::find(3);
if ($s) {
    echo "Updating Bronze (ID 3) from " . $s->sale_price_percentage . " to 1.00\n";
    $s->sale_price_percentage = 1.00;
    $s->regular_price_percentage = 5.00;
    $s->save();
    echo "Update successful.\n";
} else {
    echo "Bronze setting (ID 3) not found.\n";
}
