<?php

namespace App\Jobs;

use App\Models\ShopYandexMarketSyncRun;
use App\Services\YandexMarketStockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessYandexMarketStockSyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(YandexMarketStockService $service): void
    {
        $run = ShopYandexMarketSyncRun::findOrFail($this->runId);

        try {
            if ($run->type === 'stock_verification') {
                $service->processStockVerificationRun($run);
            } else {
                $service->processStockRun($run);
            }
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }
}
