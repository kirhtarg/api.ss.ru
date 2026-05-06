<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Models\Setting;

$settings = Setting::where('group', 'avito')->pluck('key');
foreach ($settings as $key) {
    echo $key . "\n";
}
