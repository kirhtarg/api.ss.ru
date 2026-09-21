<?php

namespace App\Services;

use App\Models\ShopCarrierDeliverySettings;
use App\Models\ShopOzonDeliveryPoint;
use App\Models\ShopOrder;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class OzonDeliveryService
{
    private const API_URL = 'https://api-delivery.ozon.ru';
    private const TOKEN_URL = 'https://xapi.ozon.ru/oauth/token';
    private const SCOPE = 'delivery-api.all';

    public function getActiveSettings(): ShopCarrierDeliverySettings
    {
        $settings = app(ShopDeliveryActivitySyncService::class)->getMethodActive('ozon') === false
            ? null
            : ShopCarrierDeliverySettings::getActive('ozon');

        if (! $settings || blank($settings->oauth_client_id) || blank($settings->oauth_client_secret)) {
            throw new RuntimeException('Ozon Доставка не активна или не заполнены OAuth Client ID и Client Secret.');
        }

        return $settings;
    }

    /**
     * Pickup-point directory maintenance is independent from whether Ozon
     * delivery is currently offered to customers at checkout.
     */
    public function getSettingsForPickupPointSync(): ShopCarrierDeliverySettings
    {
        $settings = $this->findSettingsForPickupPointSync();
        if (! $settings || blank($settings->oauth_client_id) || blank($settings->oauth_client_secret)) {
            throw new RuntimeException('Для синхронизации ПВЗ заполните OAuth Client ID и Client Secret Ozon Доставки.');
        }

        return $settings;
    }

    protected function findSettingsForPickupPointSync(): ?ShopCarrierDeliverySettings
    {
        return ShopCarrierDeliverySettings::query()->where('carrier', 'ozon')->first();
    }

    public function refreshAccessToken(?ShopCarrierDeliverySettings $settings = null): array
    {
        $settings ??= $this->getActiveSettings();
        $response = Http::acceptJson()->asJson()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $settings->oauth_client_id,
            'client_secret' => $settings->oauth_client_secret,
            'grant_type' => 'client_credentials',
            'scope' => [self::SCOPE],
        ]);
        $body = $response->json();
        $token = trim((string) data_get($body, 'access_token', ''));
        if (! $response->successful() || $token === '') {
            $message = data_get($body, 'message') ?? data_get($body, 'error_description') ?? data_get($body, 'error') ?? 'Ozon не вернул access_token';
            throw new RuntimeException('OAuth Ozon: '.$message, $response->status());
        }

        $expires = data_get($body, 'expires_in');
        // Ozon Delivery returns expires_in as an absolute Unix timestamp.
        $expiresAt = is_numeric($expires)
            ? now()->setTimestamp((int) $expires)
            : null;
        $settings->oauth_access_token = $token;
        $settings->oauth_expires_at = $expiresAt;
        $settings->save();

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function request(ShopCarrierDeliverySettings $settings, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $token = $this->accessToken($settings);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->requestWithToken($token, $path, $payload, $headers);
        $body = $response->json();
        if (! $response->successful()) {
            $message = data_get($body, 'message') ?? data_get($body, 'error.message') ?? data_get($body, 'error') ?? $response->body();
            throw new RuntimeException('Ozon API '.strtoupper($path).' (HTTP '.$response->status().'): '.(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE)), $response->status());
        }
        if (! is_array($body)) {
            throw new RuntimeException('Ozon API вернул некорректный JSON для '.strtoupper($path));
        }

        return $body;
    }

    /**
     * Send an API request and complete Ozon's same-origin testcookie challenge
     * when present. The redirect response must not be followed as a normal
     * browser redirect: Ozon expects the original POST body and challenge
     * cookie to be replayed together.
     */
    public function requestWithToken(string $token, string $path, array $payload = [], array $headers = []): Response
    {
        $url = self::API_URL.'/'.ltrim($path, '/');
        $headers = array_merge(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $headers);
        $cookies = [];

        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($cookies) {
                $headers['Cookie'] = implode('; ', array_map(
                    static fn ($name, $value) => $name.'='.$value,
                    array_keys($cookies),
                    array_values($cookies)
                ));
            }

            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->post($url, $payload);

            if (! in_array($response->status(), [302, 307], true)) {
                return $response;
            }

            foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $cookieHeader) {
                $cookiePair = trim(explode(';', (string) $cookieHeader, 2)[0]);
                if ($cookiePair === '' || ! str_contains($cookiePair, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $cookiePair, 2);
                $cookies[trim($name)] = trim($value);
            }

            $location = trim((string) $response->header('Location'));
            if ($location !== '') {
                $url = $this->resolveSameOriginUrl($url, $location);
            }

            if (! $cookies && $location === '') {
                return $response;
            }
        }

        throw new RuntimeException('Ozon API не завершил проверку testcookie после нескольких попыток.');
    }

    private function resolveSameOriginUrl(string $currentUrl, string $location): string
    {
        $base = parse_url(self::API_URL);
        $target = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        $targetParts = parse_url($target);
        if (! $targetParts ||
            strtolower((string) ($targetParts['scheme'] ?? '')) !== strtolower((string) ($base['scheme'] ?? 'https')) ||
            strtolower((string) ($targetParts['host'] ?? '')) !== strtolower((string) ($base['host'] ?? '')) ||
            (int) ($targetParts['port'] ?? 443) !== (int) ($base['port'] ?? 443)) {
            throw new RuntimeException('Ozon API вернул небезопасный redirect за пределы api-delivery.ozon.ru.');
        }

        return $target;
    }

    public function getPickupPoints(string $city): array
    {
        $needle = mb_strtolower(trim($city));
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return $this->queryLocalPickupPoints($needle)
            ->map(static function (ShopOzonDeliveryPoint $row): array {
                $point = is_array($row->point_data) ? $row->point_data : [];
                $point['delivery_point_id'] = (int) $row->delivery_point_id;
                $point['name'] = $row->name;
                $point['full_address'] = $row->full_address;
                $point['shipment_method_ids'] = $row->shipment_method_ids ?? [];

                return $point;
            })
            ->all();
    }

    protected function queryLocalPickupPoints(string $needle)
    {
        return ShopOzonDeliveryPoint::query()
            ->where('is_active', true)
            ->where('search_text', 'like', '%'.$needle.'%')
            ->orderBy('name')
            ->get();
    }

    private function pickupPointSearchText(array $point): string
    {
        $values = [];
        $appendScalars = static function ($value) use (&$values, &$appendScalars): void {
            if (is_scalar($value)) {
                $values[] = (string) $value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $appendScalars($nested);
                }
            }
        };

        foreach (['city', 'name', 'full_address', 'address', 'location', 'region', 'settlement'] as $key) {
            if (array_key_exists($key, $point)) {
                $appendScalars($point[$key]);
            }
        }

        return mb_strtolower(implode(' ', $values));
    }

    public function calculateCheckout(ShopCarrierDeliverySettings $settings, array $requestData): array
    {
        $packages = app(DeliveryPackageService::class)->fromCartItems($requestData['items'], $settings);
        $postings = $this->makePostings(
            $packages,
            (int) $requestData['shipment_method_id'],
            (float) ($requestData['subtotal'] ?? 0),
            $requestData['items'],
            null
        );
        $body = $this->request($settings, '/v1/order/checkout', [
            'recipient' => ['phone_number' => $this->normalizePhone((string) $requestData['phone'])],
            'postings' => $postings,
            'delivery' => ['delivery_point' => ['delivery_point_id' => (int) $requestData['delivery_point_id']]],
        ]);

        $results = (array) data_get($body, 'results', []);
        $errors = array_values(array_filter(array_map(fn ($row) => data_get($row, 'error'), $results)));
        if ($errors) {
            throw new RuntimeException('Ozon не рассчитал доставку: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $cost = 0.0;
        $insuranceCost = 0.0;
        $estimatedDays = null;
        $cutoffs = [];
        foreach ($results as $result) {
            $posting = (array) ($result['posting'] ?? []);
            $cost += (float) data_get($posting, 'estimated_delivery_cost.amount', 0);
            $insuranceCost += (float) data_get($posting, 'estimated_insurance_cost.amount', 0);
            $estimatedDays = max((int) ($estimatedDays ?? 0), (int) ($posting['estimated_delivery_days'] ?? 0)) ?: null;
            $cutoffs[] = $posting['cutoff_at'] ?? null;
        }

        return [
            'cost' => round($cost, 2),
            'insurance_cost' => round($insuranceCost, 2),
            'estimated_delivery_days' => $estimatedDays,
            'cutoffs' => $cutoffs,
            'delivery_point_id' => (int) $requestData['delivery_point_id'],
            'shipment_method_id' => (int) $requestData['shipment_method_id'],
            'postings' => $postings,
            'results' => $results,
        ];
    }

    public function createOrder(ShopOrder $order): array
    {
        $settings = $this->getActiveSettings();
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $pointId = (int) ($metadata['ozon_delivery_point_id'] ?? 0);
        $shipmentMethodId = (int) ($metadata['ozon_shipment_method_id'] ?? 0);
        if ($pointId <= 0 || $shipmentMethodId <= 0) {
            throw new RuntimeException('В заказе не сохранен выбранный пункт или метод доставки Ozon.');
        }
        $packages = app(DeliveryPackageService::class)->fromOrder($order, $settings);
        $quote = (array) ($metadata['ozon_delivery_quote'] ?? []);
        $postings = $this->makePostings($packages, $shipmentMethodId, (float) $order->subtotal, (array) $order->items, $quote['cutoffs'] ?? null);
        foreach ($postings as $index => &$posting) {
            $posting['posting_external_id'] = (string) $order->order_number.'-'.($index + 1);
            $posting['description'] = 'Заказ интернет-магазина № '.$order->order_number.', место '.($index + 1);
        }
        unset($posting);

        $idempotencyKey = (string) ($metadata['ozon_idempotency_key'] ?? Str::uuid());
        // Persist the key before the outbound mutation so a retry reuses the same idempotency key.
        $metadata['ozon_idempotency_key'] = $idempotencyKey;
        $order->metadata = $metadata;
        $order->save();

        $payload = [
            'order_external_id' => (string) $order->order_number,
            'recipient' => [
                'phone_number' => $this->normalizePhone((string) $order->customer_phone),
                'full_name' => trim((string) $order->customer_name),
            ],
            'delivery' => ['delivery_point' => ['delivery_point_id' => $pointId]],
            'postings' => $postings,
        ];
        $response = $this->request($settings, '/v1/order/create', $payload, $idempotencyKey);
        $metadata['ozon_order_number'] = data_get($response, 'order_number');
        $metadata['ozon_order_external_id'] = data_get($response, 'order_external_id');
        $metadata['ozon_postings'] = data_get($response, 'postings', []);
        $metadata['ozon_create_payload'] = $payload;
        $metadata['ozon_create_response'] = $response;
        $order->metadata = $metadata;
        $order->delivery_status = json_encode([
            'code' => 'CREATED',
            'name' => 'Заявка Ozon Доставки создана',
            'external_id' => $metadata['ozon_order_number'] ?: $order->order_number,
            'postings' => $metadata['ozon_postings'],
        ], JSON_UNESCAPED_UNICODE);
        $order->save();

        return ['response' => $response, 'order_number' => $metadata['ozon_order_number']];
    }

    private function accessToken(ShopCarrierDeliverySettings $settings): string
    {
        $expiresAt = $settings->oauth_expires_at;
        if (filled($settings->oauth_access_token) && $expiresAt && $expiresAt->gt(now()->addMinute())) {
            return (string) $settings->oauth_access_token;
        }

        return $this->refreshAccessToken($settings)['token'];
    }

    private function makePostings(array $packages, int $shipmentMethodId, float $totalValue, array $items, ?array $cutoffs): array
    {
        if ($shipmentMethodId <= 0) throw new RuntimeException('Не выбран метод доставки Ozon.');
        $packageValues = array_fill(0, count($packages), 0.0);
        foreach ($packages as $packageIndex => $package) {
            foreach ((array) ($package['items'] ?? []) as $packageItem) {
                $itemIndex = (int) ($packageItem['item_index'] ?? -1);
                if (! isset($items[$itemIndex])) continue;
                $item = $items[$itemIndex];
                $price = (float) ($item['final_price'] ?? $item['price'] ?? $item['unit_price'] ?? 0);
                $quantity = max(1, (int) ($packageItem['quantity'] ?? 1));
                $packageValues[$packageIndex] += $price * $quantity;
            }
        }
        if (array_sum($packageValues) <= 0 && $totalValue > 0 && count($packageValues) > 0) {
            $packageValues[0] = $totalValue;
        }

        $postings = [];
        foreach ($packages as $index => $package) {
            $postings[] = [
                'request_id' => $index + 1,
                'shipment_method_id' => $shipmentMethodId,
                'cutoff_at' => $cutoffs[$index] ?? null,
                'declared_value' => [
                    'amount' => number_format(max(0, $packageValues[$index] ?? 0), 2, '.', ''),
                    'currency_code' => 'RUB',
                ],
                'dimensions' => [
                    'weight_g' => max(1, (int) round((float) $package['weight'] * 1000)),
                    'length_mm' => max(1, (int) round((float) $package['length'] * 10)),
                    'width_mm' => max(1, (int) round((float) $package['width'] * 10)),
                    'height_mm' => max(1, (int) round((float) $package['height'] * 10)),
                ],
            ];
        }

        return $postings;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) $digits = '7'.substr($digits, 1);
        if (strlen($digits) === 10) $digits = '7'.$digits;
        if (strlen($digits) !== 11 || ! str_starts_with($digits, '7')) {
            throw new RuntimeException('Для доставки Ozon нужен корректный российский номер телефона.');
        }
        return '+'.$digits;
    }
}
