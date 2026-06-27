<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopRussianPostSettings;
use App\Services\ShopDeliveryActivitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopRussianPostController extends Controller
{
    public function getActiveSettings(): JsonResponse
    {
        $settings = app(ShopDeliveryActivitySyncService::class)->getMethodActive('russianpost') === false
            ? null
            : ShopRussianPostSettings::getActive();

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
                'cash_on_delivery_enabled' => (bool) $settings->cash_on_delivery_enabled,
                'create_order_in_account' => (bool) $settings->create_order_in_account,
            ],
        ]);
    }

    public function getOffices(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'required|string|min:2|max:255',
            'address' => 'nullable|string|max:255',
            'top' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $settings = $this->getSettingsOrFail();
            $city = trim((string) $request->query('city'));
            $address = trim((string) $request->query('address', ''));
            $top = max(1, min(10, (int) $request->query('top', 10)));
            $cityParts = $this->parseSettlement($city);
            $normalizedCity = $this->normalizeCityName($cityParts['settlement']);
            $cacheKey = 'russianpost_offices_v4_'.md5($normalizedCity.'|'.$address.'|'.$top);
            $limitCacheKey = 'russianpost_offices_limit_v1_'.md5($normalizedCity.'|'.$address);
            $debug = [
                'city' => $city,
                'address' => $address,
                'settlement' => $cityParts['settlement'],
                'normalized_city' => $normalizedCity,
                'codes_source' => null,
                'codes_count' => 0,
                'details_count' => 0,
                'filtered' => false,
                'offices_count' => 0,
            ];

            if (Cache::has($cacheKey)) {
                $cachedOffices = Cache::get($cacheKey);
                if (! empty($cachedOffices)) {
                    return response()->json([
                        'success' => true,
                        'data' => $cachedOffices,
                        'debug' => [
                            ...$debug,
                            'cache_hit' => true,
                            'offices_count' => count($cachedOffices),
                        ],
                    ]);
                }

                Cache::forget($cacheKey);
            }

            if ($address === '') {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'debug' => [
                        ...$debug,
                        'no_api_request' => true,
                        'reason' => 'exact_address_required',
                    ],
                ]);
            }

            if (Cache::has($limitCacheKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Почта России временно ограничила запросы API. Попробуйте повторить позже.',
                    'data' => [],
                    'debug' => [
                        ...$debug,
                        'limit_cache_hit' => true,
                    ],
                ], 429);
            }

            $codes = [];
            $offices = [];

            $addressOffices = $this->getOfficesByAddress($settings, $address, $top);
            $codes = $addressOffices['codes'];
            $offices = $addressOffices['offices'];
            $debug['codes_source'] = 'address';
            $debug['post_response'] = $addressOffices['debug'] ?? null;

            if (($addressOffices['limit_exceeded'] ?? false) && Cache::has($cacheKey.'_last_success')) {
                $cachedOffices = Cache::get($cacheKey.'_last_success');
                if (! empty($cachedOffices)) {
                    return response()->json([
                        'success' => true,
                        'data' => $cachedOffices,
                        'debug' => [
                            ...$debug,
                            'cache_hit' => true,
                            'cache_reason' => 'russianpost_token_limit_exceeded',
                            'offices_count' => count($cachedOffices),
                        ],
                    ]);
                }
            }

            if ($addressOffices['limit_exceeded'] ?? false) {
                Cache::put($limitCacheKey, true, now()->addMinutes(5));

                return response()->json([
                    'success' => false,
                    'message' => 'Почта России вернула Token requests limit exceeded. Запросы к API временно остановлены, чтобы не расходовать лимит дальше.',
                    'data' => [],
                    'debug' => $debug,
                ], 429);
            }
            $debug['codes_count'] = count($codes);

            if (! empty($codes)) {
                $details = $this->loadOfficeDetails($settings, array_slice($codes, 0, $top));
                $debug['details_count'] = count($details);
                $offices = $this->mergeOffices(
                    $offices,
                    $details
                );
            }
            $debug['offices_count'] = count($offices);

            if (empty($offices) && ! empty($codes)) {
                $offices = $this->buildOfficeStubsFromCodes($codes);
                $debug['used_code_stubs'] = true;
                $debug['offices_count'] = count($offices);
            }

            if (! empty($offices)) {
                Cache::put($cacheKey, $offices, now()->addHours(12));
                Cache::put($cacheKey.'_last_success', $offices, now()->addDays(7));
            }

            return response()->json([
                'success' => true,
                'data' => $offices,
                'debug' => $debug,
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
            'address' => 'nullable|string|max:255',
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
            $resolvedOffice = null;
            $resolvedAddress = $this->buildAddress($request);

            if ($indexTo === '' && $deliveryType === 'address') {
                $indexTo = $this->normalizeAddressIndex($settings, $resolvedAddress);

                if ($indexTo === '') {
                    $fallbackOffice = $this->resolveCityOffice($settings, (string) $request->query('city'), $resolvedAddress);
                    if ($fallbackOffice) {
                        $resolvedOffice = $fallbackOffice;
                        $indexTo = preg_replace('/\D+/', '', (string) ($fallbackOffice['postal_code'] ?? $fallbackOffice['id'] ?? ''));
                    }
                }
            }

            if ($indexTo === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось определить индекс получателя для расчета Почты России',
                    'data' => [],
                ]);
            }

            if (! $resolvedOffice) {
                $resolvedOffice = $this->resolveOfficeByPostalCode($settings, $indexTo);
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

            $publicTariff = $this->calculatePublicTariff($settings, $indexTo, $cargo);
            $data = $publicTariff['response'];
            $cost = $publicTariff['cost'];
            $tariffSource = 'public_tariff';

            if ($cost === null) {
                $response = Http::withOptions($this->httpOptions())
                    ->withHeaders($this->headers($settings))
                    ->post('https://otpravka-api.pochta.ru/1.0/tariff', $payload);

                if (! $response->successful()) {
                    throw new \RuntimeException($this->extractError($response));
                }

                $data = $response->json() ?: [];
                $cost = $this->extractTariffCost($data);
                $tariffSource = 'otpravka_api_fallback';
            }

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
                    'description' => $deliveryType === 'address' ? $resolvedAddress : 'Индекс отделения: '.$indexTo,
                    'cost' => $cost,
                    'cost_value' => $cost,
                    'type' => $deliveryType,
                    'postal_code' => $indexTo,
                    'resolved_postal_code' => $indexTo,
                    'resolved_address' => $resolvedAddress,
                    'resolved_office' => $resolvedOffice,
                    'period' => $this->extractPeriod($data),
                ]],
                'meta' => [
                    'tariff_source' => $tariffSource,
                    'request_payload' => $payload,
                    'server_response' => $data,
                    'resolved_postal_code' => $indexTo,
                    'resolved_address' => $resolvedAddress,
                    'resolved_office' => $resolvedOffice,
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

        if (! $settings || ! $settings->api_token || ! $settings->login || ! $settings->password || ! $settings->sender_postal_code) {
            throw new \RuntimeException('Активные настройки Почты России заполнены не полностью');
        }

        return $settings;
    }

    private function calculatePublicTariff(ShopRussianPostSettings $settings, string $indexTo, array $cargo): array
    {
        $indexFrom = preg_replace('/\D+/', '', (string) $settings->sender_postal_code);
        $indexTo = preg_replace('/\D+/', '', $indexTo);
        $weight = (int) max(1, round($cargo['weight'] * 1000));

        if ($indexFrom === '' || $indexTo === '') {
            return [
                'cost' => null,
                'response' => [
                    'error' => 'Не заполнены индексы отправителя или получателя',
                ],
            ];
        }

        $cacheKey = 'russianpost_public_tariff_v1_'.md5($indexFrom.'|'.$indexTo.'|'.$weight);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = Http::withOptions([
            'verify' => filter_var(config('services.russianpost.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
            'timeout' => 20,
        ])->get('https://tariff.russianpost.ru/tariff/v1/calculate', [
            'json' => '',
            'object' => 23030,
            'from' => $indexFrom,
            'to' => $indexTo,
            'weight' => $weight,
        ]);

        if (! $response->successful()) {
            return [
                'cost' => null,
                'response' => [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ],
            ];
        }

        $data = $response->json() ?: [];
        $cost = $this->extractPublicTariffCost($data);
        $result = [
            'cost' => $cost,
            'response' => $data,
        ];

        if ($cost !== null) {
            Cache::put($cacheKey, $result, now()->addHours(6));
        }

        return $result;
    }

    private function headers(ShopRussianPostSettings $settings): array
    {
        return [
            'Authorization' => 'AccessToken '.$settings->api_token,
            'X-User-Authorization' => $this->buildUserAuthorizationHeader($settings),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json;charset=UTF-8',
        ];
    }

    private function buildUserAuthorizationHeader(ShopRussianPostSettings $settings, bool $forceLoginPassword = false): string
    {
        $value = trim((string) $settings->password);
        if (str_starts_with(mb_strtolower($value), 'basic ')) {
            $value = trim(mb_substr($value, 6));
        }

        if ($forceLoginPassword || ! $this->looksLikeBase64UserKey($value)) {
            $value = base64_encode($settings->login.':'.((string) $settings->password));
        }

        return 'Basic '.$value;
    }

    private function looksLikeBase64UserKey(string $value): bool
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
            return false;
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) && str_contains($decoded, ':');
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
        $cacheKey = 'russianpost_address_index_v1_'.md5($address);
        if (Cache::has($cacheKey)) {
            return (string) Cache::get($cacheKey);
        }

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

        $index = preg_replace('/\D+/', '', $this->firstScalarValue($item['index'] ?? null, $item['postal-code'] ?? null) ?? '');
        if ($index !== '') {
            Cache::put($cacheKey, $index, now()->addDays(7));
        }

        return $index;
    }

    private function buildAddress(Request $request): string
    {
        $address = trim((string) $request->query('address', ''));
        if ($address !== '') {
            return $address;
        }

        return trim(implode(', ', array_filter([
            $request->query('city'),
            $request->query('street'),
            $request->query('house') ? 'д. '.$request->query('house') : null,
        ])));
    }

    private function resolveCityOffice(ShopRussianPostSettings $settings, string $city, string $address = ''): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }

        $cacheKey = 'russianpost_city_office_v1_'.md5($city.'|'.$address);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $queries = array_values(array_unique(array_filter([
            $address,
            $city.', главпочтамт',
            $city.', центральное отделение',
            $city,
        ])));

        foreach ($queries as $query) {
            $result = $this->getOfficesByAddress($settings, $query, 10);
            $offices = $result['offices'] ?? [];
            $codes = $result['codes'] ?? [];

            if (! empty($offices)) {
                $office = $this->pickPrimaryOffice($offices);
                Cache::put($cacheKey, $office, now()->addDays(7));
                return $office;
            }

            if (! empty($codes)) {
                $details = $this->loadOfficeDetails($settings, array_slice($codes, 0, 10));
                $office = ! empty($details)
                    ? $this->pickPrimaryOffice($details)
                    : ($this->buildOfficeStubsFromCodes($codes)[0] ?? null);

                if ($office) {
                    Cache::put($cacheKey, $office, now()->addDays(7));
                    return $office;
                }
            }
        }

        return null;
    }

    private function resolveOfficeByPostalCode(ShopRussianPostSettings $settings, string $postalCode): ?array
    {
        $postalCode = preg_replace('/\D+/', '', $postalCode);
        if ($postalCode === '') {
            return null;
        }

        $cacheKey = 'russianpost_office_by_postal_code_v1_'.$postalCode;
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $offices = $this->loadOfficeDetails($settings, [$postalCode]);
        $office = $offices[0] ?? ($this->buildOfficeStubsFromCodes([$postalCode])[0] ?? null);

        if ($office) {
            Cache::put($cacheKey, $office, now()->addDays(7));
        }

        return $office;
    }

    private function pickPrimaryOffice(array $offices): ?array
    {
        if (empty($offices)) {
            return null;
        }

        usort($offices, function ($a, $b) {
            $aText = mb_strtolower(($a['name'] ?? '').' '.($a['address'] ?? ''));
            $bText = mb_strtolower(($b['name'] ?? '').' '.($b['address'] ?? ''));
            $aScore = (str_contains($aText, 'главпочтамт') ? 0 : 10) + (str_contains($aText, 'централь') ? 0 : 1);
            $bScore = (str_contains($bText, 'главпочтамт') ? 0 : 10) + (str_contains($bText, 'централь') ? 0 : 1);
            return $aScore <=> $bScore;
        });

        return $offices[0] ?? null;
    }

    private function getOfficeCodesByAddress(ShopRussianPostSettings $settings, string $address, int $top = 50): array
    {
        return $this->getOfficesByAddress($settings, $address, $top)['codes'];
    }

    private function getOfficesByAddress(ShopRussianPostSettings $settings, string $address, int $top = 50): array
    {
        $attempts = [
            [
                'auth_mode' => $this->looksLikeBase64UserKey(trim((string) $settings->password)) ? 'stored_user_key' : 'login_password',
                'headers' => $this->headers($settings),
            ],
        ];

        if ($this->looksLikeBase64UserKey(trim((string) $settings->password))) {
            $attempts[] = [
                'auth_mode' => 'login_password_fallback',
                'headers' => [
                    ...$this->headers($settings),
                    'X-User-Authorization' => $this->buildUserAuthorizationHeader($settings, true),
                ],
            ];
        }

        $debugAttempts = [];
        foreach ($attempts as $attempt) {
            $response = Http::withOptions($this->httpOptions())
                ->withHeaders($attempt['headers'])
                ->get('https://otpravka-api.pochta.ru/postoffice/1.0/by-address', [
                    'address' => $address,
                    'top' => $top,
                ]);

            if (! $response->successful()) {
                Log::warning('RussianPost address offices warning: '.$this->extractError($response), [
                    'address' => $address,
                    'auth_mode' => $attempt['auth_mode'],
                ]);

                $debugAttempts[] = [
                    'auth_mode' => $attempt['auth_mode'],
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ];

                continue;
            }

            $data = $response->json();
            $codes = $this->normalizeOfficeCodes($data);
            $offices = $this->normalizeOffices($data);
            $debugAttempts[] = [
                'auth_mode' => $attempt['auth_mode'],
                'status' => $response->status(),
                'body' => $data,
                'codes_count' => count($codes),
                'offices_count' => count($offices),
            ];

            if (! empty($codes) || ! empty($offices)) {
                return [
                    'codes' => $codes,
                    'offices' => $offices,
                    'debug' => $debugAttempts,
                ];
            }
        }

        return [
            'codes' => [],
            'offices' => [],
            'debug' => $debugAttempts,
            'limit_exceeded' => collect($debugAttempts)->contains(function ($attempt) {
                return (int) ($attempt['status'] ?? 0) === 509
                    || str_contains($this->stringValue($attempt['body'] ?? ''), 'Token requests limit exceeded');
            }),
        ];
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
                        ->get('https://otpravka-api.pochta.ru/postoffice/1.0/'.rawurlencode((string) $code));
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

    private function buildOfficeStubsFromCodes(array $codes): array
    {
        return array_map(function ($code) {
            $code = (string) $code;

            return [
                'id' => $code,
                'postal_code' => $code,
                'name' => 'Отделение Почты России',
                'address' => 'Индекс отделения: '.$code,
                'latitude' => null,
                'longitude' => null,
                'work_time' => null,
                'raw' => ['postal-code' => $code],
            ];
        }, array_values(array_unique(array_filter($codes))));
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
                ? $this->firstScalarValue(
                    $item['postal-code'] ?? null,
                    $item['postoffice-code'] ?? null,
                    $item['post-office-code'] ?? null,
                    $item['postofficeCode'] ?? null,
                    $item['postalCode'] ?? null,
                    $item['index'] ?? null,
                    $item['postal_code'] ?? null
                )
                : $item;

            $code = preg_replace('/\D+/', '', $this->stringValue($code));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function normalizeOffices($data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $items = $data['postoffices'] ?? $data['offices'] ?? $data['data'] ?? $data;
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => $this->mapOffice($item), $items)));
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

        $postalCode = $this->firstScalarValue(
            $item['postal-code'] ?? null,
            $item['postoffice-code'] ?? null,
            $item['post-office-code'] ?? null,
            $item['postofficeCode'] ?? null,
            $item['postalCode'] ?? null,
            $item['index'] ?? null,
            $item['postal_code'] ?? null
        );
        if (! $postalCode) {
            return null;
        }

        return [
            'id' => $this->stringValue($postalCode),
            'postal_code' => $this->stringValue($postalCode),
            'name' => $this->firstScalarValue(
                $item['name'] ?? null,
                $item['postoffice-name'] ?? null,
                $item['post-office-name'] ?? null,
                'Отделение Почты России'
            ),
            'address' => $this->firstScalarValue(
                $item['address-source'] ?? null,
                $item['address'] ?? null,
                $item['full-address'] ?? null,
                $item['fullAddress'] ?? null,
                $item['address-string'] ?? null,
                ''
            ),
            'latitude' => $this->extractOfficeLatitude($item),
            'longitude' => $this->extractOfficeLongitude($item),
            'work_time' => $this->firstScalarValue($item['schedule'] ?? null, $item['working-hours'] ?? null),
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

        $value = str_replace(',', '.', $this->stringValue($value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function firstScalarValue(...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->stringValue($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function stringValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $normalized = $this->stringValue($nestedValue);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
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

    private function extractPublicTariffCost(array $data): ?float
    {
        if (! empty($data['errors']) || ! empty($data['error'])) {
            return null;
        }

        foreach (['paynds', 'paymoneynds', 'pay', 'paymoney'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return round(((float) $data[$key]) / 100, 2);
            }
        }

        foreach (['ground', 'avia'] as $section) {
            if (isset($data[$section]['valnds']) && is_numeric($data[$section]['valnds'])) {
                return round(((float) $data[$section]['valnds']) / 100, 2);
            }
            if (isset($data[$section]['val']) && is_numeric($data[$section]['val'])) {
                return round(((float) $data[$section]['val']) / 100, 2);
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
            return $this->firstScalarValue($data['error'] ?? null, $data['message'] ?? null, $data['desc'] ?? null)
                ?? ($response->body() ?: 'Ошибка API Почты России');
        }

        return $response->body() ?: 'Ошибка API Почты России';
    }
}
