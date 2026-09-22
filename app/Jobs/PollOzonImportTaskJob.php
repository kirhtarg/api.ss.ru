<?php

namespace App\Jobs;

use App\Models\ShopOzonAccount;
use App\Models\ShopOzonProductBinding;
use App\Models\ShopOzonSyncItem;
use App\Models\ShopOzonSyncRun;
use App\Services\Ozon\OzonPostProductSyncScheduler;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollOzonImportTaskJob implements ShouldQueue
{
    use Queueable;
    // Product moderation can legitimately take longer than a few minutes.
    // Do not turn the intermediate `moderating` status into a fake failure.
    public int $tries = 60;
    public int $backoff = 60;

    public function __construct(public int $runId, public string $taskId) {}

    public function handle(OzonPostProductSyncScheduler $postProductSyncScheduler): void
    {
        $run = ShopOzonSyncRun::findOrFail($this->runId);
        $client = new OzonSellerClient(ShopOzonAccount::findOrFail($run->account_id));
        $response = $client->post('/v1/product/import/info', ['task_id' => (int) $this->taskId]);
        $results = collect(data_get($response, 'result.items', data_get($response, 'items', [])));
        if ($results->isEmpty()) {
            $this->release(60);
            return;
        }

        $items = ShopOzonSyncItem::where('run_id', $run->id)->where('task_id', $this->taskId)->where('status', 'submitted')->get();
        $hasPendingItems = $items->contains(function ($item) use ($results) {
            $result = $results->first(fn ($row) => (string) data_get($row, 'offer_id') === $item->offer_id);
            return $this->isIntermediateStatus(data_get($result, 'status'));
        });
        if ($hasPendingItems) {
            $this->release(60);
            return;
        }

        foreach ($items as $item) {
            $result = $results->first(fn ($row) => (string) data_get($row, 'offer_id') === $item->offer_id);
            $errors = collect(data_get($result, 'errors', data_get($result, 'error', [])))->filter()->values()->all();
            if (! $result) $errors[] = ['message' => 'Ozon не вернул результат для offer_id '.$item->offer_id];
            $status = strtolower(trim((string) data_get($result, 'status')));
            $success = $result && empty($errors) && $status === 'imported';
            if ($result && ! $success && empty($errors)) {
                $errors[] = ['message' => 'Ozon завершил обработку со статусом: '.($status !== '' ? $status : 'неизвестный статус').'.'];
            }
            $item->update(['status' => $success ? 'completed' : 'failed', 'response_payload' => $result, 'errors' => $errors ?: null]);
            $bindingData = ['good_id' => $item->good_id, 'variation_id' => $item->variation_id, 'is_variation' => (bool) $item->variation_id, 'status' => $success ? 'synced' : 'error', 'product_id' => data_get($result, 'product_id'), 'errors' => $errors ?: null, 'last_synced_at' => now()];
            if ($sku = $this->ozonSku($result)) $bindingData['sku'] = $sku;
            ShopOzonProductBinding::updateOrCreate(
                ['account_id' => $run->account_id, 'offer_id' => $item->offer_id],
                $bindingData,
            );
            $run->increment('processed');
            $run->increment($success ? 'succeeded' : 'failed');
        }
        $run->refresh();
        if ($run->processed >= $run->total) {
            $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
            $postProductSyncScheduler->scheduleStocks($run);
        }
    }

    private function ozonSku(mixed $result): ?int
    {
        foreach (['sku', 'fbs_sku', 'fbo_sku', 'sources.0.sku'] as $path) {
            $value = data_get($result, $path);
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }

        return null;
    }

    private function isIntermediateStatus(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), [
            'pending', 'processing', 'queued', 'moderating', 'moderation',
            'in_moderation', 'awaiting_moderation',
        ], true);
    }
}
