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
    public function getPickupPoints(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ]);

            $settings = $this->getSettingsOrFail();
            $rawCity = trim((string) $request->query('city'));
            $city = $this->normalizeSettlementName($rawCity);
            $location = $this->detectLocation($settings, $city, $request->query('latitude'), $request->query('longitude'));

            if (empty($location['geo_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Яндекс Доставка не смогла определить geo_id для города: '.$city,
                    'data' => [],
                    'meta' => [
                        'raw_city' => $rawCity,
                        'normalized_city' => $city,
                        'location' => $location,
                    ],
                ]);
            }

            $response = $this->yandexRequestVariants($settings, 'post', '/pickup-points/list', [
                array_filter([
                    'geo_id' => $location['geo_id'],
                    'type' => 'pickup_point',
                ], fn ($value) => $value !== null && $value !== ''),
                array_filter([
                    'geo_id' => $location['geo_id'],
                ], fn ($value) => $value !== null && $value !== ''),
                array_filter([
                    'location' => [
                        'geo_id' => $location['geo_id'],
                    ],
                    'type' => 'pickup_point',
                ], fn ($value) => $value !== null && $value !== ''),
                array_filter([
                    'location' => $location,
                    'city' => $city,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);
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
            $city = $this->normalizeSettlementName((string) $request->query('city'));
            $deliveryType = $request->query('delivery_type') === 'pvz' ? 'pickup_point' : $request->query('delivery_type');
            $cartItems = $request->query('cart_items', []);
            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $location = $this->detectLocation($settings, $city, $request->query('latitude'), $request->query('longitude'));
            $cargo = $this->calculateCargo($cartItems, $settings);
            $recipientAddress = $this->buildAddress($city, (string) $request->query('street', ''), (string) $request->query('house', ''), (string) $request->query('address', ''));
            $pickupPointId = trim((string) $request->query('pickup_point_id', ''));

            $payload = [
                'platform_station_id' => $settings->warehouse_id ?: null,
                'delivery_type' => $deliveryType,
                'location' => $location,
                'destination' => array_filter([
                    'address' => $recipientAddress,
                    'geo_id' => $location['geo_id'] ?? null,
                    'pickup_point_id' => $deliveryType === 'pickup_point' ? $pickupPointId : null,
                ], fn ($value) => $value !== null && $value !== ''),
                'items' => $cargo['items'],
                'places' => $cargo['places'],
                'dimensions' => $cargo['dimensions'],
                'weight' => $cargo['weight'],
            ];

            $response = $this->yandexRequest($settings, 'post', '/pricing-calculator', $payload);
            $tariffs = $this->normalizeTariffs($response, $deliveryType, $recipientAddress, $pickupPointId);

            return response()->json([
                'success' => true,
                'data' => $tariffs,
                'meta' => [
                    'location' => $location,
                    'cargo' => $cargo,
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
        $locationPayload = array_filter([
            'address' => $city,
            'lat' => is_numeric($latitude) ? (float) $latitude : null,
            'lon' => is_numeric($longitude) ? (float) $longitude : null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->yandexRequestVariants($settings, 'post', '/location/detect', [
                ['address' => $city],
                ['address' => $cityWithCountry],
                ['location' => $locationPayload],
                ['location' => $city],
                ['location' => $cityWithCountry],
                ['text' => $city],
                ['text' => $cityWithCountry],
            ]);
            $location = $response['location'] ?? $response['data']['location'] ?? $response['result'] ?? $response;

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

    private function calculateCargo(array $cartItems, ShopCarrierDeliverySettings $settings): array
    {
        $defaultWeight = max(0.01, (float) ($settings->default_weight ?? 0.5));
        $defaultLength = max(1, (float) ($settings->default_length ?? 10));
        $defaultWidth = max(1, (float) ($settings->default_width ?? 10));
        $defaultHeight = max(1, (float) ($settings->default_height ?? 10));

        $totalWeight = 0;
        $maxLength = 0;
        $maxWidth = 0;
        $totalHeight = 0;
        $items = [];

        foreach ($cartItems as $index => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $weight = max(0.01, (float) ($item['weight'] ?? $defaultWeight));
            $length = max(1, (float) ($item['length'] ?? $defaultLength));
            $width = max(1, (float) ($item['width'] ?? $defaultWidth));
            $height = max(1, (float) ($item['height'] ?? $defaultHeight));

            $totalWeight += $weight * $quantity;
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

        return [
            'items' => $items,
            'places' => [[
                'physical_dims' => $dimensions,
                'dimensions' => $dimensions,
                'weight' => max(0.01, $totalWeight),
            ]],
            'dimensions' => $dimensions,
            'weight' => max(0.01, $totalWeight),
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

            $location = $point['location'] ?? $point['coordinates'] ?? [];
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
        }

        return null;
    }
}
