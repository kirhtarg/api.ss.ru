<?php

namespace App\Jobs;

use App\Services\SupplierFeedPriceStockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSupplierFeedPriceStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(private readonly int $runId)
    {
        $this->onQueue('supplier-feed-sync');
    }

    public function handle(SupplierFeedPriceStockService $service): void
    {
        $service->run($this->runId);
    }
}
