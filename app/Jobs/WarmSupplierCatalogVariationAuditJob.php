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
use Illuminate\Support\Facades\Log;

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
        $startedAt = microtime(true);
        $completed = false;
        try {
            $snapshot = SupplierCatalogSnapshot::find($this->snapshotId);
            if ($snapshot?->status === 'ready') {
                $catalog->warmVariationAudit($snapshot);
                $completed = true;
            }
        } finally {
            Log::info('Supplier catalog variation audit warmup finished', [
                'snapshot_id' => $this->snapshotId,
                'completed' => $completed,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ]);
            Cache::store('file')->forget($this->lockKey());
        }
    }

    private function lockKey(): string
    {
        return 'supplier-catalog:variation-audit-warming:v5:'.$this->snapshotId.':'.$this->cacheVersion;
    }
}
