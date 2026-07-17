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
use Illuminate\Support\Facades\RateLimiter;
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
        if ($run->status === 'cancelled') return;
        $account = ShopYandexMarketAccount::findOrFail($run->account_id);
        $client = new YandexMarketClient($account);
        if (in_array($run->type, ['catalog_import', 'purge_catalog'], true) && $run->status !== 'pending') {
            // A retry can inherit the running state after a worker crash. Its remote
            // pagination starts from the beginning, therefore all counters must restart too.
            $run->update([
                'total' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'requests' => 0,
                'meta' => array_merge((array) $run->meta, ['phase' => 'collecting_offer_ids', 'found_offer_ids' => 0, 'catalog_pages' => 0]),
            ]);

            if ($run->type === 'purge_catalog') {
                ShopYandexMarketSyncItem::query()->where('run_id', $run->id)->delete();
            }
        }
        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            if ($run->type === 'purge_catalog') {
                $this->purgeCatalog($run, $account, $client);
                return;
            }
            if ($run->type === 'catalog_import') {
                $this->importCatalog($run, $account, $client, $resolver);
                return;
            }
            if (in_array($run->type, ['delete', 'hide'], true)) {
                $this->manageBindings($run, $account, $client);
                return;
            }
            if ($run->type === 'readback') {
                $this->readBackCards($run, $account, $client);
                $run->refresh();
                $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);

                return;
            }

            $rows = [];
            $mappings = $resolver->mappings($account);
            $resolver->query($account, $run->good_ids)->chunkById(100, function ($goods) use (&$rows, $resolver, $mappings, $account) {
                foreach ($goods as $good) {
                    $mapping = $resolver->mappingFor($good, $mappings, $account);
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
            if ($run->type === 'stocks' && ! ($row['mapping']?->campaign_id ?: $account->campaign_id)) $errors[] = 'Для профиля не выбран магазин/склад Маркета для передачи остатков.';
            $payload = match ($run->type) {
                'prices' => $builder->pricePayload($row['good'], $row['variation'], $account, $row['mapping']),
                'stocks' => $builder->stockPayload($row['good'], $row['variation'], $account),
                default => $built['offer_mapping'],
            };
            if ($run->type === 'products') $payload = $this->sanitizeProductPayload($payload);
            $item = ShopYandexMarketSyncItem::create([
                'run_id' => $run->id, 'good_id' => $row['good']->id, 'variation_id' => $row['variation']?->id,
                'offer_id' => $built['offer_id'], 'request_payload' => $payload,
                'status' => $errors ? 'failed' : 'pending', 'errors' => $errors ?: null,
            ]);
            if ($errors) { $this->increment($run, false); continue; }
            $valid[] = compact('row', 'built', 'item', 'payload');
        }
        if ($run->type === 'products') $valid = $this->excludeDuplicateVariationPayloads($run, $valid);
        if (! $valid) return;

        if ($run->type === 'stocks') {
            $this->sendStocksByCampaign($run, $account, $client, $valid);
            return;
        }

        $run->increment('requests');
        try {
            $response = match ($run->type) {
                'products' => $client->post("/v2/businesses/{$account->business_id}/offer-mappings/update", [
                    'offerMappings' => array_column($valid, 'payload'), 'onlyPartnerMediaContent' => false,
                ], ['language' => 'RU']),
                'prices' => $client->post("/v2/businesses/{$account->business_id}/offer-prices/updates", [
                    'offers' => array_column($valid, 'payload'),
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

        $baseErrors = $run->type === 'products' ? $this->marketErrorsByOffer($response) : [];
        $contentErrors = $run->type === 'products'
            ? $this->updateCategoryContent($run, $account, $client, $valid)
            : [];

        foreach ($valid as $entry) {
            $offerId = $entry['built']['offer_id'];
            $errors = array_values(array_unique(array_merge($baseErrors[$offerId] ?? [], $contentErrors[$offerId] ?? [])));
            $entry['item']->update([
                'status' => $errors ? 'failed' : 'completed',
                'response_payload' => $response,
                'errors' => $errors ?: null,
            ]);
            $bindingData = [
                'good_id' => $entry['row']['good']->id, 'variation_id' => $entry['row']['variation']?->id,
                'status' => $errors ? 'error' : ($run->type === 'products' ? 'submitted' : 'synced'),
                'errors' => $errors ?: null, 'last_synced_at' => now(),
            ];
            if ($run->type === 'products') {
                $bindingData['payload_hash'] = hash('sha256', json_encode($entry['payload']));
            }
            ShopYandexMarketProductBinding::updateOrCreate(
                ['account_id' => $account->id, 'offer_id' => $entry['built']['offer_id']],
                $bindingData,
            );
            $this->increment($run, ! $errors);
        }
    }

    /**
     * Final guard for payloads sent to Market. Category metadata is not always
     * consistent: a numeric field can be returned as TEXT, while the API still
     * rejects values such as "160 mm". The API must receive the pure number.
     */
    private function sanitizeProductPayload(array $payload): array
    {
        $parameters = (array) data_get($payload, 'offer.parameterValues', []);
        foreach ($parameters as $index => $parameter) {
            $value = $parameter['value'] ?? null;
            if (! is_string($value)) continue;

            $normalized = $this->measurementNumber($value);
            if ($normalized !== null) $parameters[$index]['value'] = $normalized;
        }

        if ($parameters) data_set($payload, 'offer.parameterValues', array_values($parameters));

        return $payload;
    }

    /**
     * Market compares the final parameter values, not the local variation text.
     * Reject a duplicate locally, with the actual Market fields in the error.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function excludeDuplicateVariationPayloads(ShopYandexMarketSyncRun $run, array $entries): array
    {
        $rejected = [];
        foreach (collect($entries)->groupBy(fn (array $entry) => (int) $entry['row']['good']->id) as $group) {
            if ($group->count() < 2) continue;

            $signatures = [];
            foreach ($group as $entry) {
                $axes = collect($entry['built']['display_parameters'] ?? [])
                    ->filter(fn (array $parameter) => ! empty($parameter['distinctive']) && ! empty($parameter['sent']))
                    ->map(fn (array $parameter) => [
                        'name' => (string) ($parameter['name'] ?? ''),
                        'value' => (string) ($parameter['value_id'] ?? $parameter['value'] ?? ''),
                    ])->values();

                if ($axes->isEmpty()) continue;
                $signature = $axes->map(fn (array $axis) => $axis['name'].'='.$axis['value'])->implode('|');
                if (! isset($signatures[$signature])) {
                    $signatures[$signature] = $entry;
                    continue;
                }

                $fields = $axes->map(fn (array $axis) => $axis['name'].' = '.$axis['value'])->implode('; ');
                $message = 'Дублируется комбинация отличительных характеристик Яндекс Маркета: '.$fields.'.';
                foreach ([$entry, $signatures[$signature]] as $duplicate) {
                    $itemId = (int) $duplicate['item']->id;
                    if (isset($rejected[$itemId])) continue;
                    $duplicate['item']->update(['status' => 'failed', 'errors' => [$message]]);
                    $this->increment($run, false);
                    $rejected[$itemId] = true;
                }
            }
        }

        return array_values(array_filter($entries, fn (array $entry) => ! isset($rejected[(int) $entry['item']->id])));
    }

    private function measurementNumber(string $value): ?string
    {
        $value = trim(str_replace("\xC2\xA0", ' ', $value));
        if (! preg_match('/^[-+]?\d+(?:[\.,]\d+)?\s*(?:mm|мм|cm|см|m|м|kg|кг|g|гр|г|ml|мл|l|л|w|вт)\.?$/ui', $value)) {
            return null;
        }

        preg_match('/[-+]?\d+(?:[\.,]\d+)?/u', $value, $match);

        return isset($match[0]) ? str_replace(',', '.', $match[0]) : null;
    }

    /**
     * The base mapping request creates the offer and its variation group. Send the
     * same validated category values through the dedicated content endpoint too:
     * this makes them visible in the Market card editor without relying on delayed
     * background processing of offer-mappings/update.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<int, string>>
     */
    private function updateCategoryContent(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client, array $entries): array
    {
        $offers = collect($entries)->map(function (array $entry) {
            $offer = (array) data_get($entry, 'payload.offer', []);
            $parameters = array_values((array) ($offer['parameterValues'] ?? []));
            if (! $parameters) return null;

            return [
                'offerId' => (string) ($offer['offerId'] ?? ''),
                'categoryId' => (int) ($offer['marketCategoryId'] ?? 0),
                'parameterValues' => $parameters,
            ];
        })->filter(fn ($offer) => $offer && $offer['offerId'] !== '' && $offer['categoryId'] > 0)->values();

        $errorsByOffer = [];
        foreach ($offers->chunk(100) as $chunk) {
            $run->increment('requests');
            try {
                $response = $client->post("/v2/businesses/{$account->business_id}/offer-cards/update", [
                    'offersContent' => $chunk->all(),
                ]);
                $errors = $this->marketErrorsByOffer($response, 'Яндекс Маркет не принял категорийную характеристику.');
                if (data_get($response, 'status') === 'ERROR') {
                    foreach ($chunk as $offer) {
                        $errorsByOffer[$offer['offerId']] = $errors[$offer['offerId']] ?? ['Маркет не применил категорийные характеристики.'];
                    }
                } else {
                    foreach ($errors as $offerId => $messages) $errorsByOffer[$offerId] = $messages;
                }
            } catch (Throwable $e) {
                $message = 'Не удалось применить категорийные характеристики: '.mb_substr($e->getMessage(), 0, 3500);
                foreach ($chunk as $offer) $errorsByOffer[$offer['offerId']] = [$message];
            }
        }

        return $errorsByOffer;
    }

    /** @return array<string, array<int, string>> */
    private function marketErrorsByOffer(array $response, string $fallback = 'Яндекс Маркет не принял данные карточки.'): array
    {
        return collect(data_get($response, 'results', []))->mapWithKeys(function ($result) use ($fallback) {
            $messages = collect($result['errors'] ?? [])
                ->map(fn ($error) => (string) ($error['message'] ?? $error['type'] ?? $fallback))
                ->filter()
                ->values()
                ->all();
            $offerId = (string) ($result['offerId'] ?? '');
            return $messages && $offerId !== '' ? [$offerId => $messages] : [];
        })->all();
    }

    private function sendStocksByCampaign(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client, array $valid): void
    {
        $groups = collect($valid)->groupBy(fn ($entry) => (int) ($entry['row']['mapping']?->campaign_id ?: $account->campaign_id));
        foreach ($groups as $campaignId => $entries) {
            try {
                $run->increment('requests');
                $response = $client->put("/v2/campaigns/{$campaignId}/offers/stocks", ['skus' => $entries->pluck('payload')->all()]);
                foreach ($entries as $entry) {
                    $entry['item']->update(['status' => 'completed', 'response_payload' => $response]);
                    ShopYandexMarketProductBinding::updateOrCreate(['account_id' => $account->id, 'offer_id' => $entry['built']['offer_id']], [
                        'good_id' => $entry['row']['good']->id, 'variation_id' => $entry['row']['variation']?->id,
                        'status' => 'synced', 'errors' => null, 'last_synced_at' => now(),
                    ]);
                    $this->increment($run, true);
                }
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 4000);
                foreach ($entries as $entry) {
                    $entry['item']->update(['status' => 'failed', 'errors' => [$message]]);
                    ShopYandexMarketProductBinding::updateOrCreate(['account_id' => $account->id, 'offer_id' => $entry['built']['offer_id']], [
                        'good_id' => $entry['row']['good']->id, 'variation_id' => $entry['row']['variation']?->id,
                        'status' => 'error', 'errors' => [$message],
                    ]);
                    $this->increment($run, false);
                }
                $run->update(['error_message' => $message]);
            }
        }
    }

    private function increment(ShopYandexMarketSyncRun $run, bool $success): void
    {
        $run->increment('processed');
        $run->increment($success ? 'succeeded' : 'failed');
    }

    private function manageBindings(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client): void
    {
        $bindingIds = (array) data_get($run->meta, 'binding_ids', []);
        $query = ShopYandexMarketProductBinding::query()->where('account_id', $account->id)->whereIn('id', $bindingIds)->orderBy('id');
        $run->update(['total' => (clone $query)->count()]);
        $query->chunkById(500, function ($bindings) use ($run, $account, $client) {
            $offerIds = $bindings->pluck('offer_id')->filter()->values()->all();
            if (! $offerIds) return;
            try {
                $run->increment('requests');
                $response = $run->type === 'delete'
                    ? $this->deleteCatalogOffers($account, $client, $offerIds)
                    : $client->post("/v2/campaigns/{$account->campaign_id}/hidden-offers", ['hiddenOffers' => array_map(fn ($offerId) => ['offerId' => $offerId], $offerIds)]);
                $notDeleted = $run->type === 'delete'
                    ? collect(data_get($response, 'result.notDeletedOfferIds', data_get($response, 'notDeletedOfferIds', [])))->map(fn ($value) => (string) (is_array($value) ? ($value['offerId'] ?? '') : $value))->filter()->all()
                    : [];
                foreach ($bindings as $binding) {
                    $failed = in_array($binding->offer_id, $notDeleted, true);
                    ShopYandexMarketSyncItem::create([
                        'run_id' => $run->id, 'good_id' => $binding->good_id, 'variation_id' => $binding->variation_id,
                        'offer_id' => $binding->offer_id, 'status' => $failed ? 'failed' : 'completed',
                        'response_payload' => $response,
                        'errors' => $failed ? ['Яндекс Маркет не удалил этот Offer ID.'] : null,
                    ]);
                    if ($failed) {
                        $binding->update(['status' => 'error', 'errors' => ['Яндекс Маркет не удалил этот Offer ID.']]);
                    } else {
                        $binding->update(['status' => $run->type === 'delete' ? 'deleted' : 'hidden', 'errors' => null, 'last_synced_at' => now()]);
                    }
                    $this->increment($run, ! $failed);
                }
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 4000);
                foreach ($bindings as $binding) {
                    ShopYandexMarketSyncItem::create([
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

    private function purgeCatalog(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client): void
    {
        $offerIds = [];
        $pageToken = null;
        do {
            if ($this->isCancelled($run)) return;
            $run->increment('requests');
            $query = ['language' => 'RU', 'limit' => 100];
            if ($pageToken) $query['pageToken'] = $pageToken;
            $response = $this->getCatalogPage($account, $client, $query);
            $items = (array) data_get($response, 'result.offerMappings', data_get($response, 'offerMappings', []));
            foreach ($items as $item) {
                $offerId = (string) data_get($item, 'offer.offerId', data_get($item, 'offerId', ''));
                if ($offerId !== '') $offerIds[] = $offerId;
            }
            $this->updateCatalogCollectionProgress($run, count($offerIds));
            $pageToken = data_get($response, 'result.paging.nextPageToken', data_get($response, 'paging.nextPageToken'));
        } while ($pageToken && ! empty($items));

        $offerIds = array_values(array_unique($offerIds));
        $run->update(['total' => count($offerIds), 'meta' => array_merge((array) $run->meta, ['phase' => 'deleting', 'found_offer_ids' => count($offerIds)])]);
        foreach (array_chunk($offerIds, 500) as $chunk) {
            if ($this->isCancelled($run)) return;
            try {
                $run->increment('requests');
                $response = $this->deleteCatalogOffers($account, $client, $chunk);
                $notDeleted = collect(data_get($response, 'result.notDeletedOfferIds', data_get($response, 'notDeletedOfferIds', [])))
                    ->map(fn ($value) => (string) (is_array($value) ? ($value['offerId'] ?? '') : $value))->filter()->all();
                foreach ($chunk as $offerId) {
                    $failed = in_array($offerId, $notDeleted, true);
                    ShopYandexMarketSyncItem::create([
                        'run_id' => $run->id, 'offer_id' => $offerId,
                        'status' => $failed ? 'failed' : 'completed', 'response_payload' => $response,
                        'errors' => $failed ? ['Яндекс Маркет не удалил этот Offer ID.'] : null,
                    ]);
                    $this->increment($run, ! $failed);
                }
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 4000);
                foreach ($chunk as $offerId) {
                    ShopYandexMarketSyncItem::create(['run_id' => $run->id, 'offer_id' => $offerId, 'status' => 'failed', 'errors' => [$message]]);
                    $this->increment($run, false);
                }
                $run->update(['error_message' => $message]);
            }
        }
        $run->refresh();
        if ($run->failed === 0) {
            ShopYandexMarketProductBinding::query()
                ->where('account_id', $account->id)
                ->update(['status' => 'deleted', 'errors' => null, 'last_synced_at' => now()]);
        }
        $run->update(['status' => $run->failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
    }

    private function importCatalog(ShopYandexMarketSyncRun $run, ShopYandexMarketAccount $account, YandexMarketClient $client, YandexMarketProductResolver $resolver): void
    {
        $pageToken = null;
        $foundOfferIds = 0;
        $run->update([
            'total' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'meta' => array_merge((array) $run->meta, ['phase' => 'collecting_catalog', 'found_offer_ids' => 0, 'catalog_pages' => 0]),
        ]);
        do {
            if ($this->isCancelled($run)) return;
            $run->increment('requests');
            $query = ['language' => 'RU', 'limit' => 100];
            if ($pageToken) $query['pageToken'] = $pageToken;
            $response = $this->getCatalogPage($account, $client, $query);
            $items = (array) data_get($response, 'result.offerMappings', data_get($response, 'offerMappings', []));
            foreach ($items as $remote) {
                $offerId = (string) data_get($remote, 'offer.offerId', data_get($remote, 'offerId', ''));
                if ($offerId === '') continue;
                $binding = ShopYandexMarketProductBinding::firstOrNew(['account_id' => $account->id, 'offer_id' => $offerId]);
                $binding->market_sku = data_get($remote, 'mapping.marketSku', data_get($remote, 'marketSku')) ?: $binding->market_sku;
                $binding->status = (string) data_get($remote, 'cardStatus', $binding->status ?: 'catalog_imported');
                $binding->remote_payload = $remote;
                $binding->remote_updated_at = now();
                $binding->last_synced_at = now();
                $binding->last_catalog_sync_run_id = $run->id;
                $binding->save();
                $foundOfferIds++;
            }
            $this->updateCatalogCollectionProgress($run, $foundOfferIds, 'collecting_catalog');
            $pageToken = data_get($response, 'result.paging.nextPageToken', data_get($response, 'paging.nextPageToken'));
        } while ($pageToken && ! empty($items));

        // Keep the local table as a mirror of the remote business catalogue.
        // Bindings not observed in this completed import no longer exist in Market.
        ShopYandexMarketProductBinding::query()
            ->where('account_id', $account->id)
            ->where(function ($query) use ($run) {
                $query->whereNull('last_catalog_sync_run_id')
                    ->orWhere('last_catalog_sync_run_id', '!=', $run->id);
            })
            ->delete();

        // The catalogue endpoint only returns the mapping. Card completion and stock are
        // provided by separate Market endpoints, so enrich the local mirror here.
        $this->refreshCatalogDetails($account, $client, $resolver);

        $catalogCount = ShopYandexMarketProductBinding::query()
            ->where('account_id', $account->id)
            ->where('last_catalog_sync_run_id', $run->id)
            ->count();
        $run->refresh();
        $run->update([
            'total' => $catalogCount,
            'processed' => $catalogCount,
            'succeeded' => $catalogCount,
            'status' => 'completed',
            'finished_at' => now(),
            'meta' => array_merge((array) $run->meta, ['phase' => 'completed', 'imported_offer_ids' => $catalogCount, 'found_offer_ids' => $catalogCount]),
        ]);
    }

    private function refreshCatalogDetails(ShopYandexMarketAccount $account, YandexMarketClient $client, YandexMarketProductResolver $resolver): void
    {
        $bindings = ShopYandexMarketProductBinding::query()
            ->where('account_id', $account->id)
            ->with(['good.categories:id', 'good.tags:id'])
            ->orderBy('id')
            ->get();

        foreach ($bindings->chunk(200) as $chunk) {
            $response = $client->post("/v2/businesses/{$account->business_id}/offer-cards", [
                'offerIds' => $chunk->pluck('offer_id')->values()->all(),
                'withRecommendations' => true,
            ]);
            $cards = collect(data_get($response, 'result.offerCards', []))->keyBy(fn ($item) => (string) data_get($item, 'offerId'));
            foreach ($chunk as $binding) {
                $card = $cards->get($binding->offer_id);
                if (! $card) continue;
                $binding->update([
                    'market_sku' => data_get($card, 'mapping.marketSku') ?: $binding->market_sku,
                    'status' => data_get($card, 'cardStatus', $binding->status),
                    'content_rating' => data_get($card, 'contentRating'),
                    'errors' => ($errors = collect(data_get($card, 'errors', []))->values()->all()) ?: null,
                    'warnings' => ($warnings = collect(data_get($card, 'warnings', []))->merge(data_get($card, 'recommendations', []))->values()->all()) ?: null,
                    'remote_payload' => array_merge((array) $binding->remote_payload, ['content_status' => $card]),
                    'remote_updated_at' => now(),
                ]);
            }
        }

        $mappings = $resolver->mappings($account);
        $byCampaign = $bindings->groupBy(function (ShopYandexMarketProductBinding $binding) use ($account, $mappings, $resolver) {
            $mapping = $binding->good ? $resolver->mappingFor($binding->good, $mappings, $account) : null;
            return (int) ($mapping?->campaign_id ?: $account->campaign_id);
        })->filter(fn ($group, $campaignId) => $campaignId > 0);

        foreach ($byCampaign as $campaignId => $campaignBindings) {
            foreach ($campaignBindings->chunk(500) as $chunk) {
                $response = $client->post("/v2/campaigns/{$campaignId}/offers/stocks", [
                    'offerIds' => $chunk->pluck('offer_id')->values()->all(),
                ]);
                $stocksByOffer = [];
                foreach ((array) data_get($response, 'result.warehouses', []) as $warehouse) {
                    $warehouseId = (int) ($warehouse['warehouseId'] ?? 0);
                    foreach ((array) ($warehouse['offers'] ?? []) as $offer) {
                        $offerId = (string) ($offer['offerId'] ?? '');
                        if ($offerId === '') continue;
                        $available = collect($offer['stocks'] ?? [])->where('type', 'AVAILABLE')->sum(fn ($stock) => (int) ($stock['count'] ?? 0));
                        $stocksByOffer[$offerId][$warehouseId] = [
                            'available' => $available,
                            'stocks' => $offer['stocks'] ?? [],
                            'updated_at' => $offer['updatedAt'] ?? null,
                        ];
                    }
                }
                foreach ($chunk as $binding) {
                    $details = $stocksByOffer[$binding->offer_id] ?? [];
                    $binding->update([
                        'market_stock' => collect($details)->sum('available'),
                        'market_stock_details' => $details ?: null,
                        'market_stock_updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * The Marketplace API allows no more than 100 catalog pages per minute.
     * Keep a margin so a long catalogue import can finish without a 420 response.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function getCatalogPage(ShopYandexMarketAccount $account, YandexMarketClient $client, array $query): array
    {
        $rateKey = "yandex-market:offer-mappings:{$account->business_id}";
        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            while (RateLimiter::tooManyAttempts($rateKey, 85)) {
                sleep(max(1, RateLimiter::availableIn($rateKey)));
            }

            RateLimiter::hit($rateKey, 60);

            try {
                $response = $client->post(
                    "/v2/businesses/{$account->business_id}/offer-mappings",
                    ['archived' => false],
                    $query,
                );

                // 85 requests/minute leaves room for other Marketplace operations.
                usleep(750000);

                return $response;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! str_contains($exception->getMessage(), 'HTTP 420')) {
                    throw $exception;
                }

                // The remote minute window may have been spent by an earlier run.
                RateLimiter::clear($rateKey);
                sleep(61);
            }
        }

        throw $lastException ?? new \RuntimeException('Не удалось получить страницу каталога Яндекс Маркета.');
    }

    private function updateCatalogCollectionProgress(ShopYandexMarketSyncRun $run, int $foundOfferIds, string $phase = 'collecting_offer_ids'): void
    {
        $run->update([
            'meta' => array_merge((array) $run->meta, [
                'phase' => $phase,
                'found_offer_ids' => $foundOfferIds,
                'catalog_pages' => ((int) data_get($run->meta, 'catalog_pages', 0)) + 1,
            ]),
        ]);
    }

    private function isCancelled(ShopYandexMarketSyncRun $run): bool
    {
        return $run->fresh()?->status === 'cancelled';
    }

    /**
     * The delete endpoint consumes one rate-limit point per Offer ID, not per request.
     * Keep under 5,000 points/minute even when the API accepts a larger request batch.
     *
     * @param array<int, string> $offerIds
     * @return array<string, mixed>
     */
    private function deleteCatalogOffers(ShopYandexMarketAccount $account, YandexMarketClient $client, array $offerIds): array
    {
        $rateKey = "yandex-market:offer-mappings-delete:{$account->business_id}";
        $points = count($offerIds);
        $limit = 4500;
        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            while (RateLimiter::attempts($rateKey) + $points > $limit) {
                sleep(max(1, RateLimiter::availableIn($rateKey)));
            }

            RateLimiter::increment($rateKey, 60, $points);

            try {
                return $client->post(
                    "/v2/businesses/{$account->business_id}/offer-mappings/delete",
                    ['offerIds' => $offerIds],
                );
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! str_contains($exception->getMessage(), 'HTTP 420')) {
                    throw $exception;
                }

                RateLimiter::clear($rateKey);
                sleep(61);
            }
        }

        throw $lastException ?? new \RuntimeException('Не удалось удалить товары из каталога Яндекс Маркета.');
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
