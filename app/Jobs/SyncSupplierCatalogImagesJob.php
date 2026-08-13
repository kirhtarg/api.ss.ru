<?php

namespace App\Jobs;

use App\Services\BikeproductsCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSupplierCatalogImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;

    /** @param array<int, int> $itemIds */
    public function __construct(private readonly int $snapshotId, private readonly array $itemIds)
    {
        // Use the default queue worker already configured for the API.
        // A dedicated images-download worker is not a deployment requirement.
    }

    public function handle(BikeproductsCatalogService $catalog): void
    {
        $catalog->downloadAttachedImages($this->snapshotId, $this->itemIds);
    }
}
