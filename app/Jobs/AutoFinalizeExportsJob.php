<?php

namespace App\Jobs;

use App\Models\ExportFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AutoFinalizeExportsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 120; // короткий проход

    public $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $stucks = ExportFile::query()
                ->where('status', 'processing')
                ->where(function ($q) {
                    $q->whereRaw('JSON_EXTRACT(export_config, "$.total_rows") IS NOT NULL')
                        ->whereRaw('JSON_EXTRACT(export_config, "$.progress_rows") IS NOT NULL');
                })
                ->get();

            foreach ($stucks as $file) {
                $arr = $file->toArray();
                $conf = $arr['export_config'] ?? [];
                if (! is_array($conf)) {
                    $conf = [];
                }
                $progress = (int) ($conf['progress_rows'] ?? 0);
                $total = (int) ($conf['total_rows'] ?? ($arr['total_rows'] ?? 0));

                if ($total > 0 && $progress >= $total) {
                    $expectedPath = 'modex/'.($arr['filename'] ?? '');
                    $exists = $expectedPath ? Storage::exists($expectedPath) : false;
                    if (! $exists) {
                        $temp = $conf['temp_output_path'] ?? null;
                        if ($temp && Storage::exists($temp)) {
                            try {
                                $contents = Storage::get($temp);
                                Storage::put($expectedPath, $contents);
                                Storage::delete($temp);
                                $exists = true;
                            } catch (\Throwable $e) {
                                Log::error('AutoFinalize copy failed', ['file_id' => $file->id, 'error' => $e->getMessage()]);
                            }
                        }
                    }
                    if ($exists) {
                        try {
                            $size = Storage::size($expectedPath);
                            $file->update([
                                'status' => 'completed',
                                'file_path' => $expectedPath,
                                'file_size' => $size,
                                'total_rows' => $total,
                                'error_message' => null,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('AutoFinalize update failed', ['file_id' => $file->id, 'error' => $e->getMessage()]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

    }
}
