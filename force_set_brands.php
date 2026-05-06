<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Models\Setting;

// Список брендов из сообщения пользователя (примеры)
$brands = <<<XML
<brand name="3D Family"/>
<brand name="A-BOARD-H"/>
<brand name="Nike"/>
<brand name="Adidas"/>
XML;

Setting::updateOrCreate(
    ['key' => 'avito_brand_whitelist'],
    ['value' => $brands, 'group' => 'avito']
);

echo "Setting avito_brand_whitelist updated successfully.\n";
