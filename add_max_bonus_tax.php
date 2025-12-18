<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::table('settings')->updateOrInsert(
    ['key' => 'tag_max_bonus_tax'],
    ['value' => '50'] // 50% для повышенного бонуса
);

echo 'Added tag_max_bonus_tax: 50' . PHP_EOL;
