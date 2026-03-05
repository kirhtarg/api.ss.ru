<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Failed jobs:\n";

$failed = DB::table('failed_jobs')->get();

foreach ($failed as $job) {
    echo "UUID: {$job->uuid}, Failed at: {$job->failed_at}\n";
    echo 'Exception: '.substr($job->exception, 0, 200)."...\n";
    echo "---\n";
}
