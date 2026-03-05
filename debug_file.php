<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "All export files:\n";

$files = DB::table('export_files')
    ->orderBy('created_at', 'desc')
    ->take(10)
    ->get();

foreach ($files as $file) {
    echo "ID: {$file->id}, Status: {$file->status}, Format: {$file->format}, Created: {$file->created_at}\n";

    $config = json_decode($file->export_config, true);
    if (isset($config['type'])) {
        echo "  Type: {$config['type']}\n";
    }

    if (isset($config['input_file_path'])) {
        echo "  Input path: {$config['input_file_path']}\n";
        echo '  Input exists: '.(Storage::exists($config['input_file_path']) ? 'YES' : 'NO')."\n";
    }

    if ($file->error_message) {
        echo "  Error: {$file->error_message}\n";
    }

    echo "\n";
}
