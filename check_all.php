<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "All export files:\n";

$files = DB::table('export_files')
    ->orderBy('id', 'desc')
    ->take(10)
    ->get();

foreach ($files as $file) {
    echo "ID: {$file->id}, Status: {$file->status}, Format: {$file->format}\n";
}