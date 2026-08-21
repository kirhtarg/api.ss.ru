<?php

namespace App\Services;

use App\Jobs\SyncYandexProductsOfferJob;
use App\Models\Setting;
use App\Models\ShopGood;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexProductsOfferSyncService
{
    private const GROUP = 'yandex_products';
    private const API_URL = 'https://yandex.ru/products/api/ext/partner';

    public function getSettings(): array
    {
        $values = Setting::query()->where('group', self::GROUP)->pluck('value', 'key')->all();

        return [
            'enabled' => ($values['enabled'] ?? '0') === '1',
            'feed_id' => (string) ($values['feed_id'] ?? ''),
            'oauth_token' => $this->decryptToken($values['oauth_token'] ?? null),
            'last_sync_at' => $values['last_sync_at'] ?? null,
            'last_result' => $this->decodeJson($values['last_result'] ?? null),
        ];
    }

    public function saveSettings(array $data): array
    {
        $current = $this->getSettings();
        $this->save('enabled', 'Включить API Яндекс Товаров', !empty($data['enabled']) ? '1' : '0', 'boolean');
        $this->save('feed_id', 'Feed ID Яндекс Товаров', trim((string) ($data['feed_id'] ?? '')), 'string');

        $token = trim((string) ($data['oauth_token'] ?? ''));
        if ($token !== '') {
            $this->save('oauth_token', 'OAuth-токен API Яндекс Товаров', Crypt::encryptString($token), 'password');
        } elseif ($current['oauth_token'] === '') {
            $this->save('oauth_token', 'OAuth-токен API Яндекс Товаров', '', 'password');
        }

        return $this->getSettings();
    }

    public function queueGood(int $goodId): void
    {
        if ($goodId <= 0 || ! $this->isConfigured()) {
            return;
        }

        SyncYandexProductsOfferJob::dispatch($goodId)->delay(now()->addSeconds(12));
    }

    public function syncGood(int $goodId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $settings = $this->getSettings();
        $good = ShopGood::query()
            ->with(['variations' => fn ($query) => $query->where('is_active', true)])
            ->find($goodId);
        $offer = $good ? app(YmlFeedService::class)->offerSnapshot($good) : [
            'offer_id' => (string) $goodId,
            'available' => false,
            'price' => null,
            'old_price' => null,
        ];

        try {
            if (! $offer['available']) {
                $response = $this->request($settings)->post(self::API_URL.'/hidden-offers', [
                    'hiddenOffers' => [[
                        'feedId' => (int) $settings['feed_id'],
                        'offerId' => $offer['offer_id'],
                    ]],
                ]);
                $this->ensureAccepted($response, $goodId, 'hide');
                $this->recordResult(['good_id' => $goodId, 'action' => 'hidden', 'status' => 'ok']);
                return;
            }

            $restoreResponse = $this->request($settings)->send('DELETE', self::API_URL.'/hidden-offers', [
                'json' => ['hiddenOffers' => [[
                    'feedId' => (int) $settings['feed_id'],
                    'offerId' => $offer['offer_id'],
                ]]],
            ]);
            $this->ensureAccepted($restoreResponse, $goodId, 'restore');

            $price = [
                'currencyId' => 'RUR',
                'value' => $offer['price'],
            ];
            if ($offer['old_price'] !== null && $offer['old_price'] > $offer['price']) {
                $price['discountBase'] = $offer['old_price'];
            }
            $priceResponse = $this->request($settings)->post(self::API_URL.'/offer-prices/updates', [
                'offers' => [[
                    'feed' => ['id' => (int) $settings['feed_id']],
                    'id' => $offer['offer_id'],
                    'price' => $price,
                ]],
            ]);
            $this->ensureAccepted($priceResponse, $goodId, 'price');
            $this->recordResult(['good_id' => $goodId, 'action' => 'available_and_price_updated', 'status' => 'ok']);
        } catch (\Throwable $e) {
            $this->recordResult(['good_id' => $goodId, 'status' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function isConfigured(): bool
    {
        $settings = $this->getSettings();

        return $settings['enabled']
            && ctype_digit($settings['feed_id'])
            && $settings['oauth_token'] !== '';
    }

    private function request(array $settings): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 500)
            ->acceptJson()
            ->asJson()
            ->withToken($settings['oauth_token'], 'OAuth');
    }

    private function ensureAccepted($response, int $goodId, string $action): void
    {
        $body = $response->json() ?: [];
        if (! $response->successful() || data_get($body, 'status') === 'ERROR') {
            throw new \RuntimeException(sprintf(
                'Яндекс Товары не принял %s для товара #%d: %s',
                $action,
                $goodId,
                json_encode($body ?: $response->body(), JSON_UNESCAPED_UNICODE),
            ));
        }
    }

    private function recordResult(array $result): void
    {
        $this->save('last_sync_at', 'Последняя отправка в API Яндекс Товаров', now()->toDateTimeString(), 'string');
        $this->save('last_result', 'Последний результат API Яндекс Товаров', json_encode($result, JSON_UNESCAPED_UNICODE), 'json');
        if (($result['status'] ?? null) === 'error') {
            Log::warning('[yandex-products] Offer synchronization failed', $result);
        }
    }

    private function save(string $key, string $name, mixed $value, string $type): void
    {
        Setting::query()->updateOrCreate(
            ['group' => self::GROUP, 'key' => $key],
            ['name' => $name, 'value' => $value, 'type' => $type],
        );
    }

    private function decryptToken(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? json_decode($value, true) : null;
    }
}
