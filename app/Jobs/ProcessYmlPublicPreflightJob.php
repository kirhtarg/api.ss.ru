<?php

namespace App\Jobs;

use App\Models\ShopYandexMarketSyncRun;
use App\Services\YmlPublicPreflightService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessYmlPublicPreflightJob implements ShouldQueue
{
    use Queueable;
    public int $timeout = 180;
    public int $tries = 1;
    public function __construct(public int $runId) {}
    public function handle(YmlPublicPreflightService $service): void
    {
        $run = ShopYandexMarketSyncRun::findOrFail($this->runId);
        try { $service->process($run); }
        catch (Throwable $e) { $run->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]); throw $e; }
    }
}
