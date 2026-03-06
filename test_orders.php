<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$redis = \Illuminate\Support\Facades\Redis::connection();
$keys = $redis->keys('*dolyame*');
print_r($keys);
