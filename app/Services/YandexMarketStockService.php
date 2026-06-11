<?php

namespace App\Services;

use App\Models\Setting;
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

        $feedPath = $this->getFeedPath();
        if (! $feedPath) {
            return $this->fail('Файл goods_feed.xml не найден. Сначала сгенерируйте YML-фид.');
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

        if ($requests === 0 && empty($errors)) {
            $result['success'] = false;
            $result['message'] = 'В goods_feed.xml не найдено offer с тегом count.';
        }

        $this->saveSetting('yandex_market_stocks_last_sync_at', 'Последняя отправка остатков в Яндекс Маркет', $result['synced_at'], 'string');
        $this->saveSetting('yandex_market_stocks_last_sync_result', 'Результат последней отправки остатков в Яндекс Маркет', json_encode($result, JSON_UNESCAPED_UNICODE), 'json');

        return $result;
    }

    private function getFeedPath(): ?string
    {
        $relativePath = 'exports/goods_feed.xml';

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->path($relativePath);
        }

        return null;
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
