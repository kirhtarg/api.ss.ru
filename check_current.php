<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "Current processing files:\n";

$files = DB::table('export_files')
    ->where('status', 'processing')
    ->get();

foreach ($files as $file) {
    echo "ID: {$file->id}, Status: {$file->status}\n";

    $config = json_decode($file->export_config, true);
    $inputPath = $config['input_file_path'] ?? null;

    if ($inputPath) {
        echo "  Input path: {$inputPath}\n";
        echo '  Input exists: '.(Storage::exists($inputPath) ? 'YES' : 'NO')."\n";
        echo '  Full path: '.storage_path('app/'.$inputPath)."\n";
        echo '  File exists on disk: '.(file_exists(storage_path('app/'.$inputPath)) ? 'YES' : 'NO')."\n";

        if (file_exists(storage_path('app/'.$inputPath))) {
            echo '  File size: '.filesize(storage_path('app/'.$inputPath))." bytes\n";
        }
    }
    echo "\n";
}
