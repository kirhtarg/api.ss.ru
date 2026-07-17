<?php

namespace App\Services\Ozon;

use App\Jobs\ProcessOzonSyncRunJob;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonSyncRun;

class OzonPostProductSyncScheduler
{
    public function scheduleStocks(ShopOzonSyncRun $productRun): ?ShopOzonSyncRun
    {
        $productRun->refresh();
        if ($productRun->mode !== 'products' || $productRun->processed < $productRun->total || $productRun->succeeded <= 0) {
            return null;
        }

        $account = ShopOzonAccount::find($productRun->account_id);
        if (! $account) return null;

        $goodIds = $productRun->items()
            ->where('status', 'completed')
            ->distinct()
            ->pluck('good_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
        if ($goodIds === []) return null;

        $stockRun = ShopOzonSyncRun::firstOrCreate(
            ['parent_run_id' => $productRun->id, 'mode' => 'stocks'],
            [
                'account_id' => $productRun->account_id,
                'user_id' => $productRun->user_id,
                'good_ids' => $goodIds,
                'status' => 'pending',
            ],
        );

        if ($stockRun->wasRecentlyCreated) {
            ProcessOzonSyncRunJob::dispatch($stockRun->id)->delay(now()->addSeconds(15));
        }

        return $stockRun;
    }
}
