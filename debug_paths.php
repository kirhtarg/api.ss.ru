<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function frontend_public_path(string $subpath = ''): string
{
    $path = config('frontend.path');
    if (empty($path)) {
        return "FRONTEND_PATH NOT SET";
    }
    $base = base_path(rtrim($path, '/') . '/public');

    return $subpath !== '' ? rtrim($base, '/') . '/' . ltrim($subpath, '/') : $base;
}

$testFile = '/images/shop/goods/excel_good_0_var_0_0_990.jpg';

echo "Test File (rel): " . $testFile . PHP_EOL;

$apiPath = realpath(public_path($testFile)) ?: public_path($testFile);
$frontendFullPath = realpath(frontend_public_path($testFile)) ?: frontend_public_path($testFile);

echo "API Path: " . $apiPath . PHP_EOL;
echo "Frontend Path: " . $frontendFullPath . PHP_EOL;

echo "API File Exists: " . (file_exists($apiPath) ? 'Yes' : 'No') . PHP_EOL;
echo "Frontend File Exists: " . (file_exists($frontendFullPath) ? 'Yes' : 'No') . PHP_EOL;

// Check directory
$dir = dirname($frontendFullPath);
echo "Directory: " . $dir . PHP_EOL;
echo "Directory Exists: " . (file_exists($dir) ? 'Yes' : 'No') . PHP_EOL;

// List files in directory
if (file_exists($dir)) {
    echo "Files in directory starting with excel_:" . PHP_EOL;
    $files = glob($dir . '/excel_*');
    foreach (array_slice($files, 0, 5) as $f) {
        echo "  - " . basename($f) . PHP_EOL;
    }
}
