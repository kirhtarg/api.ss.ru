<?php

namespace App\Jobs;

use App\Services\BikeproductsCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AuditSupplierCatalogImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(private readonly int $snapshotId)
    {
        $this->onQueue('images-audit');
    }

    public function handle(BikeproductsCatalogService $catalog): void
    {
        $catalog->processImageContentAuditBatch($this->snapshotId);
    }
}
