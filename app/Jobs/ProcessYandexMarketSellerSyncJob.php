<?php

namespace App\Jobs;

use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketProductBinding;
use App\Models\ShopYandexMarketSyncItem;
use App\Models\ShopYandexMarketSyncRun;
use App\Services\YandexMarket\YandexMarketClient;
use App\Services\YandexMarket\YandexMarketPayloadBuilder;
use App\Services\YandexMarket\YandexMarketProductResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessYandexMarketSellerSyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;
    public int $tries = 2;

    public function __construct(public int $runId) {}

    public function handle(YandexMarketPayloadBuilder $builder, YandexMarketProductResolver $resolver): void
    {
        $run = ShopYandexMarketSyncRun::findOrFail($this->runId);
        $account = ShopYandexMarketAccount::findOrFail($run->account_id);
        $client = new YandexMarketClient($account);
        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            if ($run->type === 'readback') {
                $this->readBackCards($run, $account, $client);
                $run->refresh();
                $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);

                return;
            }

            $rows = [];
            $mappings = $resolver->mappings($account);
            $resolver->query($account, $run->good_ids)->chunkById(100, function ($goods) use (&$rows, $resolver, $mappings) {
                foreach ($goods as $good) {
                    $mapping = $resolver->mappingFor($good, $mappings);
                    foreach ($resolver->rowsForGood($good, $mapping) as $row) $rows[] = $row;
                }
            });
            $run->update(['total' => count($rows)]);

            foreach (array_chunk($rows, $run->type === 'stocks' ? 2000 : 100) as $chunk) {
                $this->sendChunk($run, $account, $client, $builder, $chunk);
            }

            $run->refresh();
            $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function sendChunk(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client, YandexMarketPayloadBuilder $builder, array $chunk): void
    {
        $valid = [];
        foreach ($chunk as $row) {
            $built = $builder->build($row['good'], $row['variation'], $account, $row['mapping']);
            $errors = $run->type === 'products' ? array_values(array_unique(array_merge($built['errors'], $row['row_errors']))) : [];
            if (! $row['mapping']) $errors[] = 'Для категории товара не настроен профиль Яндекс Маркета.';
            $payload = match ($run->type) {
                'prices' => $builder->pricePayload($row['good'], $row['variation'], $account, $row['mapping']),
                'stocks' => $builder->stockPayload($row['good'], $row['variation'], $account),
                default => $built['offer_mapping'],
            };
            $item = ShopYandexMarketSyncItem::create([
                'run_id' => $run->id, 'good_id' => $row['good']->id, 'variation_id' => $row['variation']?->id,
                'offer_id' => $built['offer_id'], 'request_payload' => $payload,
                'status' => $errors ? 'failed' : 'pending', 'errors' => $errors ?: null,
            ]);
            if ($errors) { $this->increment($run, false); continue; }
            $valid[] = compact('row', 'built', 'item', 'payload');
        }
        if (! $valid) return;

        $run->increment('requests');
        try {
            $response = match ($run->type) {
                'products' => $client->post("/v2/businesses/{$account->business_id}/offer-mappings/update", [
                    'offerMappings' => array_column($valid, 'payload'), 'onlyPartnerMediaContent' => false,
                ], ['language' => 'RU']),
                'prices' => $client->post("/v2/businesses/{$account->business_id}/offer-prices/updates", [
                    'offers' => array_column($valid, 'payload'),
                ]),
                'stocks' => $client->put("/v2/campaigns/{$account->campaign_id}/offers/stocks", [
                    'skus' => array_column($valid, 'payload'),
                ]),
                default => throw new \RuntimeException('Неизвестный тип синхронизации.'),
            };
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 4000);
            foreach ($valid as $entry) {
                $entry['item']->update(['status' => 'failed', 'errors' => [$message]]);
                ShopYandexMarketProductBinding::updateOrCreate(
                    ['account_id' => $account->id, 'offer_id' => $entry['built']['offer_id']],
                    [
                        'good_id' => $entry['row']['good']->id,
                        'variation_id' => $entry['row']['variation']?->id,
                        'status' => 'error',
                        'errors' => [$message],
                    ],
                );
                $this->increment($run, false);
            }
            $run->update(['error_message' => $message]);

            return;
        }

        foreach ($valid as $entry) {
            $entry['item']->update(['status' => 'completed', 'response_payload' => $response]);
            $bindingData = [
                'good_id' => $entry['row']['good']->id, 'variation_id' => $entry['row']['variation']?->id,
                'status' => $run->type === 'products' ? 'submitted' : 'synced',
                'errors' => null, 'last_synced_at' => now(),
            ];
            if ($run->type === 'products') {
                $bindingData['payload_hash'] = hash('sha256', json_encode($entry['payload']));
            }
            ShopYandexMarketProductBinding::updateOrCreate(
                ['account_id' => $account->id, 'offer_id' => $entry['built']['offer_id']],
                $bindingData,
            );
            $this->increment($run, true);
        }
    }

    private function increment(ShopYandexMarketSyncRun $run, bool $success): void
    {
        $run->increment('processed');
        $run->increment($success ? 'succeeded' : 'failed');
    }

    private function readBackCards(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client): void
    {
        $query = ShopYandexMarketProductBinding::query()->where('account_id', $account->id)->orderBy('id');
        $run->update(['total' => (clone $query)->count()]);

        $query->chunkById(200, function ($bindings) use ($run, $account, $client) {
            $run->increment('requests');
            try {
                $response = $client->post("/v2/businesses/{$account->business_id}/offer-cards", [
                    'offerIds' => $bindings->pluck('offer_id')->values()->all(),
                    'withRecommendations' => true,
                ]);
                $cards = collect(data_get($response, 'result.offerCards', []))->keyBy(
                    fn ($item) => (string) data_get($item, 'offerId')
                );

                foreach ($bindings as $binding) {
                    $card = $cards->get($binding->offer_id);
                    if (! $card) {
                        $this->recordReadbackFailure($run, $binding, 'Маркет не вернул карточку для этого Offer ID.');
                        continue;
                    }
                    $errors = collect(data_get($card, 'errors', []))->values()->all();
                    $warnings = collect(data_get($card, 'warnings', []))
                        ->merge(data_get($card, 'recommendations', []))->values()->all();
                    $binding->update([
                        'market_sku' => data_get($card, 'mapping.marketSku'),
                        'status' => data_get($card, 'cardStatus', $binding->status),
                        'content_rating' => data_get($card, 'contentRating'),
                        'errors' => $errors ?: null,
                        'warnings' => $warnings ?: null,
                        'remote_payload' => $card,
                        'remote_updated_at' => now(),
                    ]);
                    ShopYandexMarketSyncItem::create([
                        'run_id' => $run->id,
                        'good_id' => $binding->good_id,
                        'variation_id' => $binding->variation_id,
                        'offer_id' => $binding->offer_id,
                        'status' => $errors ? 'failed' : 'completed',
                        'response_payload' => $card,
                        'errors' => $errors ?: null,
                    ]);
                    $this->increment($run, empty($errors));
                }
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 4000);
                foreach ($bindings as $binding) {
                    $this->recordReadbackFailure($run, $binding, $message);
                }
                $run->update(['error_message' => $message]);
            }
        });
    }

    private function recordReadbackFailure(ShopYandexMarketSyncRun $run, ShopYandexMarketProductBinding $binding, string $message): void
    {
        $binding->update(['status' => 'error', 'errors' => [$message]]);
        ShopYandexMarketSyncItem::create([
            'run_id' => $run->id,
            'good_id' => $binding->good_id,
            'variation_id' => $binding->variation_id,
            'offer_id' => $binding->offer_id,
            'status' => 'failed',
            'errors' => [$message],
        ]);
        $this->increment($run, false);
    }
}
