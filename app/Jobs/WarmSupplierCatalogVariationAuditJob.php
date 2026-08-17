<?php

namespace App\Jobs;

use App\Models\SupplierCatalogSnapshot;
use App\Services\BikeproductsCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class WarmSupplierCatalogVariationAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        private readonly int $snapshotId,
        private readonly string $cacheVersion,
    ) {
    }

    public function handle(BikeproductsCatalogService $catalog): void
    {
        try {
            $snapshot = SupplierCatalogSnapshot::find($this->snapshotId);
            if ($snapshot?->status === 'ready') {
                $catalog->warmVariationAudit($snapshot);
            }
        } finally {
            Cache::store('file')->forget($this->lockKey());
        }
    }

    private function lockKey(): string
    {
        return 'supplier-catalog:variation-audit-warming:'.$this->snapshotId.':'.$this->cacheVersion;
    }
}
