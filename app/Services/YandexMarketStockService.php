<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\ShopGood;
use Illuminate\Support\Facades\Http;

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
        ];
    }

    public function saveSettings(array $data): array
    {
        $this->saveSetting('yandex_market_campaign_id', 'Campaign ID Яндекс Маркета', $data['campaign_id'] ?? '', 'string');
        $this->saveSetting('yandex_market_auth_token', 'Токен API Яндекс Маркета', $data['auth_token'] ?? '', 'password');
        $this->saveSetting('yandex_market_auth_type', 'Тип авторизации API Яндекс Маркета', $data['auth_type'] ?? 'api_key', 'string');

        return $this->getSettings();
    }

    public function syncStocks(): array
    {
        $settings = $this->getSettings();
        $campaignId = trim((string) $settings['campaign_id']);
        $token = trim((string) $settings['auth_token']);
        $authType = $settings['auth_type'] === 'oauth' ? 'oauth' : 'api_key';

        if ($campaignId === '' || ! ctype_digit($campaignId)) {
            return $this->fail('Не указан корректный campaignId Яндекс Маркета.');
        }

        if ($token === '') {
            return $this->fail('Не указан токен API Яндекс Маркета.');
        }

        $endpoint = "https://api.partner.market.yandex.ru/v2/campaigns/{$campaignId}/offers/stocks";
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => ($authType === 'oauth' ? 'OAuth ' : 'Api-Key ') . $token,
        ];

        $totalSent = 0;
        $requests = 0;
        $errors = [];
        $updatedAt = now()->toIso8601String();

        foreach ($this->stockPayloadChunks($updatedAt) as $skus) {
            if (empty($skus)) {
                continue;
            }

            $requests++;
            $response = Http::timeout(60)
                ->retry(2, 500)
                ->withHeaders($headers)
                ->put($endpoint, ['skus' => $skus]);

            if (! $response->successful()) {
                $errors[] = [
                    'status' => $response->status(),
                    'body' => $response->json() ?: $response->body(),
                    'sent_before_error' => $totalSent,
                ];
                break;
            }

            $totalSent += count($skus);
        }

        $result = [
            'success' => empty($errors),
            'sent' => $totalSent,
            'requests' => $requests,
            'errors' => $errors,
            'synced_at' => now()->toDateTimeString(),
        ];

        $this->saveSetting('yandex_market_stocks_last_sync_at', 'Последняя отправка остатков в Яндекс Маркет', $result['synced_at'], 'string');
        $this->saveSetting('yandex_market_stocks_last_sync_result', 'Результат последней отправки остатков в Яндекс Маркет', json_encode($result, JSON_UNESCAPED_UNICODE), 'json');

        return $result;
    }

    private function stockPayloadChunks(string $updatedAt): \Generator
    {
        $buffer = [];

        ShopGood::active()
            ->select('id', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
            ->with([
                'variations' => function ($query) {
                    $query->select(
                        'id',
                        'good_id',
                        'stock_quantity',
                        'remote_stock_quantity',
                        'fast_remote_stock_quantity',
                        'is_active'
                    )->where('is_active', true);
                },
            ])
            ->chunk(500, function ($goods) use (&$buffer, $updatedAt) {
                foreach ($goods as $good) {
                    $buffer[] = [
                        'sku' => (string) $good->id,
                        'items' => [
                            [
                                'count' => $this->getOfferStockValue($good),
                                'updatedAt' => $updatedAt,
                            ],
                        ],
                    ];
                }
            });

        foreach (array_chunk($buffer, self::BATCH_SIZE) as $chunk) {
            yield $chunk;
        }
    }

    private function getOfferStockValue(ShopGood $good): int
    {
        if ($good->relationLoaded('variations') && $good->variations->isNotEmpty()) {
            return $good->variations
                ->filter(fn ($variation) => $variation->is_active)
                ->sum(fn ($variation) => $this->getItemStockValue($variation));
        }

        return $this->getItemStockValue($good);
    }

    private function getItemStockValue($item): int
    {
        return $this->normalizeNumericStock($item->stock_quantity ?? 0)
            + $this->normalizeRemoteStockPresence($item->remote_stock_quantity ?? null)
            + $this->normalizeRemoteStockPresence($item->fast_remote_stock_quantity ?? null);
    }

    private function normalizeNumericStock($value): int
    {
        return max(0, (int) $value);
    }

    private function normalizeRemoteStockPresence($value): int
    {
        $stockValue = trim((string) ($value ?? ''));

        return ($stockValue === '' || $stockValue === '0') ? 0 : 10;
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

    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'sent' => 0,
            'requests' => 0,
            'errors' => [],
        ];
    }
}
