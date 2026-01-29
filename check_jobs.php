<?php

require_once 'bootstrap/app.php';

use Illuminate\Support\Facades\DB;

echo "Jobs in queue: " . DB::table('jobs')->count() . PHP_EOL;

$jobs = DB::table('jobs')->get();
foreach ($jobs as $job) {
    echo "Job ID: {$job->id}, Queue: {$job->queue}, Created: {$job->created_at}" . PHP_EOL;
    echo "Payload preview: " . substr($job->payload, 0, 200) . "..." . PHP_EOL;
    echo "---" . PHP_EOL;
}

echo PHP_EOL . "Failed jobs: " . DB::table('failed_jobs')->count() . PHP_EOL;

echo PHP_EOL . "Export files with modex type:" . PHP_EOL;
$modexFiles = DB::table('export_files')
    ->where('export_config->type', 'modex')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($modexFiles as $file) {
    echo "ID: {$file->id}, Status: {$file->status}, Created: {$file->created_at}" . PHP_EOL;
}