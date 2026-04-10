<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ShopBonusSettings;

$settings = ShopBonusSettings::all();
foreach ($settings as $s) {
    if (str_contains($s->name, 'Бронза') || str_contains($s->name, 'Основная')) {
        echo "Updating " . $s->name . " (ID " . $s->id . ") sale rate to 1.00\n";
        $s->sale_price_percentage = 1.00;
        $s->save();
    }
}
echo "All relevant settings updated.\n";
