<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$allJobs = DB::table('jobs')->get();
$queues = $allJobs->groupBy('queue');

echo "Jobs by queue:\n";
foreach ($queues as $queueName => $jobs) {
    echo "Queue '$queueName': {$jobs->count()} jobs\n";
}

echo "\nAll jobs:\n";
$count = $allJobs->count();
if ($count > 0) {
    $jobs = $allJobs->take(5);
    foreach ($jobs as $job) {
        echo "ID: {$job->id} - Queue: {$job->queue} - Created: {$job->created_at}\n";
        echo "Payload preview: " . substr($job->payload, 0, 100) . "...\n";
    }
}

$failedCount = DB::table('failed_jobs')->count();
echo "\nFailed jobs: $failedCount\n";

if ($failedCount > 0) {
    $failedJobs = DB::table('failed_jobs')->take(3)->get();
    foreach ($failedJobs as $job) {
        echo "ID: {$job->id} - Failed at: {$job->failed_at}\n";
        echo "Exception: " . substr($job->exception, 0, 200) . "...\n";
    }
}