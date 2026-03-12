<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$methods = \App\Models\ShopPaymentMethod::where('type', 'yandex_pay')->orWhere('type', 'yandex_split')->get();
foreach ($methods as $method) {
    echo "ID: {$method->id}, Name: {$method->name}, Type: {$method->type}\n";
    echo "Settings: " . json_encode($method->settings, JSON_PRETTY_PRINT) . "\n\n";
}
