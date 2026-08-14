<?php

namespace App\Jobs;

use App\Services\BikeproductsCatalogService;
use App\Models\SupplierCatalogActionRun;
use Illuminate\Support\Facades\DB;
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
    private string $mode = 'append';
    private ?int $actionRunId = null;

    /** @param array<int, int> $itemIds */
    public function __construct(
        private readonly int $snapshotId,
        private readonly array $itemIds,
        ?int $actionRunId = null,
        string $mode = 'append',
    )
    {
        $this->actionRunId = $actionRunId;
        $this->mode = $mode;
        // Use the default queue worker already configured for the API.
        // A dedicated images-download worker is not a deployment requirement.
    }

    public function handle(BikeproductsCatalogService $catalog): void
    {
        $result = $catalog->downloadAttachedImages($this->snapshotId, $this->itemIds, $this->mode);
        $this->recordProgress($result);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Supplier image sync job failed', [
            'snapshot_id' => $this->snapshotId,
            'item_ids' => $this->itemIds,
            'mode' => $this->mode,
            'error' => $exception->getMessage(),
        ]);
        $this->recordProgress(['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => count($this->itemIds)], true);
    }

    /** @param array{created: int, updated: int, skipped: int, failed: int} $result */
    private function recordProgress(array $result, bool $jobFailed = false): void
    {
        if (! $this->actionRunId) {
            return;
        }

        DB::transaction(function () use ($result, $jobFailed): void {
            $run = SupplierCatalogActionRun::query()->lockForUpdate()->find($this->actionRunId);
            if (! $run) {
                return;
            }
            $payload = $run->result ?? [];
            $sync = $payload['image_sync'] ?? [];
            if ($sync === []) {
                return;
            }
            $sync['completed_jobs'] = (int) ($sync['completed_jobs'] ?? 0) + 1;
            $sync['failed_jobs'] = (int) ($sync['failed_jobs'] ?? 0) + ($jobFailed ? 1 : 0);
            foreach (['created', 'updated', 'skipped', 'failed'] as $key) {
                $sync[$key] = (int) ($sync[$key] ?? 0) + (int) ($result[$key] ?? 0);
            }
            $total = max(1, (int) ($sync['total_jobs'] ?? 1));
            if ((int) $sync['completed_jobs'] >= $total) {
                $sync['status'] = (int) $sync['failed_jobs'] > 0 ? 'completed_with_errors' : 'completed';
                $sync['completed_at'] = now()->toIso8601String();
            } else {
                $sync['status'] = 'processing';
            }
            $payload['image_sync'] = $sync;
            $run->update(['result' => $payload]);
        });
    }
}
