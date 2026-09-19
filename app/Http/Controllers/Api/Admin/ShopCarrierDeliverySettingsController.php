<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopCarrierDeliverySettings;
use App\Services\OzonDeliveryService;
use App\Services\ShopDeliveryActivitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ShopCarrierDeliverySettingsController extends Controller
{
    private array $allowedCarriers = ['ozon', 'yandex'];

    public function show(Request $request, string $carrier): JsonResponse
    {
        if ($access = $this->checkAccess($request)) {
            return $access;
        }

        if (! in_array($carrier, $this->allowedCarriers, true)) {
            return response()->json(['success' => false, 'message' => 'Неизвестная служба доставки'], 404);
        }

        $settings = ShopCarrierDeliverySettings::firstOrCreate(['carrier' => $carrier], ['is_active' => false]);
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive($carrier, $settings);

        return response()->json([
            'success' => true,
            'data' => $this->settingsPayload($settings),
        ]);
    }

    public function save(Request $request, string $carrier): JsonResponse
    {
        if ($access = $this->checkAccess($request)) {
            return $access;
        }

        if (! in_array($carrier, $this->allowedCarriers, true)) {
            return response()->json(['success' => false, 'message' => 'Неизвестная служба доставки'], 404);
        }

        $validator = Validator::make($request->all(), [
            'api_url' => 'nullable|string|max:255',
            'api_token' => 'nullable|string',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|string|max:255',
            'sender_city' => 'nullable|string|max:255',
            'sender_street' => 'nullable|string|max:255',
            'sender_house' => 'nullable|string|max:255',
            'sender_postal_code' => 'nullable|string|max:255',
            'default_weight' => 'nullable|numeric|min:0.01|max:1000',
            'default_length' => 'nullable|numeric|min:1|max:1000',
            'default_width' => 'nullable|numeric|min:1|max:1000',
            'default_height' => 'nullable|numeric|min:1|max:1000',
            'settings' => 'nullable|array',
            'oauth_client_id' => 'nullable|string|max:255',
            'oauth_client_secret' => 'nullable|string|max:2000',
            'oauth_access_token' => 'nullable|string|max:4000',
            'oauth_expires_at' => 'nullable|date',
            'seller_id' => 'nullable|string|max:255',
            'store_domain' => 'nullable|string|max:255',
            'logistics_schema' => 'nullable|in:FBS,FBO,BOTH',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Ошибка валидации', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $settingsData = $this->normalizeSettingsPayload($request->input('settings'), $carrier);
        if ($carrier === 'yandex' && ($settingsData['api_mode'] ?? 'other_day') === 'express' && trim((string) ($settingsData['express_delivery_city'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Для режима Яндекс Экспресс обязательно укажите город доставки.',
                'errors' => [
                    'settings.express_delivery_city' => ['Для режима Яндекс Экспресс обязательно укажите город доставки.'],
                ],
            ], 422);
        }
        $validated['settings'] = $settingsData;

        $existing = ShopCarrierDeliverySettings::where('carrier', $carrier)->first();
        if ($carrier === 'ozon') {
            foreach (['oauth_client_secret', 'oauth_access_token'] as $secret) {
                if (blank($validated[$secret] ?? null) && $existing?->{$secret}) {
                    unset($validated[$secret]);
                }
            }
            $validated['store_domain'] = $this->normalizeStoreDomain((string) ($validated['store_domain'] ?? ''));
        }

        $settings = ShopCarrierDeliverySettings::updateOrCreate(
            ['carrier' => $carrier],
            array_merge($validated, ['carrier' => $carrier])
        );
        if ($request->has('is_active')) {
            app(ShopDeliveryActivitySyncService::class)->applySettingsActiveToMethod($carrier, $request->boolean('is_active'));
        }
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive($carrier, $settings);

        return response()->json([
            'success' => true,
            'message' => 'Настройки доставки сохранены',
            'data' => $this->settingsPayload($settings),
        ]);
    }

    public function validateCredentials(Request $request, string $carrier): JsonResponse
    {
        if ($access = $this->checkAccess($request)) {
            return $access;
        }

        if (! in_array($carrier, $this->allowedCarriers, true)) {
            return response()->json(['success' => false, 'message' => 'Неизвестная служба доставки'], 404);
        }

        if ($carrier === 'yandex') {
            return $this->validateYandexCredentials($request);
        }

        if ($carrier === 'ozon') {
            return $this->validateOzonCredentials($request);
        }

        $apiUrl = trim((string) $request->get('api_url', ''));
        $apiToken = trim((string) $request->get('api_token', ''));

        if ($apiUrl === '' || $apiToken === '') {
        return response()->json([
                'success' => false,
                'message' => 'Укажите API URL и токен. Для полноценной проверки нужен рабочий URL метода из договора/ЛК службы доставки.',
                'data' => ['valid' => false],
            ]);
        }

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(20)
                ->get($apiUrl);

            return response()->json([
                'success' => $response->successful(),
                'message' => $response->successful() ? 'Доступ к API подтвержден' : ($response->json('message') ?? $response->body() ?: 'API вернул ошибку'),
                'data' => [
                    'valid' => $response->successful(),
                    'status' => $response->status(),
                ],
            ], $response->successful() ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['valid' => false],
            ], 422);
        }
    }

    public function refreshOzonToken(Request $request, string $carrier): JsonResponse
    {
        if ($access = $this->checkAccess($request)) {
            return $access;
        }
        if ($carrier !== 'ozon') {
            return response()->json(['success' => false, 'message' => 'OAuth-токен поддерживается только для Ozon Доставки'], 404);
        }

        $settings = ShopCarrierDeliverySettings::firstOrCreate(['carrier' => 'ozon'], ['is_active' => false]);
        $clientId = trim((string) $request->input('oauth_client_id', $settings->oauth_client_id));
        $clientSecret = trim((string) $request->input('oauth_client_secret', $settings->oauth_client_secret));
        if ($clientId === '' || $clientSecret === '') {
            return response()->json(['success' => false, 'message' => 'Сначала укажите OAuth Client ID и Client Secret Ozon и сохраните настройки.'], 422);
        }

        try {
            $tokenResult = $this->requestOzonAccessToken($clientId, $clientSecret);
            if (! $tokenResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $tokenResult['message'],
                    'data' => ['status' => $tokenResult['status']],
                ], 422);
            }

            $settings->oauth_client_id = $clientId;
            $settings->oauth_client_secret = $clientSecret;
            $settings->oauth_access_token = $tokenResult['token'];
            $settings->oauth_expires_at = $tokenResult['expires_at'];
            $settings->save();

            return response()->json([
                'success' => true,
                'message' => 'OAuth-токен Ozon получен и сохранен на сервере.',
                'data' => [
                    'has_oauth_access_token' => true,
                    'oauth_expires_at' => $settings->oauth_expires_at,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Не удалось подключиться к OAuth API Ozon. Проверьте доступ сервера к xapi.ozon.ru.'], 422);
        }
    }

    private function validateOzonCredentials(Request $request): JsonResponse
    {
        $settings = ShopCarrierDeliverySettings::where('carrier', 'ozon')->first();
        $clientId = trim((string) $request->input('oauth_client_id', $settings?->oauth_client_id));
        $clientSecret = trim((string) $request->input('oauth_client_secret', '')) ?: (string) ($settings?->oauth_client_secret ?? '');
        if (! $settings || $clientId === '' || $clientSecret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Укажите OAuth Client ID и Client Secret Ozon Доставки. Токен сайт получит автоматически.',
                'data' => ['valid' => false],
            ], 422);
        }

        $tokenResult = $this->requestOzonAccessToken($clientId, $clientSecret);
        if (! $tokenResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $tokenResult['message'],
                'data' => ['valid' => false, 'status' => $tokenResult['status'], 'response' => $tokenResult['response']],
            ], 422);
        }
        $token = $tokenResult['token'];
        $settings->oauth_client_id = $clientId;
        $settings->oauth_client_secret = $clientSecret;
        $settings->oauth_access_token = $token;
        $settings->oauth_expires_at = $tokenResult['expires_at'];
        $settings->save();

        try {
            $url = 'https://api-delivery.ozon.ru/v1/delivery-point/list';
            $response = app(OzonDeliveryService::class)->requestWithToken(
                $token,
                '/v1/delivery-point/list',
                ['pagination' => ['cursor' => null, 'limit' => 1]]
            );
            $data = $response->json();
            $enabled = $response->successful() && is_array(data_get($data, 'delivery_points'));
            $suggestions = [];
            if ($response->status() === 401) {
                $message = 'OAuth-токен получен, но Ozon Delivery API его не принял (401). Проверьте настройки приватного приложения и доступ к Ozon Доставке.';
                $suggestions = [
                    'Client ID и Client Secret должны быть созданы именно в приложении Ozon Доставки, а не взяты из Seller API или другого приложения.',
                    'В приложении должен быть включен доступ delivery-api.all (либо полный набор прав Ozon Delivery API), после изменения прав выпустите токен заново кнопкой «Получить OAuth-токен».',
                    'Проверьте, что подключение Ozon Доставки для бизнеса активно для кабинета и по нему заключен/активирован договор. Seller API Api-Key сюда не подходит.',
                ];
            } elseif ($response->successful()) {
                $message = $enabled
                    ? 'OAuth-токен принят, API Ozon Доставки доступен.'
                    : 'Ozon ответил, но формат списка пунктов выдачи неожиданный.';
            } else {
                $message = data_get($data, 'message') ?? data_get($data, 'error.message') ?? $response->body();
            }

            return response()->json([
                'success' => $response->successful() && $enabled,
                'message' => $message,
                'data' => [
                    'valid' => $response->successful() && $enabled,
                    'status' => $response->status(),
                    'url' => $url,
                    'api_accessible' => $enabled,
                    'oauth_token_obtained' => true,
                    'oauth_scope_requested' => 'delivery-api.all',
                    'suggestions' => $suggestions,
                    'response' => $response->successful()
                        ? ['delivery_points_count' => count((array) data_get($data, 'delivery_points', [])), 'next_cursor' => data_get($data, 'next_cursor')]
                        : $data,
                ],
            ], $response->successful() && $enabled ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки Ozon Delivery: '.$e->getMessage(),
                'data' => ['valid' => false],
            ], 422);
        }
    }

    private function requestOzonAccessToken(string $clientId, string $clientSecret): array
    {
        $response = Http::acceptJson()->asJson()->timeout(20)->post('https://xapi.ozon.ru/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => ['delivery-api.all'],
        ]);
        $body = $response->json();
        $token = trim((string) data_get($body, 'access_token', ''));
        if (! $response->successful() || $token === '') {
            $message = data_get($body, 'message') ?? data_get($body, 'error_description') ?? data_get($body, 'error') ?? 'Ozon не вернул access_token';
            if (is_array($body)) {
                unset($body['access_token'], $body['client_secret']);
            }
            return ['success' => false, 'status' => $response->status(), 'message' => 'Не удалось получить OAuth-токен Ozon: '.$message, 'response' => $body];
        }
        $expires = data_get($body, 'expires_in');
        $expiresAt = is_numeric($expires) ? now()->setTimestamp((int) $expires) : null;

        return ['success' => true, 'token' => $token, 'expires_at' => $expiresAt, 'status' => $response->status()];
    }

    private function settingsPayload(ShopCarrierDeliverySettings $settings): array
    {
        $payload = $settings->toArray();
        unset($payload['oauth_client_secret'], $payload['oauth_access_token']);

        return array_merge($payload, [
            'has_oauth_client_secret' => filled($settings->oauth_client_secret),
            'has_oauth_access_token' => filled($settings->oauth_access_token),
        ]);
    }

    private function normalizeStoreDomain(string $domain): ?string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^https?://#i', '', $domain);
        return rtrim((string) $domain, '/');
    }

    private function validateYandexCredentials(Request $request): JsonResponse
    {
        $settingsData = $request->get('settings', []);
        $settingsData = is_array($settingsData) ? $settingsData : [];
        $apiMode = ($settingsData['api_mode'] ?? 'other_day') === 'express' ? 'express' : 'other_day';
        $apiBaseUrl = $apiMode === 'express'
            ? $this->normalizeYandexExpressApiBaseUrl((string) $request->get('api_url', ''))
            : $this->normalizeYandexApiBaseUrl((string) $request->get('api_url', ''));
        $apiToken = trim((string) $request->get('api_token', ''));

        if ($apiToken === '') {
            return response()->json([
                'success' => false,
                'message' => 'Укажите API токен Яндекс Доставки.',
                'data' => ['valid' => false],
            ], 422);
        }

        if ($apiMode === 'express') {
            return $this->validateYandexExpressCredentials($apiBaseUrl, $apiToken, $request);
        }

        try {
            $merchantQuery = $this->yandexMerchantQueryFromRequest($request);
            $url = $apiBaseUrl.'/merchant/info';
            $checkedUrl = $this->appendQueryToUrl($url, $merchantQuery);
            $detectUrl = $this->appendQueryToUrl($apiBaseUrl.'/location/detect', $merchantQuery);
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(20)
                ->get($url, $merchantQuery);

            if ($response->successful()) {
                $detectResponse = Http::withToken($apiToken)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(20)
                    ->post($detectUrl, ['location' => 'Москва']);

                if (! $detectResponse->successful()) {
                    $detectMessage = $detectResponse->json('message')
                        ?? $detectResponse->json('error')
                        ?? $detectResponse->body()
                        ?: 'API Яндекс Доставки не разрешил метод location/detect';

                    return response()->json([
                        'success' => false,
                    'message' => 'Доступ к merchant/info есть, но методы доставки недоступны: '.$detectMessage,
                    'data' => [
                        'valid' => false,
                        'mode' => 'other_day',
                        'checked_method' => 'POST /location/detect',
                        'merchant_status' => $response->status(),
                        'delivery_method_status' => $detectResponse->status(),
                        'merchant_url' => $checkedUrl,
                        'delivery_method_url' => $detectUrl,
                        'response' => $detectResponse->json(),
                        'merchant_id' => $merchantQuery['merchant_id'] ?? null,
                        'suggestions' => $this->yandexOtherDayAccessSuggestions('location/detect', $detectResponse->status(), (string) $detectMessage),
                    ],
                ], 422);
            }
            }

            $message = $response->successful()
                ? 'Доступ к API Яндекс Доставки подтвержден, метод location/detect доступен'
                : ($response->json('message') ?? $response->json('error') ?? $response->body() ?: 'API Яндекс Доставки вернул ошибку');

            return response()->json([
                'success' => $response->successful(),
                'message' => $message,
                'data' => [
                    'valid' => $response->successful(),
                    'mode' => 'other_day',
                    'checked_method' => $response->successful() ? 'GET /merchant/info + POST /location/detect' : 'GET /merchant/info',
                    'status' => $response->status(),
                    'url' => $checkedUrl,
                    'response' => $response->json(),
                    'merchant_id' => $merchantQuery['merchant_id'] ?? null,
                    'suggestions' => $response->successful() ? [] : $this->yandexOtherDayAccessSuggestions('merchant/info', $response->status(), (string) $message),
                ],
            ], $response->successful() ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки API Яндекс Доставки: '.$e->getMessage(),
                'data' => [
                    'valid' => false,
                    'mode' => 'other_day',
                    'suggestions' => $this->yandexOtherDayAccessSuggestions('connection', 0, $e->getMessage()),
                ],
            ], 422);
        }
    }

    private function yandexMerchantQueryFromRequest(Request $request): array
    {
        $settingsData = $request->get('settings', []);
        $settingsData = is_array($settingsData) ? $settingsData : [];
        $merchantId = trim((string) (
            $request->get('merchant_id')
            ?: ($settingsData['merchant_id'] ?? '')
            ?: ($settingsData['merchantId'] ?? '')
            ?: ($settingsData['cabinet_id'] ?? '')
            ?: ($settingsData['client_id'] ?? '')
            ?: $request->get('client_id')
        ));

        return $merchantId !== '' ? ['merchant_id' => $merchantId] : [];
    }

    private function appendQueryToUrl(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
    private function yandexOtherDayAccessSuggestions(string $method, int $status, string $message): array
    {
        $suggestions = [
            'Для режима "Доставка по России" в личном кабинете Яндекс Доставки должен быть подключен API Доставки по России.',
            'Токен должен быть выпущен для того же Merchant ID / Cabinet ID, который указан в настройках.',
            'В настройках сайта должен быть указан ID склада / platform_station_id, созданный в кабинете Яндекса.',
            'Базовый URL должен быть https://b2b-authproxy.taxi.yandex.net/api/b2b/platform или поле можно оставить пустым.',
        ];

        $lowerMessage = mb_strtolower($message);
        if (in_array($status, [401, 403], true) || str_contains($lowerMessage, 'access denied') || str_contains($lowerMessage, 'not authorized')) {
            array_unshift($suggestions, 'Яндекс отклонил авторизацию для метода '.$method.'. Проверьте права токена на этот метод или запросите у поддержки Яндекса включение доступа.');
        }

        if ($method === 'location/detect') {
            $suggestions[] = 'Метод location/detect нужен для определения geo_id города перед загрузкой ПВЗ.';
        }

        if ($method === 'merchant/info') {
            $suggestions[] = 'Если merchant/info недоступен, проблема в токене, кабинете или базовом URL, расчет доставки дальше работать не сможет.';
        }

        if (str_contains($lowerMessage, 'unknown merchant_id')) {
            array_unshift($suggestions, 'Яндекс получил merchant_id, но не нашел такой кабинет. Проверьте, что в поле Merchant ID / Cabinet ID указан именно ID кабинета из Яндекс Доставки, а токен выпущен для этого же кабинета.');
        }

        if (str_contains($lowerMessage, 'missing merchant_id')) {
            array_unshift($suggestions, 'Яндекс требует merchant_id в query. Укажите Merchant ID / Cabinet ID в настройках Яндекс Доставки.');
        }

        return $suggestions;
    }

    private function normalizeSettingsPayload(mixed $settings, string $carrier): array
    {
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        $settings = is_array($settings) ? $settings : [];

        if ($carrier === 'yandex') {
            $apiMode = ($settings['api_mode'] ?? 'other_day') === 'express' ? 'express' : 'other_day';
            $settings['api_mode'] = $apiMode;
            $settings['disable_order_creation'] = filter_var($settings['disable_order_creation'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $settings['express_taxi_class'] = trim((string) ($settings['express_taxi_class'] ?? 'express')) ?: 'express';
            $settings['express_pro_courier'] = filter_var($settings['express_pro_courier'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (array_key_exists('sender_phone', $settings)) {
                $settings['sender_phone'] = trim((string) $settings['sender_phone']);
            }
            $settings['express_delivery_city'] = trim((string) ($settings['express_delivery_city'] ?? ''));
        }

        return $settings;
    }

    private function validateYandexExpressCredentials(string $apiBaseUrl, string $apiToken, Request $request): JsonResponse
    {
        try {
            $sourceCity = trim((string) $request->get('sender_city', 'Москва'));
            $sourceStreet = trim((string) $request->get('sender_street', ''));
            $sourceHouse = trim((string) $request->get('sender_house', ''));
            $sourceAddress = trim(implode(', ', array_filter([
                $sourceCity,
                $sourceStreet,
                $sourceHouse !== '' ? 'д. '.$sourceHouse : '',
            ]))) ?: 'Москва';
            $settingsData = $request->get('settings', []);
            $settingsData = is_array($settingsData) ? $settingsData : [];
            $taxiClass = $settingsData['express_taxi_class'] ?? 'express';

            $url = $apiBaseUrl.'/offers/calculate';
            $payload = [
                'items' => [[
                    'size' => [
                        'length' => 0.1,
                        'width' => 0.1,
                        'height' => 0.1,
                    ],
                    'weight' => 0.1,
                    'quantity' => 1,
                    'pickup_point' => 1,
                    'dropoff_point' => 2,
                    'age_restricted' => false,
                ]],
                'route_points' => [
                    [
                        'id' => 1,
                        'fullname' => $sourceAddress,
                        'country' => 'Россия',
                        'city' => $sourceCity,
                    ],
                    [
                        'id' => 2,
                        'fullname' => $sourceAddress,
                        'country' => 'Россия',
                        'city' => $sourceCity,
                    ],
                ],
                'requirements' => [
                    'taxi_classes' => [$taxiClass],
                    'skip_door_to_door' => false,
                    'pro_courier' => (bool) ($settingsData['express_pro_courier'] ?? false),
                ],
            ];

            $response = Http::withToken($apiToken)
                ->withHeaders(['Accept-Language' => 'ru'])
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($url, $payload);

            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();
            $isAuthError = in_array($response->status(), [401, 403], true)
                || stripos((string) $message, 'not authorized') !== false
                || stripos((string) $message, 'unauthorized') !== false;
            $isAccessDenied = stripos((string) $message, 'access denied') !== false;
            $valid = $response->successful() || (in_array($response->status(), [400, 404, 422], true) && ! $isAuthError && ! $isAccessDenied);
            $successMessage = $response->successful()
                ? 'Доступ к API Яндекс Экспресс подтвержден, тестовый расчет доступен'
                : 'Доступ к API Яндекс Экспресс подтвержден, но тестовый расчет вернул проверяемую ошибку: '.$message;
            $errorMessage = $isAccessDenied
                ? 'Нет доступа к Express/Cargo API Яндекс Доставки: метод offers/calculate вернул отказ. Нужно подключить Cargo/Express API для этого кабинета/токена у Яндекса.'
                : ($message ?: 'API Яндекс Экспресс вернул ошибку авторизации');

            return response()->json([
                'success' => $valid,
                'message' => $valid
                    ? $successMessage
                    : $errorMessage,
                'data' => [
                    'valid' => $valid,
                    'mode' => 'express',
                    'checked_method' => 'POST /offers/calculate',
                    'status' => $response->status(),
                    'url' => $url,
                    'request_payload' => $payload,
                    'response' => $body,
                ],
            ], $valid ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки API Яндекс Экспресс: '.$e->getMessage(),
                'data' => ['valid' => false],
            ], 422);
        }
    }

    private function normalizeYandexApiBaseUrl(string $apiUrl): string
    {
        $default = 'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform';
        $apiUrl = trim($apiUrl);

        if ($apiUrl === '') {
            return $default;
        }

        $apiUrl = rtrim($apiUrl, '/');

        foreach ([
            '/merchant/info',
            '/pricing-calculator',
            '/location/detect',
            '/pickup-points/list',
            '/offers/create',
            '/offers/confirm',
            '/request/create',
            '/warehouses/list',
        ] as $methodPath) {
            if (str_ends_with($apiUrl, $methodPath)) {
                return substr($apiUrl, 0, -strlen($methodPath));
            }
        }

        return $apiUrl;
    }

    private function normalizeYandexExpressApiBaseUrl(string $apiUrl): string
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

    private function checkAccess(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Необходима авторизация.'], 401);
        }

        if (! $user->hasRole('admin') && ! $user->hasRole('shop')) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещен.'], 403);
        }

        return null;
    }
}
