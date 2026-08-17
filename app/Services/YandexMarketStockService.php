<?php

namespace App\Services;

use App\Jobs\ProcessYandexMarketStockSyncJob;
use App\Models\Setting;
use App\Models\ShopYandexMarketSyncRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class YandexMarketStockService
{
    private const BATCH_SIZE = 2000;

    public function getSettings(): array
    {
        $settings = Setting::where('group', 'yandex_market')
            ->whereIn('key', [
                'yandex_market_campaign_id',
                'yandex_market_auth_token',
                'yandex_market_auth_type',
                'yandex_market_stocks_last_sync_at',
                'yandex_market_stocks_last_sync_result',
                'yandex_market_weight_multiplier',
                'yandex_market_length_multiplier',
                'yandex_market_width_multiplier',
                'yandex_market_height_multiplier',
            ])
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        return [
            'campaign_id' => (string) ($settings['yandex_market_campaign_id'] ?? ''),
            'auth_token' => (string) ($settings['yandex_market_auth_token'] ?? ''),
            'auth_type' => (string) ($settings['yandex_market_auth_type'] ?? 'api_key'),
            'last_sync_at' => $settings['yandex_market_stocks_last_sync_at'] ?? null,
            'last_sync_result' => $settings['yandex_market_stocks_last_sync_result'] ?? null,
            'dimension_multipliers' => [
                'weight' => $this->normalizeMultiplier($settings['yandex_market_weight_multiplier'] ?? 1),
                'length' => $this->normalizeMultiplier($settings['yandex_market_length_multiplier'] ?? 1),
                'width' => $this->normalizeMultiplier($settings['yandex_market_width_multiplier'] ?? 1),
                'height' => $this->normalizeMultiplier($settings['yandex_market_height_multiplier'] ?? 1),
            ],
        ];
    }

    public function saveSettings(array $data): array
    {
        $this->saveSetting('yandex_market_campaign_id', 'Campaign ID Яндекс Маркета', $data['campaign_id'] ?? '', 'string');
        $this->saveSetting('yandex_market_auth_token', 'Токен API Яндекс Маркета', $data['auth_token'] ?? '', 'password');
        $this->saveSetting('yandex_market_auth_type', 'Тип авторизации API Яндекс Маркета', $data['auth_type'] ?? 'api_key', 'string');
        $dimensionMultipliers = (array) ($data['dimension_multipliers'] ?? []);
        foreach (['weight', 'length', 'width', 'height'] as $field) {
            $this->saveSetting(
                "yandex_market_{$field}_multiplier",
                "Множитель {$field} для Яндекс Маркета",
                $this->normalizeMultiplier($dimensionMultipliers[$field] ?? 1),
                'number'
            );
        }

        return $this->getSettings();
    }

    public function syncStocks(): array
    {
        $run = $this->startStockSync();

        return $this->serializeRun($run);
    }

    public function startStockSync(?int $userId = null): ShopYandexMarketSyncRun
    {
        $active = ShopYandexMarketSyncRun::query()
            ->whereIn('type', ['stocks', 'stock_verification'])
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        if ($active) {
            return $active;
        }

        $feedPath = $this->getFeedPath();
        if (! $feedPath) {
            throw new \RuntimeException('Файл goods_feed.xml не найден. Сначала сгенерируйте YML-фид.');
        }

        $run = ShopYandexMarketSyncRun::create([
            'user_id' => $userId,
            'type' => 'stocks',
            'status' => 'pending',
            'total' => $this->countFeedStocks($feedPath),
            'meta' => ['feed_updated_at' => date(DATE_ATOM, filemtime($feedPath) ?: time())],
        ]);

        ProcessYandexMarketStockSyncJob::dispatch($run->id);

        return $run;
    }

    public function startStockVerification(?int $userId = null): ShopYandexMarketSyncRun
    {
        $active = ShopYandexMarketSyncRun::query()
            ->whereIn('type', ['stocks', 'stock_verification'])
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();
        if ($active) return $active;

        $feedPath = $this->getFeedPath();
        if (! $feedPath) throw new \RuntimeException('Файл goods_feed.xml не найден.');

        $run = ShopYandexMarketSyncRun::create([
            'user_id' => $userId,
            'type' => 'stock_verification',
            'status' => 'pending',
            'total' => $this->countFeedStocks($feedPath),
            'meta' => ['matched' => 0, 'mismatched' => 0, 'missing' => 0, 'items' => []],
        ]);
        ProcessYandexMarketStockSyncJob::dispatch($run->id);

        return $run;
    }

    public function processStockVerificationRun(ShopYandexMarketSyncRun $run): void
    {
        $settings = $this->getSettings();
        $campaignId = trim((string) $settings['campaign_id']);
        $token = trim((string) $settings['auth_token']);
        if ($campaignId === '' || ! ctype_digit($campaignId)) throw new \RuntimeException('Не указан корректный campaignId Яндекс Маркета.');
        if ($token === '') throw new \RuntimeException('Не указан токен API Яндекс Маркета.');

        $headers = $this->apiHeaders($settings);
        $endpoint = "https://api.partner.market.yandex.ru/v2/campaigns/{$campaignId}/offers/stocks";
        $feedPath = $this->getFeedPath();
        if (! $feedPath) throw new \RuntimeException('Файл goods_feed.xml не найден.');

        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);
        $meta = ['matched' => 0, 'mismatched' => 0, 'missing' => 0, 'items' => []];
        $errors = [];
        $requests = 0;

        foreach ($this->stockPayloadChunksFromFeed($feedPath, now()->toIso8601String()) as $feedChunk) {
            foreach (array_chunk($feedChunk, 500) as $chunk) {
                $requests++;
                $expected = collect($chunk)->mapWithKeys(fn ($row) => [(string) $row['sku'] => (int) $row['items'][0]['count']])->all();
                $response = Http::timeout(60)->retry(2, 500)->withHeaders($headers)->post($endpoint, [
                    'offerIds' => array_keys($expected),
                    'withTurnover' => false,
                ]);
                if (! $response->successful()) {
                    $errors[] = ['batch' => $requests, 'status' => $response->status(), 'body' => $response->json() ?: $response->body()];
                    $run->increment('processed', count($chunk));
                    $run->increment('failed', count($chunk));
                    continue;
                }

                $actual = [];
                foreach ((array) data_get($response->json(), 'result.warehouses', []) as $warehouse) {
                    foreach ((array) ($warehouse['offers'] ?? []) as $offer) {
                        $sku = (string) ($offer['offerId'] ?? '');
                        $fit = collect((array) ($offer['stocks'] ?? []))->where('type', 'FIT')->sum(fn ($stock) => (int) ($stock['count'] ?? 0));
                        $actual[$sku] = max($actual[$sku] ?? 0, $fit);
                    }
                }

                foreach ($expected as $sku => $count) {
                    $status = ! array_key_exists($sku, $actual) ? 'missing' : ($actual[$sku] === $count ? 'matched' : 'mismatched');
                    $meta[$status]++;
                    if ($status !== 'matched' && count($meta['items']) < 100) {
                        $meta['items'][] = ['sku' => $sku, 'expected' => $count, 'actual' => $actual[$sku] ?? null, 'status' => $status];
                    }
                    $run->increment('processed');
                    $run->increment($status === 'matched' ? 'succeeded' : 'failed');
                }
                $run->update(['requests' => $requests, 'meta' => $meta, 'errors' => $errors ?: null]);
            }
        }

        $run->refresh();
        $run->update([
            'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
            'finished_at' => now(),
            'requests' => $requests,
            'meta' => $meta,
            'errors' => $errors ?: null,
        ]);
    }

    public function processStockRun(ShopYandexMarketSyncRun $run): void
    {
        $settings = $this->getSettings();
        $campaignId = trim((string) $settings['campaign_id']);
        $token = trim((string) $settings['auth_token']);
        if ($campaignId === '' || ! ctype_digit($campaignId)) {
            throw new \RuntimeException('Не указан корректный campaignId Яндекс Маркета.');
        }

        if ($token === '') {
            throw new \RuntimeException('Не указан токен API Яндекс Маркета.');
        }

        $endpoint = "https://api.partner.market.yandex.ru/v2/campaigns/{$campaignId}/offers/stocks";
        $headers = $this->apiHeaders($settings);

        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);
        $totalSent = (int) $run->succeeded;
        $requests = (int) $run->requests;
        $errors = (array) ($run->errors ?? []);
        $updatedAt = now()->toIso8601String();

        $feedPath = $this->getFeedPath();
        if (! $feedPath) {
            throw new \RuntimeException('Файл goods_feed.xml не найден. Сначала сгенерируйте YML-фид.');
        }

        foreach ($this->stockPayloadChunksFromFeed($feedPath, $updatedAt) as $skus) {
            if (empty($skus)) {
                continue;
            }

            $requests++;
            $response = Http::timeout(60)
                ->retry(2, 500)
                ->withHeaders($headers)
                ->put($endpoint, ['skus' => $skus]);

            if (! $response->successful()) {
                $batchError = [
                    'batch' => $requests,
                    'status' => $response->status(),
                    'body' => $response->json() ?: $response->body(),
                    'sku_from' => $skus[0]['sku'] ?? null,
                    'sku_to' => $skus[array_key_last($skus)]['sku'] ?? null,
                ];
                $errors[] = $batchError;
                $run->increment('processed', count($skus));
                $run->increment('failed', count($skus));
            } else {
                $totalSent += count($skus);
                $run->increment('processed', count($skus));
                $run->increment('succeeded', count($skus));
            }
            $run->update(['requests' => $requests, 'errors' => $errors ?: null]);
        }

        $result = [
            'success' => empty($errors),
            'sent' => $totalSent,
            'requests' => $requests,
            'errors' => $errors,
            'synced_at' => now()->toDateTimeString(),
        ];

        if ($requests === 0) {
            throw new \RuntimeException('В goods_feed.xml не найдено offer с тегом count.');
        }

        $this->saveSetting('yandex_market_stocks_last_sync_at', 'Последняя отправка остатков в Яндекс Маркет', $result['synced_at'], 'string');
        $this->saveSetting('yandex_market_stocks_last_sync_result', 'Результат последней отправки остатков в Яндекс Маркет', json_encode($result, JSON_UNESCAPED_UNICODE), 'json');
        $run->refresh();
        $run->update([
            'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
            'finished_at' => now(),
            'meta' => array_merge((array) $run->meta, ['accepted_at' => now()->toIso8601String()]),
        ]);
    }

    public function recentRuns(int $limit = 10): array
    {
        return ShopYandexMarketSyncRun::query()->latest()->limit($limit)->get()->map(fn ($run) => $this->serializeRun($run))->all();
    }

    public function serializeRun(ShopYandexMarketSyncRun $run): array
    {
        $total = max(0, (int) $run->total);
        $processed = max(0, (int) $run->processed);

        return [
            'id' => $run->id,
            'type' => $run->type,
            'status' => $run->status,
            'total' => $total,
            'processed' => $processed,
            'succeeded' => (int) $run->succeeded,
            'failed' => (int) $run->failed,
            'requests' => (int) $run->requests,
            'progress' => $total > 0 ? min(100, round($processed * 100 / $total, 1)) : 0,
            'errors' => $run->errors ?: [],
            'meta' => $run->meta ?: [],
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    public function getFeedPath(): ?string
    {
        $relativePath = 'exports/goods_feed.xml';

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->path($relativePath);
        }

        return null;
    }

    private function countFeedStocks(string $feedPath): int
    {
        $count = 0;
        foreach ($this->stockPayloadChunksFromFeed($feedPath, now()->toIso8601String()) as $chunk) {
            $count += count($chunk);
        }

        return $count;
    }

    private function stockPayloadChunksFromFeed(string $feedPath, string $updatedAt): \Generator
    {
        $reader = new \XMLReader();
        if (! $reader->open($feedPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Не удалось открыть goods_feed.xml для чтения.');
        }

        $buffer = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'offer') {
                    continue;
                }

                $sku = trim((string) $reader->getAttribute('id'));
                if ($sku === '') {
                    continue;
                }

                $offerXml = $reader->readOuterXML();
                if ($offerXml === '') {
                    continue;
                }

                $offer = @simplexml_load_string($offerXml);
                if (! $offer || ! isset($offer->count)) {
                    continue;
                }

                $count = max(0, (int) trim((string) $offer->count));
                $buffer[] = [
                    'sku' => $sku,
                    'items' => [
                        [
                            'count' => $count,
                            'updatedAt' => $updatedAt,
                        ],
                    ],
                ];

                if (count($buffer) >= self::BATCH_SIZE) {
                    yield $buffer;
                    $buffer = [];
                }
            }
        } finally {
            $reader->close();
        }

        if (! empty($buffer)) {
            yield $buffer;
        }
    }

    private function saveSetting(string $key, string $name, $value, string $type): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            [
                'name' => $name,
                'value' => $value,
                'default_value' => null,
                'type' => $type,
                'group' => 'yandex_market',
                'description' => null,
            ]
        );
    }

    private function normalizeMultiplier($value): float
    {
        $number = (float) str_replace(',', '.', (string) $value);

        return $number > 0 ? $number : 1.0;
    }

    private function apiHeaders(array $settings): array
    {
        $authType = ($settings['auth_type'] ?? 'api_key') === 'oauth' ? 'oauth' : 'api_key';
        $token = trim((string) ($settings['auth_token'] ?? ''));

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($authType === 'api_key') {
            $headers['Api-Key'] = $token;

            return $headers;
        }

        if (! preg_match('/^(OAuth|Bearer)\s+/i', $token)) {
            $token = 'OAuth '.$token;
        }
        $headers['Authorization'] = $token;

        return $headers;
    }
}
