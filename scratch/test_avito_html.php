<?php

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AvitoFeedService;

$service = app(AvitoFeedService::class);

$html = '<div class="item" style="..."><div class="title" style="...">Рама</div><div class="val" style="...">Alu X6 Ultralite X-Road Race , Road Advanced Geometry, Smooth Welding, Disc Brake Flat mount</div></div><div class="item" style="..."><div class="title" style="...">Материал рамы</div><div class="val" style="...">Алюминий</div></div>';

$reflection = new \ReflectionClass(get_class($service));
$method = $reflection->getMethod('cleanHtml');
$method->setAccessible(true);

$result = $method->invoke($service, $html);

echo "Original HTML:\n" . $html . "\n\n";
echo "Cleaned Result:\n" . $result . "\n";
