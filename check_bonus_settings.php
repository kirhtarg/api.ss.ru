<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'All settings with bonus:' . PHP_EOL;
$bonusSettings = DB::table('settings')->where('key', 'like', '%bonus%')->get();
foreach ($bonusSettings as $setting) {
    echo $setting->key . ': ' . $setting->value . PHP_EOL;
}

echo PHP_EOL . 'All settings with max:' . PHP_EOL;
$maxSettings = DB::table('settings')->where('key', 'like', '%max%')->get();
foreach ($maxSettings as $setting) {
    echo $setting->key . ': ' . $setting->value . PHP_EOL;
}
