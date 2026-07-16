<?php

namespace App\Jobs;

use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonProductBinding;
use App\Models\ShopOzonSyncItem;
use App\Models\ShopOzonSyncRun;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessOzonSyncRunJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 2;

    public function __construct(public int $runId) {}

    public function handle(OzonProductPayloadBuilder $builder, OzonProductResolver $resolver): void
    {
        $run = ShopOzonSyncRun::findOrFail($this->runId);
        $account = ShopOzonAccount::findOrFail($run->account_id);
        $client = new OzonSellerClient($account);
        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            $rows = $this->rows($run, $account, $resolver);
            $run->update(['total' => count($rows)]);

            foreach (array_chunk($rows, 100) as $chunk) {
                $this->sendChunk($run, $account, $client, $builder, $chunk);
            }

            $run->refresh();
            if ($run->mode === 'products' && $run->processed < $run->total) {
                $run->update(['status' => 'awaiting_result']);
                return;
            }
            $run->update([
                'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function rows(ShopOzonSyncRun $run, ShopOzonAccount $account, OzonProductResolver $resolver): array
    {
        if (! $account->selection_tag_id) {
            throw new \RuntimeException('Выберите тег отбора товаров Ozon в настройках кабинета.');
        }

        $query = $resolver->query($account, $run->good_ids);
        $mappings = $resolver->mappings($account);

        $rows = [];
        $query->chunkById(200, function ($goods) use (&$rows, $mappings, $resolver) {
            foreach ($goods as $good) {
                $mapping = $resolver->mappingFor($good, $mappings);
                foreach ($resolver->rowsForGood($good, $mapping) as $row) $rows[] = $row;
            }
        });
        return $rows;
    }

    private function sendChunk(ShopOzonSyncRun $run, ShopOzonAccount $account, OzonSellerClient $client, OzonProductPayloadBuilder $builder, array $chunk): void
    {
        $valid = [];
        foreach ($chunk as $row) {
            $good = $row['good'];
            $variation = $row['variation'];
            $mapping = $row['mapping'];
            $built = $builder->build($good, $variation, $account, $mapping);
            $errors = $run->mode === 'products' ? array_values(array_unique(array_merge($built['errors'], $row['row_errors']))) : [];
            if (! $mapping) $errors[] = 'Для категории товара не настроен профиль Ozon.';
            if ($run->mode === 'prices' && (float) $builder->pricePayload($good, $variation, $account)['price'] <= 0) {
                $errors[] = 'Цена должна быть больше нуля.';
            }
            $item = ShopOzonSyncItem::create([
                'run_id' => $run->id, 'good_id' => $good->id, 'variation_id' => $variation?->id,
                'offer_id' => $built['offer_id'], 'request_payload' => $built['payload'],
                'status' => $errors ? 'failed' : 'pending', 'errors' => $errors ?: null,
            ]);
            if ($errors) {
                $this->increment($run, false);
                continue;
            }
            $valid[] = compact('good', 'variation', 'built', 'item');
        }
        if (! $valid) return;

        if ($run->mode === 'products') {
            $response = $client->post('/v3/product/import', ['items' => array_column(array_column($valid, 'built'), 'payload')]);
            $taskId = (string) (data_get($response, 'result.task_id') ?? data_get($response, 'task_id') ?? '');
            foreach ($valid as $entry) $entry['item']->update(['status' => 'submitted', 'task_id' => $taskId, 'response_payload' => $response]);
            if ($taskId !== '') PollOzonImportTaskJob::dispatch($run->id, $taskId)->delay(now()->addSeconds(10));
            else foreach ($valid as $entry) $this->completeItem($run, $entry, true, $response);
            return;
        }

        if ($run->mode === 'prices') {
            $response = $client->post('/v1/product/import/prices', ['prices' => array_map(fn ($entry) => $builder->pricePayload($entry['good'], $entry['variation'], $account), $valid)]);
        } else {
            if (! $account->warehouse_id) throw new \RuntimeException('Для синхронизации остатков укажите warehouse_id Ozon.');
            $response = $client->post('/v2/products/stocks', ['stocks' => array_map(fn ($entry) => $builder->stock($entry['good'], $entry['variation'], $account), $valid)]);
        }
        foreach ($valid as $entry) {
            $errors = $this->responseErrorsForOffer($response, $entry['built']['offer_id']);
            $this->completeItem($run, $entry, empty($errors), $response, $errors);
        }
    }

    private function completeItem(ShopOzonSyncRun $run, array $entry, bool $success, array $response, array $errors = []): void
    {
        $entry['item']->update(['status' => $success ? 'completed' : 'failed', 'response_payload' => $response, 'errors' => $errors ?: null]);
        ShopOzonProductBinding::updateOrCreate(
            ['account_id' => $run->account_id, 'offer_id' => $entry['built']['offer_id']],
            ['good_id' => $entry['good']->id, 'variation_id' => $entry['variation']?->id, 'is_variation' => (bool) $entry['variation'], 'status' => $success ? 'synced' : 'error', 'payload_hash' => hash('sha256', json_encode($entry['built']['payload'])), 'errors' => $errors ?: null, 'last_synced_at' => now()]
        );
        $this->increment($run, $success);
    }

    private function responseErrorsForOffer(array $response, string $offerId): array
    {
        $items = collect(data_get($response, 'result', data_get($response, 'items', [])));
        if ($items->isAssoc()) $items = collect(data_get($response, 'result.items', []));
        $row = $items->first(fn ($item) => (string) data_get($item, 'offer_id') === $offerId);
        return collect(data_get($row, 'errors', []))->filter()->values()->all();
    }

    private function increment(ShopOzonSyncRun $run, bool $success): void
    {
        $run->increment('processed');
        $run->increment($success ? 'succeeded' : 'failed');
    }
}
