<?php

namespace App\Jobs;

use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonProductBinding;
use App\Models\ShopOzonSyncItem;
use App\Models\ShopOzonSyncRun;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use App\Services\Ozon\OzonPostProductSyncScheduler;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

class ProcessOzonSyncRunJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 2;

    public function __construct(public int $runId) {}

    public function handle(
        OzonProductPayloadBuilder $builder,
        OzonProductResolver $resolver,
        OzonPostProductSyncScheduler $postProductSyncScheduler,
    ): void
    {
        $run = ShopOzonSyncRun::findOrFail($this->runId);
        $account = ShopOzonAccount::findOrFail($run->account_id);
        $client = new OzonSellerClient($account);
        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            if ($run->mode === 'archive') {
                $this->archiveBindings($run, $account, $client);
                return;
            }
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
            if ($run->mode === 'products') {
                $postProductSyncScheduler->scheduleStocks($run);
            }
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function rows(ShopOzonSyncRun $run, ShopOzonAccount $account, OzonProductResolver $resolver): array
    {
        $query = $resolver->query($account, $run->good_ids);
        $mappings = $resolver->mappings($account);
        if ($mappings->isEmpty() || ! $mappings->contains(fn ($mapping) => $resolver->effectiveSelectionTagId($mapping, $account))) {
            throw new \RuntimeException('Для активного профиля Ozon укажите тег отбора товаров.');
        }

        $rows = [];
        $query->chunkById(200, function ($goods) use (&$rows, $mappings, $resolver, $account) {
            foreach ($goods as $good) {
                $mapping = $resolver->mappingFor($good, $mappings, $account);
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
            if ($run->mode === 'stocks' && (! $mapping || ! $builder->stock($good, $variation, $account, $mapping)['warehouse_id'])) {
                $errors[] = 'Для профиля категории не указан склад Ozon для остатков.';
            }
            if ($run->mode === 'prices' && (float) $builder->pricePayload($good, $variation, $account, $mapping)['price'] <= 0) {
                $errors[] = 'Цена должна быть больше нуля.';
            }
            $requestPayload = match ($run->mode) {
                'prices' => $builder->pricePayload($good, $variation, $account, $mapping),
                'stocks' => $builder->stock($good, $variation, $account, $mapping),
                default => $built['payload'],
            };
            $item = ShopOzonSyncItem::create([
                'run_id' => $run->id, 'good_id' => $good->id, 'variation_id' => $variation?->id,
                'offer_id' => $built['offer_id'], 'request_payload' => $requestPayload,
                'status' => $errors ? 'failed' : 'pending', 'errors' => $errors ?: null,
            ]);
            if ($errors) {
                $this->increment($run, false);
                continue;
            }
            $valid[] = compact('good', 'variation', 'mapping', 'built', 'item');
        }
        if (! $valid) return;

        if ($run->mode === 'products') {
            $response = $client->post('/v3/product/import', ['items' => array_column(array_column($valid, 'built'), 'payload')]);
            $taskId = (string) (data_get($response, 'result.task_id') ?? data_get($response, 'task_id') ?? '');
            foreach ($valid as $entry) {
                $entry['item']->update(['status' => 'submitted', 'task_id' => $taskId, 'response_payload' => $response]);
                $this->storeBindingSnapshot($run, $entry, 'submitted');
            }
            if ($taskId !== '') PollOzonImportTaskJob::dispatch($run->id, $taskId)->delay(now()->addSeconds(10));
            else foreach ($valid as $entry) $this->completeItem($run, $entry, true, $response);
            return;
        }

        if ($run->mode === 'prices') {
            $response = $client->post('/v1/product/import/prices', ['prices' => array_map(fn ($entry) => $builder->pricePayload($entry['good'], $entry['variation'], $account, $entry['mapping']), $valid)]);
        } else {
            $response = $client->post('/v2/products/stocks', ['stocks' => array_map(fn ($entry) => $builder->stock($entry['good'], $entry['variation'], $account, $entry['mapping']), $valid)]);
        }
        foreach ($valid as $entry) {
            $errors = $this->responseErrorsForOffer($response, $entry['built']['offer_id']);
            $this->completeItem($run, $entry, empty($errors), $response, $errors);
        }
    }

    private function completeItem(ShopOzonSyncRun $run, array $entry, bool $success, array $response, array $errors = []): void
    {
        $entry['item']->update(['status' => $success ? 'completed' : 'failed', 'response_payload' => $response, 'errors' => $errors ?: null]);
        $this->storeBindingSnapshot($run, $entry, $success ? 'synced' : 'error', $errors);
        $this->increment($run, $success);
    }

    private function storeBindingSnapshot(ShopOzonSyncRun $run, array $entry, string $status, array $errors = []): void
    {
        $bindingData = [
            'good_id' => $entry['good']->id,
            'variation_id' => $entry['variation']?->id,
            'is_variation' => (bool) $entry['variation'],
            'status' => $status,
            'errors' => $errors ?: null,
            'last_synced_at' => now(),
        ];
        if ($run->mode === 'products') {
            $bindingData += [
                'payload_hash' => hash('sha256', json_encode($entry['built']['payload'])),
                'synced_attributes' => $entry['built']['display_attributes'],
                'synced_variation_attributes' => $entry['built']['variation_attributes'],
            ];
        }

        ShopOzonProductBinding::updateOrCreate(
            ['account_id' => $run->account_id, 'offer_id' => $entry['built']['offer_id']],
            $bindingData,
        );
    }

    private function responseErrorsForOffer(array $response, string $offerId): array
    {
        $rawItems = data_get($response, 'result', data_get($response, 'items', []));
        if (is_array($rawItems) && Arr::isAssoc($rawItems)) {
            $rawItems = array_key_exists('offer_id', $rawItems)
                ? [$rawItems]
                : data_get($response, 'result.items', data_get($response, 'items', []));
        }
        $items = collect(is_array($rawItems) ? $rawItems : []);
        $row = $items->first(fn ($item) => (string) data_get($item, 'offer_id') === $offerId);
        if (! is_array($row)) {
            return [['message' => 'Ozon не вернул результат обновления для offer_id '.$offerId.'.']];
        }

        $errors = collect(data_get($row, 'errors', []))->filter()->values();
        if (array_key_exists('updated', $row) && ! filter_var($row['updated'], FILTER_VALIDATE_BOOL)) {
            $errors->push(['message' => 'Ozon не подтвердил обновление данных оффера.']);
        }

        return $errors->all();
    }

    private function increment(ShopOzonSyncRun $run, bool $success): void
    {
        $run->increment('processed');
        $run->increment($success ? 'succeeded' : 'failed');
    }

    private function archiveBindings(ShopOzonSyncRun $run, ShopOzonAccount $account, OzonSellerClient $client): void
    {
        $query = ShopOzonProductBinding::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $run->binding_ids ?? [])
            ->orderBy('id');
        $run->update(['total' => (clone $query)->count()]);

        $query->chunkById(100, function ($bindings) use ($run, $account, $client) {
            $available = $bindings->filter(fn ($binding) => filled($binding->product_id));
            foreach ($bindings->diff($available) as $binding) {
                ShopOzonSyncItem::create([
                    'run_id' => $run->id, 'good_id' => $binding->good_id, 'variation_id' => $binding->variation_id,
                    'offer_id' => $binding->offer_id, 'status' => 'failed',
                    'errors' => ['Не найден Ozon Product ID. Сначала выполните «Обновить данные Ozon».'],
                ]);
                $this->increment($run, false);
            }
            if ($available->isEmpty()) return;

            try {
                $run->increment('requests');
                $response = $client->post('/v1/product/archive', ['product_id' => $available->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all()]);
                foreach ($available as $binding) {
                    ShopOzonSyncItem::create([
                        'run_id' => $run->id, 'good_id' => $binding->good_id, 'variation_id' => $binding->variation_id,
                        'offer_id' => $binding->offer_id, 'status' => 'completed', 'response_payload' => $response,
                    ]);
                    $binding->update(['status' => 'archived', 'errors' => null, 'last_synced_at' => now()]);
                    $this->increment($run, true);
                }
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 4000);
                foreach ($available as $binding) {
                    ShopOzonSyncItem::create([
                        'run_id' => $run->id, 'good_id' => $binding->good_id, 'variation_id' => $binding->variation_id,
                        'offer_id' => $binding->offer_id, 'status' => 'failed', 'errors' => [$message],
                    ]);
                    $binding->update(['status' => 'error', 'errors' => [$message]]);
                    $this->increment($run, false);
                }
                $run->update(['error_message' => $message]);
            }
        });
        $run->refresh();
        $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
    }
}
