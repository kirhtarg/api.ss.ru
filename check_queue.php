<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Jobs in queue: " . DB::table('jobs')->count() . "\n";
echo "Failed jobs: " . DB::table('failed_jobs')->count() . "\n";

$jobs = DB::table('jobs')->get();
foreach ($jobs as $job) {
    echo "Job ID: {$job->id}, Queue: {$job->queue}\n";
}

echo "\nModex files:\n";
$files = DB::table('export_files')
    ->whereRaw("JSON_EXTRACT(export_config, '$.type') = 'modex'")
    ->orderBy('created_at', 'desc')
    ->take(5)
    ->get();

foreach ($files as $file) {
    echo "ID: {$file->id}, Status: {$file->status}, Created: {$file->created_at}\n";
}