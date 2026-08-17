<?php

namespace App\Jobs;

use App\Services\BikeproductsCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use App\Models\SupplierCatalogSnapshot;

class ProcessSupplierCatalogExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private readonly int $snapshotId)
    {
    }

    public function handle(BikeproductsCatalogService $catalog): void
    {
        $catalog->processExcelSnapshot($this->snapshotId);

        $snapshot = SupplierCatalogSnapshot::find($this->snapshotId);
        if ($snapshot?->status !== 'ready') {
            return;
        }

        $version = $snapshot->updated_at?->format('Uu') ?? '0';
        $lockKey = 'supplier-catalog:variation-audit-warming:'.$snapshot->id.':'.$version;
        if (Cache::store('file')->add($lockKey, true, now()->addMinutes(30))) {
            WarmSupplierCatalogVariationAuditJob::dispatch($snapshot->id, $version);
        }
    }
}
