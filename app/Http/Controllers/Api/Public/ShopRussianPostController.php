<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopRussianPostSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopRussianPostController extends Controller
{
    public function getActiveSettings(): JsonResponse
    {
        $settings = ShopRussianPostSettings::getActive();

        if (! $settings) {
            return response()->json([
                'success' => false,
                'message' => 'Настройки Почты России не найдены',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sender_city' => $settings->sender_city,
                'sender_postal_code' => $settings->sender_postal_code,
                'default_weight' => (float) ($settings->default_weight ?? 0.5),
                'default_length' => (float) ($settings->default_length ?? 10),
                'default_width' => (float) ($settings->default_width ?? 10),
                'default_height' => (float) ($settings->default_height ?? 10),
            ],
        ]);
    }

    public function getOffices(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'required|string|min:2|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        try {
            $settings = $this->getSettingsOrFail();
            $city = trim((string) $request->query('city'));
            $address = trim((string) $request->query('address', ''));
            $cityParts = $this->parseSettlement($city);
            $normalizedCity = $this->normalizeCityName($cityParts['settlement']);
            $cacheKey = 'russianpost_offices_v2_'.md5($normalizedCity.'|'.$address);

            if ($address === '' && Cache::has($cacheKey)) {
                $cachedOffices = Cache::get($cacheKey);
                if (! empty($cachedOffices)) {
                    return response()->json([
                        'success' => true,
                        'data' => $cachedOffices,
                    ]);
                }

                Cache::forget($cacheKey);
            }

            $legacyCacheKey = 'russianpost_offices_'.md5($normalizedCity.'|'.$address);
            if ($address === '') {
                Cache::forget($legacyCacheKey);
            }

            $codes = [];
            $offices = [];
            $shouldFilterOfficesByCity = false;

            if (empty($offices) && $address !== '') {
                $codes = $this->getOfficeCodesByAddress($settings, $address, 50);
                $shouldFilterOfficesByCity = true;
            }

            if (empty($codes)) {
                $codes = $this->getOfficeCodesBySettlement($settings, $city);
                $shouldFilterOfficesByCity = false;
            }

            if (empty($offices) && empty($codes)) {
                $codes = $this->getOfficeCodesByAddress($settings, $city, 1000);
                $shouldFilterOfficesByCity = true;
            }

            $offices = $this->mergeOffices(
                $offices,
                $this->loadOfficeDetails($settings, $codes)
            );
            if ($shouldFilterOfficesByCity) {
                $offices = $this->filterOfficesByCity($offices, $normalizedCity);
            }

            if ($address === '' && ! empty($offices)) {
                Cache::put($cacheKey, $offices, now()->addHours(12));
            }

            return response()->json([
                'success' => true,
                'data' => $offices,
            ]);
        } catch (\Throwable $e) {
            Log::error('RussianPost offices error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения отделений Почты России: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function getTariffs(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'required|string|min:2|max:255',
            'delivery_type' => 'required|string|in:address,office',
            'street' => 'nullable|string|max:255',
            'house' => 'nullable|string|max:50',
            'postal_code' => 'nullable|string|max:20',
            'cart_items' => 'nullable',
        ]);

        try {
            $settings = $this->getSettingsOrFail();
            $cartItems = $request->query('cart_items', []);
            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $deliveryType = $request->query('delivery_type');
            $indexTo = preg_replace('/\D+/', '', (string) $request->query('postal_code', ''));

            if ($indexTo === '' && $deliveryType === 'address') {
                $indexTo = $this->normalizeAddressIndex($settings, $this->buildAddress($request));
            }

            if ($indexTo === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось определить индекс получателя для расчета Почты России',
                    'data' => [],
                ]);
            }

            $cargo = $this->calculateCargo($cartItems, $settings);
            $payload = [
                'index-from' => preg_replace('/\D+/', '', (string) $settings->sender_postal_code),
                'index-to' => $indexTo,
                'mail-category' => 'ORDINARY',
                'mail-type' => 'ONLINE_PARCEL',
                'mass' => (int) max(1, round($cargo['weight'] * 1000)),
                'dimension' => [
                    'length' => (int) max(1, round($cargo['length'])),
                    'width' => (int) max(1, round($cargo['width'])),
                    'height' => (int) max(1, round($cargo['height'])),
                ],
                'fragile' => false,
                'inventory' => false,
                'with-order-of-notice' => false,
                'with-simple-notice' => false,
            ];

            if ($deliveryType === 'address') {
                $payload['courier'] = true;
            }

            $response = Http::withOptions($this->httpOptions())
                ->withHeaders($this->headers($settings))
                ->post('https://otpravka-api.pochta.ru/1.0/tariff', $payload);

            if (! $response->successful()) {
                throw new \RuntimeException($this->extractError($response));
            }

            $data = $response->json() ?: [];
            $cost = $this->extractTariffCost($data);

            if ($cost === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Почта России не вернула стоимость доставки для выбранного направления',
                    'data' => [],
                    'meta' => ['response' => $data],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [[
                    'code' => 'russianpost_'.$deliveryType,
                    'name' => $deliveryType === 'address' ? 'Почта России до адреса' : 'Почта России до отделения',
                    'description' => $deliveryType === 'address' ? $this->buildAddress($request) : 'Индекс отделения: '.$indexTo,
                    'cost' => $cost,
                    'cost_value' => $cost,
                    'type' => $deliveryType,
                    'postal_code' => $indexTo,
                    'period' => $this->extractPeriod($data),
                ]],
                'meta' => [
                    'request_payload' => $payload,
                    'server_response' => $data,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('RussianPost tariff error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета Почты России: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    private function getSettingsOrFail(): ShopRussianPostSettings
    {
        $settings = ShopRussianPostSettings::getActive();

        if (! $settings || ! $settings->api_token || ! $settings->login || ! $settings->sender_postal_code) {
            throw new \RuntimeException('Активные настройки Почты России заполнены не полностью');
        }

        return $settings;
    }

    private function headers(ShopRussianPostSettings $settings): array
    {
        return [
            'Authorization' => 'AccessToken '.$settings->api_token,
            'X-User-Authorization' => 'Basic '.base64_encode($settings->login.':'.($settings->password ?? '')),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json;charset=UTF-8',
        ];
    }

    private function httpOptions(): array
    {
        return [
            'verify' => filter_var(config('services.russianpost.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
            'timeout' => 30,
        ];
    }

    private function normalizeAddressIndex(ShopRussianPostSettings $settings, string $address): string
    {
        $response = Http::withOptions($this->httpOptions())
            ->withHeaders($this->headers($settings))
            ->post('https://otpravka-api.pochta.ru/1.0/clean/address', [[
                'id' => 'checkout',
                'original-address' => $address,
            ]]);

        if (! $response->successful()) {
            return '';
        }

        $data = $response->json();
        $item = is_array($data) ? ($data[0] ?? []) : [];

        return preg_replace('/\D+/', '', (string) ($item['index'] ?? $item['postal-code'] ?? ''));
    }

    private function buildAddress(Request $request): string
    {
        return trim(implode(', ', array_filter([
            $request->query('city'),
            $request->query('street'),
            $request->query('house') ? 'д. '.$request->query('house') : null,
        ])));
    }

    private function getOfficeCodesBySettlement(ShopRussianPostSettings $settings, string $city): array
    {
        $parts = $this->parseSettlement($city);
        $rawParts = array_values(array_filter(array_map('trim', preg_split('/,/', $city))));
        $rawRegion = $rawParts[1] ?? null;
        $settlement = $parts['settlement'];
        $rawSettlement = $rawParts[0] ?? $city;
        $normalizedSettlement = $this->normalizeSettlementNeedle($this->normalizeCityName($settlement));
        $paramVariants = [
            array_filter([
                'settlement' => $settlement,
                'region' => $parts['region'],
                'district' => $parts['district'],
            ]),
            array_filter([
                'settlement' => $settlement,
                'region' => $rawRegion,
                'district' => $parts['district'],
            ]),
            ['settlement' => $settlement],
            ['settlement' => $rawSettlement],
        ];

        if ($normalizedSettlement !== '' && $normalizedSettlement !== $this->normalizeCityName($settlement)) {
            $paramVariants[] = ['settlement' => $normalizedSettlement];
        }

        if ($this->isFederalCity($normalizedSettlement)) {
            $paramVariants[] = [
                'settlement' => $settlement,
                'region' => $settlement,
            ];
            $paramVariants[] = [
                'region' => $settlement,
            ];
            $paramVariants[] = [
                'settlement' => $rawSettlement,
                'region' => $settlement,
            ];
        }

        $codes = [];
        $usedVariants = [];
        foreach ($paramVariants as $params) {
            $paramsKey = json_encode($params, JSON_UNESCAPED_UNICODE);
            if (! $params || isset($usedVariants[$paramsKey])) {
                continue;
            }
            $usedVariants[$paramsKey] = true;

            $response = Http::withOptions($this->httpOptions())
                ->withHeaders($this->headers($settings))
                ->get('https://otpravka-api.pochta.ru/postoffice/1.0/settlement.offices.codes', $params);

            if (! $response->successful()) {
                Log::warning('RussianPost settlement offices warning: '.$this->extractError($response), [
                    'city' => $city,
                    'params' => $params,
                ]);

                continue;
            }

            $codes = array_merge($codes, $this->normalizeOfficeCodes($response->json()));
            if (! empty($codes)) {
                break;
            }
        }

        return array_values(array_unique($codes));
    }

    private function isFederalCity(string $normalizedCity): bool
    {
        return in_array($normalizedCity, [
            'москва',
            'moscow',
            'санкт петербург',
            'санкт-петербург',
            'спб',
            'петербург',
            'севастополь',
        ], true);
    }

    private function getOfficeCodesByAddress(ShopRussianPostSettings $settings, string $address, int $top = 50): array
    {
        $response = Http::withOptions($this->httpOptions())
            ->withHeaders($this->headers($settings))
            ->get('https://otpravka-api.pochta.ru/postoffice/1.0/by-address', [
                'address' => $address,
                'top' => $top,
            ]);

        if (! $response->successful()) {
            Log::warning('RussianPost address offices warning: '.$this->extractError($response), ['address' => $address]);

            return [];
        }

        return $this->normalizeOfficeCodes($response->json());
    }

    private function loadOfficeDetails(ShopRussianPostSettings $settings, array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        $offices = [];

        foreach (array_chunk($codes, 40) as $chunk) {
            $responses = Http::pool(function ($pool) use ($settings, $chunk) {
                return array_map(function ($code) use ($pool, $settings) {
                    return $pool
                        ->withOptions($this->httpOptions())
                        ->withHeaders($this->headers($settings))
                        ->get('https://otpravka-api.pochta.ru/postoffice/1.0/'.rawurlencode((string) $code), [
                            'filter-by-office-type' => 'true',
                        ]);
                }, $chunk);
            });

            foreach ($responses as $index => $response) {
                $code = $chunk[$index] ?? null;
                if (! $response->successful()) {
                    Log::warning('RussianPost office details warning: '.$this->extractError($response), ['postal_code' => $code]);
                    continue;
                }

                $office = $this->mapOffice($response->json());
                if ($office) {
                    $offices[] = $office;
                }
            }
        }

        return $offices;
    }

    private function mergeOffices(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $offices) {
            foreach ($offices as $office) {
                if (! is_array($office)) {
                    continue;
                }

                $key = (string) ($office['postal_code'] ?? $office['id'] ?? '');
                if ($key === '') {
                    continue;
                }

                if (! isset($merged[$key])) {
                    $merged[$key] = $office;
                    continue;
                }

                $merged[$key] = array_merge(
                    $merged[$key],
                    array_filter($office, fn ($value) => $value !== null && $value !== '')
                );
            }
        }

        return array_values($merged);
    }

    private function normalizeOfficeCodes($data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $items = $data['postoffices'] ?? $data['offices'] ?? $data['data'] ?? $data;
        if (! is_array($items)) {
            return [];
        }

        $codes = [];
        foreach ($items as $item) {
            $code = is_array($item)
                ? ($item['postal-code'] ?? $item['index'] ?? $item['postal_code'] ?? null)
                : $item;

            $code = preg_replace('/\D+/', '', (string) $code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function parseSettlement(string $city): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/,/', $city))));
        $settlement = $parts[0] ?? $city;
        $region = $parts[1] ?? null;
        $district = null;

        $settlement = preg_replace('/^(г\.?|город)\s+/iu', '', trim($settlement));
        $settlement = trim((string) $settlement);

        if ($region) {
            $region = preg_replace('/\s+(обл\.?|область|край|респ\.?|республика|АО|автономный округ)$/iu', '', trim($region));
            $region = trim((string) $region);
        }

        if (isset($parts[2])) {
            $district = preg_replace('/\s+(р-н|район)$/iu', '', trim($parts[2]));
            $district = trim((string) $district);
        }

        return [
            'settlement' => $settlement ?: $city,
            'region' => $region ?: null,
            'district' => $district ?: null,
        ];
    }

    private function filterOfficesByCity(array $offices, string $normalizedCity): array
    {
        $cityNeedle = $this->normalizeSettlementNeedle($normalizedCity);
        if ($cityNeedle === '') {
            return $offices;
        }

        $filtered = array_values(array_filter($offices, function (array $office) use ($cityNeedle) {
            $address = $this->normalizeCityName((string) ($office['address'] ?? ''));
            $name = $this->normalizeCityName((string) ($office['name'] ?? ''));

            return $this->containsCityToken($address, $cityNeedle)
                || $this->containsCityToken($name, $cityNeedle);
        }));

        return $filtered;
    }

    private function normalizeSettlementNeedle(string $normalizedCity): string
    {
        $city = preg_replace('/\b(п|пос|поселок|посёлок|с|село|д|деревня|рп|рабочий поселок|рабочий посёлок)\.?\b/u', '', $normalizedCity);
        $city = str_replace('-', ' ', (string) $city);
        $city = preg_replace('/\s+/u', ' ', (string) $city);

        return trim((string) $city);
    }

    private function containsCityToken(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $haystack = str_replace('-', ' ', $haystack);

        return (bool) preg_match('/(?:^|[\s\-])'.preg_quote($needle, '/').'(?:$|[\s\-])/u', $haystack);
    }

    private function normalizeCityName(string $city): string
    {
        $city = mb_strtolower(trim($city));
        $city = preg_replace('/\b(г|город|обл|область|край|респ|республика|ао)\.?\b/u', '', $city);
        $city = preg_replace('/[^а-яёa-z0-9\- ]/u', ' ', $city);
        $city = preg_replace('/\s+/u', ' ', $city);

        return trim((string) $city);
    }

    private function mapOffice($item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $postalCode = $item['postal-code'] ?? $item['index'] ?? null;
        if (! $postalCode) {
            return null;
        }

        return [
            'id' => (string) $postalCode,
            'postal_code' => (string) $postalCode,
            'name' => $item['name'] ?? 'Отделение Почты России',
            'address' => $item['address-source'] ?? $item['address'] ?? $item['full-address'] ?? '',
            'latitude' => $this->extractOfficeLatitude($item),
            'longitude' => $this->extractOfficeLongitude($item),
            'work_time' => $item['schedule'] ?? $item['working-hours'] ?? null,
            'raw' => $item,
        ];
    }

    private function extractOfficeLatitude(array $item): ?float
    {
        return $this->normalizeCoordinate(
            $item['latitude']
            ?? $item['lat']
            ?? $item['geo-lat']
            ?? $item['geo_lat']
            ?? $item['coordinates']['latitude']
            ?? $item['coordinates']['lat']
            ?? $item['coordinates']['latitude-decimal']
            ?? $item['address']['latitude']
            ?? $item['address']['lat']
            ?? $item['address']['geo-lat']
            ?? $item['address']['geo_lat']
            ?? $item['gps-coordinates']['latitude']
            ?? $item['gps-coordinates']['lat']
            ?? $item['geo-position']['latitude']
            ?? $item['geo-position']['lat']
            ?? $item['gps']['latitude']
            ?? $item['gps']['lat']
            ?? null
        );
    }

    private function extractOfficeLongitude(array $item): ?float
    {
        return $this->normalizeCoordinate(
            $item['longitude']
            ?? $item['lon']
            ?? $item['lng']
            ?? $item['geo-lon']
            ?? $item['geo_lon']
            ?? $item['coordinates']['longitude']
            ?? $item['coordinates']['lon']
            ?? $item['coordinates']['lng']
            ?? $item['coordinates']['longitude-decimal']
            ?? $item['address']['longitude']
            ?? $item['address']['lon']
            ?? $item['address']['lng']
            ?? $item['address']['geo-lon']
            ?? $item['address']['geo_lon']
            ?? $item['gps-coordinates']['longitude']
            ?? $item['gps-coordinates']['lon']
            ?? $item['gps-coordinates']['lng']
            ?? $item['geo-position']['longitude']
            ?? $item['geo-position']['lon']
            ?? $item['geo-position']['lng']
            ?? $item['gps']['longitude']
            ?? $item['gps']['lon']
            ?? $item['gps']['lng']
            ?? null
        );
    }

    private function normalizeCoordinate($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(',', '.', (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function calculateCargo($cartItems, ShopRussianPostSettings $settings): array
    {
        $weight = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;

        foreach ((array) $cartItems as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $fields = $this->getItemDeliveryFields($item, $settings);
            $weight += $fields['weight'] * $quantity;
            $length = max($length, $fields['length']);
            $width = max($width, $fields['width']);
            $height = max($height, $fields['height']);
        }

        return [
            'weight' => $weight ?: (float) ($settings->default_weight ?? 0.5),
            'length' => $length ?: (float) ($settings->default_length ?? 10),
            'width' => $width ?: (float) ($settings->default_width ?? 10),
            'height' => $height ?: (float) ($settings->default_height ?? 10),
        ];
    }

    private function getItemDeliveryFields(array $item, ShopRussianPostSettings $settings): array
    {
        $weight = $this->positiveNumber($item['weight'] ?? null);
        $length = $this->positiveNumber($item['length'] ?? ($item['depth'] ?? null));
        $width = $this->positiveNumber($item['width'] ?? null);
        $height = $this->positiveNumber($item['height'] ?? null);

        if ((! $weight || ! $length || ! $width || ! $height) && ! empty($item['good_id'])) {
            $good = ShopGood::find($item['good_id']);
            if ($good) {
                $weight = $weight ?: $this->positiveNumber($good->weight ?? null);
                $length = $length ?: $this->positiveNumber($good->length ?? ($good->depth ?? null));
                $width = $width ?: $this->positiveNumber($good->width ?? null);
                $height = $height ?: $this->positiveNumber($good->height ?? null);
            }
        }

        return [
            'weight' => $weight ?: (float) ($settings->default_weight ?? 0.5),
            'length' => $length ?: (float) ($settings->default_length ?? 10),
            'width' => $width ?: (float) ($settings->default_width ?? 10),
            'height' => $height ?: (float) ($settings->default_height ?? 10),
        ];
    }

    private function positiveNumber($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function extractTariffCost(array $data): ?float
    {
        foreach (['total-rate', 'total-rate-with-vat', 'ground-rate', 'avia-rate'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return round(((float) $data[$key]) / 100, 2);
            }
        }

        return null;
    }

    private function extractPeriod(array $data): ?string
    {
        $min = $data['delivery-time']['min-days'] ?? $data['delivery-time-min'] ?? null;
        $max = $data['delivery-time']['max-days'] ?? $data['delivery-time-max'] ?? null;

        if ($min && $max) {
            return $min === $max ? $min.' дн.' : $min.'-'.$max.' дн.';
        }

        return null;
    }

    private function extractError($response): string
    {
        $data = $response->json();
        if (is_array($data)) {
            return $data['error'] ?? $data['message'] ?? $data['desc'] ?? $response->body();
        }

        return $response->body() ?: 'Ошибка API Почты России';
    }
}
