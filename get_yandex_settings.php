<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$method = \App\Models\ShopPaymentMethod::find(6);
$settings = $method->settings;

echo "--- Yandex Pay Settings ---\n";
foreach ($settings as $key => $value) {
    if (is_array($value) || is_object($value)) {
        echo "$key: " . json_encode($value) . "\n";
    } else {
        echo "$key: " . ($value ?? 'NULL') . "\n";
    }
}
echo "---------------------------\n";
