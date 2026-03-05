<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessModexJob;
use App\Models\ExportFile;

echo "Testing ProcessModexJob for file 206...\n";

try {
    $file = ExportFile::find(206);
    if (! $file) {
        echo "File not found!\n";
        exit(1);
    }

    echo "File found: {$file->id}, status: {$file->status}\n";

    // Создаем job и запускаем напрямую
    $job = new ProcessModexJob($file);
    $job->handle();

    echo "Job completed!\n";

    // Проверяем статус после обработки
    $file->refresh();
    echo "New status: {$file->status}\n";

} catch (Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
}
