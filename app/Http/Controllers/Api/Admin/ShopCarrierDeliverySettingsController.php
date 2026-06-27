<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopCarrierDeliverySettings;
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

        return response()->json([
            'success' => true,
            'data' => ShopCarrierDeliverySettings::firstOrCreate(['carrier' => $carrier], ['is_active' => false]),
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
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Ошибка валидации', 'errors' => $validator->errors()], 422);
        }

        $settings = ShopCarrierDeliverySettings::updateOrCreate(
            ['carrier' => $carrier],
            array_merge($validator->validated(), ['carrier' => $carrier])
        );

        return response()->json([
            'success' => true,
            'message' => 'Настройки доставки сохранены',
            'data' => $settings,
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

    private function validateYandexCredentials(Request $request): JsonResponse
    {
        $apiBaseUrl = $this->normalizeYandexApiBaseUrl((string) $request->get('api_url', ''));
        $apiToken = trim((string) $request->get('api_token', ''));

        if ($apiToken === '') {
            return response()->json([
                'success' => false,
                'message' => 'Укажите API токен Яндекс Доставки.',
                'data' => ['valid' => false],
            ], 422);
        }

        try {
            $url = $apiBaseUrl.'/merchant/info';
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(20)
                ->get($url);

            $message = $response->successful()
                ? 'Доступ к API Яндекс Доставки подтвержден'
                : ($response->json('message') ?? $response->json('error') ?? $response->body() ?: 'API Яндекс Доставки вернул ошибку');

            return response()->json([
                'success' => $response->successful(),
                'message' => $message,
                'data' => [
                    'valid' => $response->successful(),
                    'status' => $response->status(),
                    'url' => $url,
                    'response' => $response->json(),
                ],
            ], $response->successful() ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки API Яндекс Доставки: '.$e->getMessage(),
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
