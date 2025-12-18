<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'Checking which parameter is returned:' . PHP_EOL;
$settings = DB::table('settings')->whereIn('key', ['tag_max_bonus_tax', 'tag_max_bonud_tax'])->get();
foreach ($settings as $setting) {
    echo $setting->key . ': ' . $setting->value . PHP_EOL;
}
