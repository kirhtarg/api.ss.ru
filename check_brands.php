<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Models\Setting;

$whitelist = Setting::where('key', 'avito_brand_whitelist')->value('value');
echo "Raw Whitelist Content:\n";
var_dump($whitelist);

$service = new \App\Services\AvitoFeedService();
$reflection = new \ReflectionClass($service);
$method = $reflection->getMethod('parseBrands');
$method->setAccessible(true);
$brands = $method->invoke($service, $whitelist);

echo "\nParsed Brands:\n";
print_r($brands);
