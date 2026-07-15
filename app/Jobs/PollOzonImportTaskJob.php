<?php

namespace App\Jobs;

use App\Models\ShopOzonAccount;
use App\Models\ShopOzonProductBinding;
use App\Models\ShopOzonSyncItem;
use App\Models\ShopOzonSyncRun;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollOzonImportTaskJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 12;
    public int $backoff = 15;

    public function __construct(public int $runId, public string $taskId) {}

    public function handle(): void
    {
        $run = ShopOzonSyncRun::findOrFail($this->runId);
        $client = new OzonSellerClient(ShopOzonAccount::findOrFail($run->account_id));
        $response = $client->post('/v1/product/import/info', ['task_id' => (int) $this->taskId]);
        $results = collect(data_get($response, 'result.items', data_get($response, 'items', [])));
        if ($results->isEmpty()) {
            $this->release(15);
            return;
        }

        $items = ShopOzonSyncItem::where('run_id', $run->id)->where('task_id', $this->taskId)->where('status', 'submitted')->get();
        $hasPendingItems = $items->contains(function ($item) use ($results) {
            $result = $results->first(fn ($row) => (string) data_get($row, 'offer_id') === $item->offer_id);
            return in_array(strtolower((string) data_get($result, 'status')), ['pending', 'processing'], true);
        });
        if ($hasPendingItems) {
            $this->release(15);
            return;
        }

        foreach ($items as $item) {
            $result = $results->first(fn ($row) => (string) data_get($row, 'offer_id') === $item->offer_id);
            $errors = collect(data_get($result, 'errors', []))->filter()->values()->all();
            if (! $result) $errors[] = ['message' => 'Ozon не вернул результат для offer_id '.$item->offer_id];
            $success = $result && empty($errors) && strtolower((string) data_get($result, 'status')) === 'imported';
            $item->update(['status' => $success ? 'completed' : 'failed', 'response_payload' => $result, 'errors' => $errors ?: null]);
            ShopOzonProductBinding::updateOrCreate(
                ['account_id' => $run->account_id, 'offer_id' => $item->offer_id],
                ['good_id' => $item->good_id, 'variation_id' => $item->variation_id, 'status' => $success ? 'synced' : 'error', 'product_id' => data_get($result, 'product_id'), 'errors' => $errors ?: null, 'last_synced_at' => now()]
            );
            $run->increment('processed');
            $run->increment($success ? 'succeeded' : 'failed');
        }
        $run->refresh();
        if ($run->processed >= $run->total) $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
    }
}
