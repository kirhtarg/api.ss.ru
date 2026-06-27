<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCarrierDeliverySettings;
use App\Services\ShopDeliveryActivitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopYandexDeliveryController extends Controller
{
    public function getActiveSettings(): JsonResponse
    {
        try {
            $settings = $this->getSettingsOrFail();
            $settingsData = is_array($settings->settings) ? $settings->settings : [];

            return response()->json([
                'success' => true,
                'data' => [
                    'api_mode' => $settingsData['api_mode'] ?? 'other_day',
                    'is_express' => $this->isExpressMode($settings),
                    'has_warehouse' => trim((string) $settings->warehouse_id) !== '',
                    'sender_city' => $settings->sender_city,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }
    }

    public function getPickupPoints(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ]);

            $settings = $this->getSettingsOrFail();
            if ($this->isExpressMode($settings)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Для режима Яндекс Экспресс доставка до ПВЗ не используется. Доступна доставка от адреса отправителя до адреса получателя.',
                ]);
            }

            $rawCity = trim((string) $request->query('city'));
            $city = $this->normalizeSettlementName($rawCity);
            $location = $this->detectLocation($settings, $city, $request->query('latitude'), $request->query('longitude'));

            if (empty($location['geo_id'])) {
                $location = $this->enrichLocationWithCoordinates($city, $location);
            }

            $pickupPointPayloads = [];
            if (! empty($location['geo_id'])) {
                $pickupPointPayloads[] = [
                    'geo_id' => $location['geo_id'],
                    'type' => 'pickup_point',
                ];
                $pickupPointPayloads[] = [
                    'geo_id' => $location['geo_id'],
                ];
                $pickupPointPayloads[] = [
                    'location' => [
                        'geo_id' => $location['geo_id'],
                    ],
                    'type' => 'pickup_point',
                ];
            }
            $pickupPointPayloads[] = array_filter([
                    'latitude' => $this->makeCoordinateInterval($location['lat'] ?? null),
                    'longitude' => $this->makeCoordinateInterval($location['lon'] ?? null),
                    'type' => 'pickup_point',
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);
            $pickupPointPayloads[] = array_filter([
                    'latitude' => $this->makeCoordinateInterval($location['lat'] ?? null, 0.2),
                    'longitude' => $this->makeCoordinateInterval($location['lon'] ?? null, 0.2),
                    'type' => 'pickup_point',
            ], fn ($value) => $value !== null && $value !== '');
            $pickupPointPayloads[] = array_filter([
                    'location' => $location,
                    'city' => $city,
            ], fn ($value) => $value !== null && $value !== '');

            $response = $this->yandexRequestVariants($settings, 'post', '/pickup-points/list', array_values(array_filter(
                $pickupPointPayloads,
                fn ($payload) => is_array($payload) && count($payload) > 1
            )));
            $points = $this->normalizePickupPoints($response);

            return response()->json([
                'success' => true,
                'data' => $points,
                'meta' => [
                    'raw_city' => $rawCity,
                    'normalized_city' => $city,
                    'location' => $location,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Yandex Delivery pickup points error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Не удалось загрузить ПВЗ Яндекс Доставки: '.$e->getMessage(),
                'data' => [],
            ]);
        }
    }

    public function getTariffs(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'street' => 'nullable|string|max:255',
                'house' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:500',
                'delivery_type' => 'required|string|in:address,pickup_point,pvz',
                'pickup_point_id' => 'nullable|string|max:255',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'cart_items' => 'nullable',
            ]);

            $settings = $this->getSettingsOrFail();
            if ($this->isExpressMode($settings)) {
                return $this->getExpressTariffs($request, $settings);
            }

            $city = $this->normalizeSettlementName((string) $request->query('city'));
            $deliveryType = $request->query('delivery_type') === 'pvz' ? 'pickup_point' : $request->query('delivery_type');
            $cartItems = $request->query('cart_items', []);
            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $cargo = $this->calculateCargo($cartItems, $settings);
            $recipientAddress = $this->buildAddress($city, (string) $request->query('street', ''), (string) $request->query('house', ''), (string) $request->query('address', ''));
            $pickupPointId = trim((string) $request->query('pickup_point_id', ''));

            if (! trim((string) $settings->warehouse_id)) {
                throw new \RuntimeException('В настройках Яндекс Доставки не указан ID склада отправления / platform_station_id');
            }

            if ($deliveryType === 'pickup_point' && $pickupPointId === '') {
                throw new \RuntimeException('Для расчета доставки до ПВЗ выберите пункт выдачи Яндекс Доставки');
            }

            if ($deliveryType === 'address' && $recipientAddress === '') {
                throw new \RuntimeException('Для расчета доставки до адреса укажите город, улицу и дом');
            }

            $payload = [
                'source' => [
                    'platform_station_id' => (string) $settings->warehouse_id,
                ],
                'destination' => $deliveryType === 'pickup_point'
                    ? ['platform_station_id' => $pickupPointId]
                    : ['address' => $recipientAddress],
                'tariff' => $deliveryType === 'pickup_point' ? 'self_pickup' : 'time_interval',
                'total_weight' => $cargo['total_weight_grams'],
                'total_assessed_price' => $cargo['total_assessed_price_kopecks'],
                'client_price' => 0,
                'payment_method' => 'already_paid',
                'places' => $cargo['places'],
            ];

            $response = $this->yandexRequest($settings, 'post', '/pricing-calculator', $payload);
            $tariffs = $this->normalizeTariffs($response, $deliveryType, $recipientAddress, $pickupPointId);

            return response()->json([
                'success' => true,
                'data' => $tariffs,
                'meta' => [
                    'cargo' => $cargo,
                    'request_payload' => $payload,
                    'raw' => $response,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Yandex Delivery tariffs error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Не удалось рассчитать Яндекс Доставку: '.$e->getMessage(),
                'data' => [],
            ]);
        }
    }

    private function getSettingsOrFail(): ShopCarrierDeliverySettings
    {
        if (app(ShopDeliveryActivitySyncService::class)->getMethodActive('yandex') === false) {
            throw new \RuntimeException('Способ доставки Яндекс Доставка отключен');
        }

        $settings = ShopCarrierDeliverySettings::getActive('yandex');

        if (! $settings || ! $settings->api_token) {
            throw new \RuntimeException('Активные настройки Яндекс Доставки не найдены');
        }

        return $settings;
    }

    private function yandexRequest(ShopCarrierDeliverySettings $settings, string $method, string $path, array $payload = []): array
    {
        $baseUrl = $this->normalizeBaseUrl((string) $settings->api_url);
        $url = $baseUrl.$path;
        $request = Http::withToken((string) $settings->api_token)
            ->acceptJson()
            ->asJson()
            ->timeout(25);

        $response = strtolower($method) === 'get'
            ? $request->get($url, $payload)
            : $request->post($url, $payload);

        if (! $response->successful()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? $response->json('error_description')
                ?? $response->json('detail')
                ?? $response->json('details')
                ?? $response->body()
                ?: 'API Яндекс Доставки вернул ошибку';
            Log::warning('Yandex Delivery API error', [
                'url' => $url,
                'status' => $response->status(),
                'payload' => $payload,
                'response' => $response->json() ?: $response->body(),
            ]);
            throw new \RuntimeException(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE), $response->status());
        }

        return $response->json() ?: [];
    }

    private function yandexExpressRequest(ShopCarrierDeliverySettings $settings, string $method, string $path, array $payload = [], array $query = []): array
    {
        $baseUrl = $this->normalizeExpressBaseUrl((string) $settings->api_url);
        $url = $baseUrl.$path;
        $request = Http::withToken((string) $settings->api_token)
            ->withHeaders(['Accept-Language' => 'ru'])
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $response = strtolower($method) === 'get'
            ? $request->get($url, $query ?: $payload)
            : $request->post($url.($query ? '?'.http_build_query($query) : ''), $payload);

        if (! $response->successful()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? $response->json('error_description')
                ?? $response->body()
                ?: 'API Яндекс Экспресс вернул ошибку';
            Log::warning('Yandex Express Delivery API error', [
                'url' => $url,
                'status' => $response->status(),
                'payload' => $payload,
                'query' => $query,
                'response' => $response->json() ?: $response->body(),
            ]);
            throw new \RuntimeException(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE), $response->status());
        }

        return $response->json() ?: [];
    }

    private function getExpressTariffs(Request $request, ShopCarrierDeliverySettings $settings): JsonResponse
    {
        $city = $this->normalizeSettlementName((string) $request->query('city'));
        $cartItems = $request->query('cart_items', []);
        if (is_string($cartItems)) {
            $cartItems = json_decode($cartItems, true) ?: [];
        }

        $recipientAddress = $this->buildAddress($city, (string) $request->query('street', ''), (string) $request->query('house', ''), (string) $request->query('address', ''));
        if ($recipientAddress === '') {
            throw new \RuntimeException('Для расчета Яндекс Экспресс укажите адрес получателя');
        }

        $payload = $this->buildExpressOfferPayload($settings, $recipientAddress, $city, $cartItems);
        $response = $this->yandexExpressRequest($settings, 'post', '/offers/calculate', $payload);
        $tariffs = $this->normalizeExpressOffers($response);

        return response()->json([
            'success' => true,
            'data' => $tariffs,
            'meta' => [
                'request_payload' => $payload,
                'raw' => $response,
            ],
        ]);
    }

    private function buildExpressOfferPayload(ShopCarrierDeliverySettings $settings, string $recipientAddress, string $recipientCity, array $cartItems): array
    {
        $sourceAddress = $this->buildAddress(
            $this->normalizeSettlementName((string) $settings->sender_city),
            (string) $settings->sender_street,
            (string) $settings->sender_house,
            ''
        );

        if ($sourceAddress === '') {
            throw new \RuntimeException('В настройках Яндекс Доставки укажите адрес отправителя');
        }

        $settingsData = is_array($settings->settings) ? $settings->settings : [];

        return [
            'items' => $this->buildExpressItems($cartItems, $settings),
            'route_points' => [
                $this->buildExpressRoutePoint(1, $sourceAddress, (string) $settings->sender_city),
                $this->buildExpressRoutePoint(2, $recipientAddress, $recipientCity),
            ],
            'requirements' => array_filter([
                'taxi_classes' => [$settingsData['express_taxi_class'] ?? 'express'],
                'skip_door_to_door' => false,
                'pro_courier' => (bool) ($settingsData['express_pro_courier'] ?? false),
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function buildExpressItems(array $cartItems, ShopCarrierDeliverySettings $settings): array
    {
        $defaultWeight = max(0.01, (float) ($settings->default_weight ?? 0.5));
        $defaultLength = max(1, (float) ($settings->default_length ?? 10));
        $defaultWidth = max(1, (float) ($settings->default_width ?? 10));
        $defaultHeight = max(1, (float) ($settings->default_height ?? 10));
        $items = [];

        foreach ($cartItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $weight = $this->positiveDeliveryNumber($item['weight'] ?? null) ?? $defaultWeight;
            $length = $this->positiveDeliveryNumber($item['length'] ?? ($item['depth'] ?? null)) ?? $defaultLength;
            $width = $this->positiveDeliveryNumber($item['width'] ?? null) ?? $defaultWidth;
            $height = $this->positiveDeliveryNumber($item['height'] ?? null) ?? $defaultHeight;
            $items[] = [
                'size' => [
                    'length' => round(max(1, $length) / 100, 3),
                    'width' => round(max(1, $width) / 100, 3),
                    'height' => round(max(1, $height) / 100, 3),
                ],
                'weight' => max(0.01, $weight),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'pickup_point' => 1,
                'dropoff_point' => 2,
                'age_restricted' => false,
            ];
        }

        return $items ?: [[
            'size' => [
                'length' => round($defaultLength / 100, 3),
                'width' => round($defaultWidth / 100, 3),
                'height' => round($defaultHeight / 100, 3),
            ],
            'weight' => $defaultWeight,
            'quantity' => 1,
            'pickup_point' => 1,
            'dropoff_point' => 2,
            'age_restricted' => false,
        ]];
    }

    private function buildExpressRoutePoint(int $id, string $address, string $city): array
    {
        return array_filter([
            'id' => $id,
            'fullname' => $address,
            'country' => 'Россия',
            'city' => $this->normalizeSettlementName($city),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function normalizeExpressOffers(array $response): array
    {
        $offers = $response['offers'] ?? [];

        return array_values(array_filter(array_map(function ($offer, $index) {
            if (! is_array($offer)) {
                return null;
            }
            $price = $offer['price']['total_price_with_vat'] ?? $offer['price']['total_price'] ?? null;
            if (! is_numeric($price)) {
                return null;
            }

            return [
                'code' => 'yandex_express_'.($offer['taxi_class'] ?? $index),
                'name' => $this->expressTaxiClassName((string) ($offer['taxi_class'] ?? 'express')),
                'description' => $offer['description'] ?? 'Доставка от адреса отправителя до адреса получателя',
                'cost' => round((float) $price, 2),
                'cost_value' => round((float) $price, 2),
                'type' => 'address',
                'period' => $this->formatExpressOfferPeriod($offer),
                'raw' => $offer,
            ];
        }, $offers, array_keys($offers))));
    }

    private function expressTaxiClassName(string $taxiClass): string
    {
        return match ($taxiClass) {
            'courier' => 'Яндекс Курьер',
            'cargo' => 'Яндекс Грузовой',
            'sdd_multislot' => 'Яндекс В течение дня',
            default => 'Яндекс Экспресс',
        };
    }

    private function formatExpressOfferPeriod(array $offer): ?string
    {
        $pickup = $offer['pickup_interval'] ?? [];
        $delivery = $offer['delivery_interval'] ?? [];
        $from = $pickup['from'] ?? null;
        $to = $delivery['to'] ?? null;

        return $from && $to ? $from.' - '.$to : null;
    }

    private function isExpressMode(ShopCarrierDeliverySettings $settings): bool
    {
        $settingsData = is_array($settings->settings) ? $settings->settings : [];

        return ($settingsData['api_mode'] ?? 'other_day') === 'express';
    }

    private function normalizeExpressBaseUrl(string $apiUrl): string
    {
        $default = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2';
        $apiUrl = trim($apiUrl);
        if ($apiUrl === '' || str_contains($apiUrl, '/api/b2b/platform')) {
            return $default;
        }
        $apiUrl = rtrim($apiUrl, '/');

        foreach ([
            '/offers/calculate',
            '/claims/create',
            '/claims/accept',
            '/claims/info',
            '/claims/cancel',
        ] as $methodPath) {
            if (str_ends_with($apiUrl, $methodPath)) {
                return substr($apiUrl, 0, -strlen($methodPath));
            }
        }

        return $apiUrl;
    }

    private function yandexRequestVariants(ShopCarrierDeliverySettings $settings, string $method, string $path, array $payloadVariants): array
    {
        $lastError = null;

        foreach ($payloadVariants as $payload) {
            try {
                return $this->yandexRequest($settings, $method, $path, $payload);
            } catch (\Throwable $e) {
                $lastError = $e;
                if (! $this->isClientApiError($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError ?: new \RuntimeException('API Яндекс Доставки вернул ошибку');
    }

    private function isClientApiError(\Throwable $e): bool
    {
        return in_array((int) $e->getCode(), [400, 401, 403, 404, 409, 422], true);
    }

    private function normalizeBaseUrl(string $apiUrl): string
    {
        $apiUrl = trim($apiUrl) ?: 'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform';
        $apiUrl = rtrim($apiUrl, '/');

        foreach ([
            '/merchant/info',
            '/pricing-calculator',
            '/location/detect',
            '/pickup-points/list',
            '/offers/create',
            '/offers/confirm',
        ] as $methodPath) {
            if (str_ends_with($apiUrl, $methodPath)) {
                return substr($apiUrl, 0, -strlen($methodPath));
            }
        }

        return $apiUrl;
    }

    private function detectLocation(ShopCarrierDeliverySettings $settings, string $city, mixed $latitude = null, mixed $longitude = null): array
    {
        $city = $this->normalizeSettlementName($city);
        $cityWithCountry = str_contains(mb_strtolower($city), 'россия') ? $city : 'Россия, '.$city;
        try {
            $response = $this->yandexRequestVariants($settings, 'post', '/location/detect', [
                ['location' => $city],
                ['location' => $cityWithCountry],
            ]);
            $location = $response['variants'][0]
                ?? $response['data']['variants'][0]
                ?? $response['location']
                ?? $response['data']['location']
                ?? $response['result']
                ?? $response;

            return array_filter([
                'address' => $location['address'] ?? $city,
                'geo_id' => $location['geo_id']
                    ?? $location['geoId']
                    ?? $location['geoid']
                    ?? $location['geo']['id']
                    ?? $location['id']
                    ?? null,
                'lat' => $location['lat'] ?? $location['latitude'] ?? null,
                'lon' => $location['lon'] ?? $location['longitude'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        } catch (\Throwable $e) {
            if (! $this->isClientApiError($e)) {
                throw $e;
            }

            return ['address' => $city];
        }
    }

    private function normalizeSettlementName(string $city): string
    {
        $city = trim(preg_replace('/\s+/u', ' ', $city));
        $city = preg_replace('/^(г|город|д|деревня|пос|поселок|посёлок|пгт|с|село)\.?\s+/iu', '', $city);

        return trim($city);
    }

    private function makeCoordinateInterval(mixed $value, float $radius = 0.08): ?array
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return [
            'from' => round($coordinate - $radius, 6),
            'to' => round($coordinate + $radius, 6),
        ];
    }

    private function enrichLocationWithCoordinates(string $city, array $location): array
    {
        if (! empty($location['lat']) && ! empty($location['lon'])) {
            return $location;
        }

        $coordinates = $this->detectCoordinatesByDaData($city);
        if (! $coordinates) {
            return $location;
        }

        return array_filter([
            ...$location,
            'address' => $location['address'] ?? $city,
            'lat' => $coordinates['lat'],
            'lon' => $coordinates['lon'],
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function detectCoordinatesByDaData(string $city): ?array
    {
        $apiKey = config('services.dadata.api_key') ?: env('DADATA_API_KEY');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Token '.$apiKey,
            ])->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', [
                'query' => $city,
                'count' => 1,
                'locations' => [['country' => 'Россия']],
                'from_bound' => ['value' => 'city'],
                'to_bound' => ['value' => 'city'],
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('suggestions.0.data');
            $lat = $data['geo_lat'] ?? null;
            $lon = $data['geo_lon'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lon)) {
                return null;
            }

            return [
                'lat' => (float) $lat,
                'lon' => (float) $lon,
            ];
        } catch (\Throwable $e) {
            Log::warning('Yandex Delivery DaData coordinates fallback failed: '.$e->getMessage(), [
                'city' => $city,
            ]);

            return null;
        }
    }

    private function calculateCargo(array $cartItems, ShopCarrierDeliverySettings $settings): array
    {
        $defaultWeight = max(0.01, (float) ($settings->default_weight ?? 0.5));
        $defaultLength = max(1, (float) ($settings->default_length ?? 10));
        $defaultWidth = max(1, (float) ($settings->default_width ?? 10));
        $defaultHeight = max(1, (float) ($settings->default_height ?? 10));

        $totalWeight = 0.0;
        $totalAssessedPrice = 0.0;
        $maxLength = 0;
        $maxWidth = 0;
        $totalHeight = 0;
        $items = [];

        foreach ($cartItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $weight = max(0.01, $this->positiveDeliveryNumber($item['weight'] ?? null) ?? $defaultWeight);
            $length = max(1, $this->positiveDeliveryNumber($item['length'] ?? ($item['depth'] ?? null)) ?? $defaultLength);
            $width = max(1, $this->positiveDeliveryNumber($item['width'] ?? null) ?? $defaultWidth);
            $height = max(1, $this->positiveDeliveryNumber($item['height'] ?? null) ?? $defaultHeight);
            $price = max(0, (float) ($item['price'] ?? $item['total'] ?? $item['amount'] ?? 0));

            $totalWeight += $weight * $quantity;
            $totalAssessedPrice += $price * $quantity;
            $maxLength = max($maxLength, $length);
            $maxWidth = max($maxWidth, $width);
            $totalHeight += $height * $quantity;
            $items[] = [
                'id' => (string) ($item['variation_id'] ?? $item['good_id'] ?? $index),
                'quantity' => $quantity,
                'size' => [
                    'length' => $length,
                    'width' => $width,
                    'height' => $height,
                ],
                'weight' => $weight,
            ];
        }

        if (empty($items)) {
            $items[] = [
                'id' => 'default',
                'quantity' => 1,
                'size' => ['length' => $defaultLength, 'width' => $defaultWidth, 'height' => $defaultHeight],
                'weight' => $defaultWeight,
            ];
            $totalWeight = $defaultWeight;
            $maxLength = $defaultLength;
            $maxWidth = $defaultWidth;
            $totalHeight = $defaultHeight;
        }

        $dimensions = [
            'length' => max(1, $maxLength),
            'width' => max(1, $maxWidth),
            'height' => max(1, $totalHeight),
        ];

        $weightGrams = max(1, (int) round(max(0.01, $totalWeight) * 1000));

        return [
            'items' => $items,
            'places' => [[
                'physical_dims' => [
                    'weight_gross' => $weightGrams,
                    'dx' => (int) ceil($dimensions['length']),
                    'dy' => (int) ceil($dimensions['height']),
                    'dz' => (int) ceil($dimensions['width']),
                ],
            ]],
            'dimensions' => $dimensions,
            'weight' => max(0.01, $totalWeight),
            'total_weight_grams' => $weightGrams,
            'total_assessed_price_kopecks' => (int) round($totalAssessedPrice * 100),
        ];
    }

    private function buildAddress(string $city, string $street, string $house, string $address): string
    {
        if (trim($address) !== '') {
            return trim($address);
        }

        return trim(implode(', ', array_filter([
            $city,
            $street,
            $house !== '' ? 'д. '.$house : '',
        ])));
    }

    private function positiveDeliveryNumber($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function normalizePickupPoints(array $response): array
    {
        $items = $response['pickup_points']
            ?? $response['points']
            ?? $response['data']['pickup_points']
            ?? $response['data']['points']
            ?? $response['result']['pickup_points']
            ?? $response;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($point) {
            if (! is_array($point)) {
                return null;
            }

            $location = $point['position'] ?? $point['location'] ?? $point['coordinates'] ?? [];
            $lat = $point['lat'] ?? $point['latitude'] ?? $location['lat'] ?? $location['latitude'] ?? null;
            $lon = $point['lon'] ?? $point['lng'] ?? $point['longitude'] ?? $location['lon'] ?? $location['lng'] ?? $location['longitude'] ?? null;
            $id = $point['id'] ?? $point['pickup_point_id'] ?? $point['code'] ?? $point['station_id'] ?? null;
            $address = $point['address'] ?? '';
            if (is_array($address)) {
                $address = $address['full_address']
                    ?? $address['full']
                    ?? $address['formatted']
                    ?? $address['address']
                    ?? trim(implode(', ', array_filter([
                        $address['city'] ?? null,
                        $address['street'] ?? null,
                        $address['house'] ?? null,
                    ])));
            }

            if (! $id) {
                return null;
            }

            return [
                'id' => (string) $id,
                'name' => $point['name'] ?? $point['title'] ?? 'ПВЗ Яндекс Доставки',
                'address' => (string) $address,
                'latitude' => $lat,
                'longitude' => $lon,
                'raw' => $point,
            ];
        }, $items)));
    }

    private function normalizeTariffs(array $response, string $deliveryType, string $address, string $pickupPointId): array
    {
        if (isset($response['pricing_total'])) {
            $cost = $this->parseYandexMoney((string) $response['pricing_total']);

            return $cost === null ? [] : [[
                'code' => $deliveryType === 'pickup_point' ? 'yandex_self_pickup' : 'yandex_time_interval',
                'name' => $deliveryType === 'pickup_point' ? 'До ПВЗ Яндекс Доставки' : 'До адреса Яндекс Доставкой',
                'description' => $deliveryType === 'pickup_point' ? 'ПВЗ: '.$pickupPointId : $address,
                'cost' => $cost,
                'cost_value' => $cost,
                'type' => $deliveryType,
                'period' => isset($response['delivery_days']) ? $response['delivery_days'].' дн.' : null,
                'delivery_days' => $response['delivery_days'] ?? null,
                'raw' => $response,
            ]];
        }

        $items = $response['tariffs']
            ?? $response['offers']
            ?? $response['services']
            ?? $response['data']['tariffs']
            ?? $response['data']['offers']
            ?? $response['result']['tariffs']
            ?? null;

        if (! is_array($items)) {
            $items = [$response];
        }

        $tariffs = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $cost = $this->extractCost($item);
            if ($cost === null) {
                continue;
            }

            $tariffs[] = [
                'code' => (string) ($item['code'] ?? $item['id'] ?? $item['offer_id'] ?? 'yandex_'.$deliveryType.'_'.$index),
                'name' => $item['name'] ?? $item['title'] ?? ($deliveryType === 'pickup_point' ? 'До ПВЗ Яндекс Доставки' : 'До адреса Яндекс Доставкой'),
                'description' => $item['description'] ?? ($deliveryType === 'pickup_point' ? 'ПВЗ: '.$pickupPointId : $address),
                'cost' => $cost,
                'cost_value' => $cost,
                'type' => $deliveryType,
                'period' => $item['delivery_time'] ?? $item['period'] ?? $item['eta'] ?? null,
                'raw' => $item,
            ];
        }

        return $tariffs;
    }

    private function extractCost(array $item): ?float
    {
        $candidates = [
            $item['price'] ?? null,
            $item['cost'] ?? null,
            $item['amount'] ?? null,
            $item['tariff']['price'] ?? null,
            $item['pricing']['price'] ?? null,
            $item['pricing_total'] ?? null,
            $item['offer_details']['pricing_total'] ?? null,
            $item['offer_details']['pricing'] ?? null,
            $item['total_price'] ?? null,
            $item['delivery_price'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['amount'] ?? $candidate['value'] ?? null;
            }
            if (is_numeric($candidate)) {
                $value = (float) $candidate;
                return $value > 10000 ? round($value / 100, 2) : round($value, 2);
            }

            if (is_string($candidate)) {
                $value = $this->parseYandexMoney($candidate);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function parseYandexMoney(string $value): ?float
    {
        if (! preg_match('/-?\d+(?:[.,]\d+)?/u', $value, $matches)) {
            return null;
        }

        return round((float) str_replace(',', '.', $matches[0]), 2);
    }
}
