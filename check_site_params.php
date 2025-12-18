<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Проверяем параметры сайта в таблице settings
$settings = DB::table('settings')->whereIn('key', ['tag_max_bonus', 'tag_max_bonus_tax', 'tag_no_bonus'])->get();

echo 'Settings found:' . PHP_EOL;
foreach ($settings as $setting) {
    echo $setting->key . ': ' . ($setting->value ?? 'null') . PHP_EOL;
}

if ($settings->count() === 0) {
    echo 'No settings found. Available keys:' . PHP_EOL;
    $allSettings = DB::table('settings')->select('key')->distinct()->get();
    foreach ($allSettings as $setting) {
        echo '- ' . $setting->key . PHP_EOL;
    }
}
