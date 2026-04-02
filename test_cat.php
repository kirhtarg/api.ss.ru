<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$good = \App\Models\ShopGood::first();
echo "Sample category id: " . $good->category_id . "\n";

