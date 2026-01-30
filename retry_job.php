<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\ExportFile;
use App\Jobs\ProcessModexJob;

echo "Checking file 211...\n";

$file = ExportFile::find(211);
if (!$file) {
    echo "File not found!\n";
    exit(1);
}

echo "File status: {$file->status}\n";

if ($file->status === 'processing') {
    echo "File is still processing, resetting to pending...\n";
    $file->update(['status' => 'pending']);
}

echo "Dispatching new job...\n";
ProcessModexJob::dispatch($file);

echo "Job dispatched!\n";