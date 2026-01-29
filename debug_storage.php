<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;

echo "Storage disk: local\n";
echo "Root path: " . config('filesystems.disks.local.root') . "\n";
echo "Real path: " . realpath(config('filesystems.disks.local.root')) . "\n";

$testPath = 'exports/test_debug_' . time() . '.txt';
$testContent = 'Test content ' . date('Y-m-d H:i:s');

echo "\nTesting Storage::put('$testPath', '$testContent')\n";

$result = Storage::put($testPath, $testContent);
echo "Put result: " . ($result ? 'true' : 'false') . "\n";

echo "Storage::exists('$testPath'): " . (Storage::exists($testPath) ? 'true' : 'false') . "\n";
echo "Storage::path('$testPath'): " . Storage::path($testPath) . "\n";
echo "file_exists(Storage::path('$testPath')): " . (file_exists(Storage::path($testPath)) ? 'true' : 'false') . "\n";

echo "\nFiles in exports directory:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            echo "  {$file} ({$size} bytes)\n";
        }
    }
} else {
    echo "Directory does not exist: $exportsDir\n";
}