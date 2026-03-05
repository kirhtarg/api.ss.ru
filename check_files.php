<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Modex files:\n";

$files = DB::table('export_files')
    ->whereRaw("JSON_EXTRACT(export_config, '$.type') = 'modex'")
    ->orderBy('created_at', 'desc')
    ->take(5)
    ->get();

foreach ($files as $file) {
    echo "ID: {$file->id}, Status: {$file->status}, Created: {$file->created_at}\n";
    if ($file->error_message) {
        echo "  Error: {$file->error_message}\n";
    }
}

echo "\nJobs in queue: ".DB::table('jobs')->count()."\n";
