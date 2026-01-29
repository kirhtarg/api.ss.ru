<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;

echo "Testing file creation...\n";

$testFile = 'exports/test_creation_' . time() . '.txt';
$content = 'Test content created at ' . date('Y-m-d H:i:s');

echo "Creating file: $testFile\n";
$result = Storage::put($testFile, $content);

echo "Put result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";

$exists = Storage::exists($testFile);
echo "Storage::exists(): " . ($exists ? 'YES' : 'NO') . "\n";

$path = Storage::path($testFile);
echo "Storage::path(): $path\n";

$fileExists = file_exists($path);
echo "file_exists(): " . ($fileExists ? 'YES' : 'NO') . "\n";

if ($fileExists) {
    $size = filesize($path);
    echo "File size: $size bytes\n";

    $readContent = file_get_contents($path);
    echo "Content matches: " . ($readContent === $content ? 'YES' : 'NO') . "\n";
}

echo "\nFiles in exports directory:\n";
$exportsDir = storage_path('app/exports');
if (is_dir($exportsDir)) {
    $files = scandir($exportsDir);
    $found = false;
    foreach ($files as $file) {
        if (strpos($file, 'test_creation_') === 0) {
            $fullPath = $exportsDir . '/' . $file;
            $size = filesize($fullPath);
            echo "  FOUND: $file ($size bytes)\n";
            $found = true;
        }
    }
    if (!$found) {
        echo "  Test file NOT found in directory\n";
    }
}