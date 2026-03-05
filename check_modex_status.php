<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ExportFile;

$files = ExportFile::where('export_config->type', 'modex')
    ->orderBy('id', 'desc')
    ->take(5)
    ->get();

foreach ($files as $file) {
    $conf = $file->export_config ?? [];
    echo "ID: {$file->id} | Status: {$file->status} | Progress: ".($conf['progress_rows'] ?? 'N/A').' / '.($conf['total_rows'] ?? 'N/A').' | Phase: '.($conf['phase'] ?? 'N/A')."\n";
    if ($file->status === 'processing') {
        // Check if job is in queue
        // This is harder to check directly without knowing job ID, but we can assume if it's processing for a long time it might be stuck
        echo '   -> Processing since: '.$file->updated_at."\n";
    }
}
