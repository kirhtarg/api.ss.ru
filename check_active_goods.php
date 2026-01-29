<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopGood;

$active = ShopGood::where('is_active', true)->count();
$inactive = ShopGood::where('is_active', false)->count();
$total = ShopGood::count();

echo "Total goods: {$total}\n";
echo "Active goods: {$active}\n";
echo "Inactive goods: {$inactive}\n";

// Проверим, что возвращает фильтр
$queryWithFilter = ShopGood::where('is_active', true);
$resultWithFilter = $queryWithFilter->count();

$queryWithoutFilter = ShopGood::query();
$resultWithoutFilter = $queryWithoutFilter->count();

echo "\nQuery with is_active=true: {$resultWithFilter}\n";
echo "Query without filter: {$resultWithoutFilter}\n";