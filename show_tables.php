<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = DB::select('SHOW TABLES');
echo "Tables with 'site' or 'setting':" . PHP_EOL;
foreach ($tables as $table) {
    $tableName = current($table);
    if (strpos($tableName, 'site') !== false || strpos($tableName, 'setting') !== false) {
        echo $tableName . PHP_EOL;
    }
}
