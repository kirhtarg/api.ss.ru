<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Shop\Property;
use App\Models\ShopVariationAttribute;

echo "Checking properties:\n";
$props = Property::take(5)->get(['id', 'name']);
foreach ($props as $prop) {
    echo "  {$prop->id}: {$prop->name}\n";
}

echo "\nChecking variation attributes:\n";
$attrs = ShopVariationAttribute::take(5)->get(['id', 'name']);
foreach ($attrs as $attr) {
    echo "  {$attr->id}: {$attr->name}\n";
}

echo "\nChecking if property with ID 1 exists:\n";
$prop1 = Property::find(1);
if ($prop1) {
    echo "  Property 1: {$prop1->name}\n";
} else {
    echo "  Property 1 not found\n";
}

echo "\nChecking if attribute with name 'color' exists:\n";
$colorAttr = ShopVariationAttribute::where('name', 'color')->first();
if ($colorAttr) {
    echo "  Color attribute: {$colorAttr->id} - {$colorAttr->name}\n";
} else {
    echo "  Color attribute not found\n";
}