<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Models\Setting;

$settings = Setting::where('group', 'avito')->get(['key', 'value'])->toArray();
print_r($settings);
