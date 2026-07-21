<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopDellinSettings;
use App\Services\ShopDeliveryActivitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ShopDellinSettingsController extends Controller
{
    /**
     * Проверка доступа к контроллеру
     */
    private function checkAccess($request)
    {
        $user = $request->user();

        // Проверяем авторизацию
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация.',
            ], 401);
        }

        // Проверяем, что пользователь имеет роль admin или shop
        if (! $user->hasRole('admin') && ! $user->hasRole('shop')) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен. Требуется роль администратора или менеджера магазина.',
            ], 403);
        }

        return null;
    }

    /**
     * Получить настройки Деловых линий (только одну запись)
     */
    public function index(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopDellinSettings::first();
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive('dellin', $settings);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /**
     * Получить активные настройки Деловых линий
     */
    public function getActive(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = app(ShopDeliveryActivitySyncService::class)->getMethodActive('dellin') === false
            ? null
            : ShopDellinSettings::getActive();

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /**
     * Identifies the active configured Dellin account without exposing its API
     * credentials. Used in the order-creation confirmation dialog.
     */
    public function accountSummary(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopDellinSettings::getActive();
        if (! $settings) {
            return response()->json([
                'success' => false,
                'message' => 'Активные настройки Деловых линий не найдены.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'settings_id' => $settings->id,
                'login' => $settings->login,
                'company' => $settings->sender_company,
                'full_name' => $settings->sender_name,
                'phone' => $settings->sender_phone,
                'auth_type' => $settings->auth_type,
            ],
        ]);
    }

    /**
     * Создать или обновить настройки Деловых линий
     */
    public function store(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $validator = Validator::make($request->all(), [
            'appkey' => 'required|string|max:255',
            'auth_type' => 'nullable|string|in:appkey,pat,login_password',
            'pat' => 'nullable|string|max:255',
            'login' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'sender_company' => 'nullable|string|max:255',
            'sender_name' => 'nullable|string|max:255',
            'sender_phone' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'sender_inn' => 'nullable|string|max:255',
            'sender_kpp' => 'nullable|string|max:255',
            'sender_city' => 'nullable|string|max:255',
            'sender_street' => 'nullable|string|max:255',
            'sender_house' => 'nullable|string|max:255',
            'sender_flat' => 'nullable|string|max:255',
            'sender_postal_code' => 'nullable|string|max:255',
            'default_weight' => 'nullable|numeric|min:0.01|max:1000',
            'default_length' => 'nullable|numeric|min:1|max:1000',
            'default_width' => 'nullable|numeric|min:1|max:1000',
            'default_height' => 'nullable|numeric|min:1|max:1000',
            'cash_on_delivery_enabled' => 'boolean',
            'create_order_in_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Ищем существующую запись или создаем новую
        $settings = ShopDellinSettings::first();
        if ($settings) {
            $settings->update($request->all());
        } else {
            $settings = ShopDellinSettings::create($request->all());
        }
        if ($request->has('is_active')) {
            app(ShopDeliveryActivitySyncService::class)->applySettingsActiveToMethod('dellin', $request->boolean('is_active'));
        }
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive('dellin', $settings);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Деловых линий сохранены успешно',
            'data' => $settings,
        ]);
    }

    /**
     * Обновить настройки Деловых линий
     */
    public function update(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopDellinSettings::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'appkey' => 'sometimes|string|max:255',
            'auth_type' => 'nullable|string|in:appkey,pat,login_password',
            'pat' => 'nullable|string|max:255',
            'login' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'sender_company' => 'nullable|string|max:255',
            'sender_name' => 'nullable|string|max:255',
            'sender_phone' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'sender_inn' => 'nullable|string|max:255',
            'sender_kpp' => 'nullable|string|max:255',
            'sender_city' => 'nullable|string|max:255',
            'sender_street' => 'nullable|string|max:255',
            'sender_house' => 'nullable|string|max:255',
            'sender_flat' => 'nullable|string|max:255',
            'sender_postal_code' => 'nullable|string|max:255',
            'default_weight' => 'nullable|numeric|min:0.01|max:1000',
            'default_length' => 'nullable|numeric|min:1|max:1000',
            'default_width' => 'nullable|numeric|min:1|max:1000',
            'default_height' => 'nullable|numeric|min:1|max:1000',
            'cash_on_delivery_enabled' => 'boolean',
            'create_order_in_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $settings->update($request->all());
        if ($request->has('is_active')) {
            app(ShopDeliveryActivitySyncService::class)->applySettingsActiveToMethod('dellin', $request->boolean('is_active'));
        }
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive('dellin', $settings);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Деловых линий обновлены успешно',
            'data' => $settings,
        ]);
    }

    /**
     * Удалить настройки Деловых линий
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopDellinSettings::findOrFail($id);
        $settings->delete();

        return response()->json([
            'success' => true,
            'message' => 'Настройки Деловых линий удалены успешно',
        ]);
    }

    /**
     * Активировать настройки Деловых линий
     */
    public function activate(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopDellinSettings::findOrFail($id);

        // Деактивируем все остальные
        ShopDellinSettings::where('id', '!=', $id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $settings->update(['is_active' => true]);
        app(ShopDeliveryActivitySyncService::class)->applySettingsActiveToMethod('dellin', true);
        app(ShopDeliveryActivitySyncService::class)->mergeMethodActive('dellin', $settings);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Деловых линий активированы успешно',
            'data' => $settings,
        ]);
    }

    /**
     * Проверить валидность API ключа Деловых линий
     */
    public function validateKey(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $validator = Validator::make($request->all(), [
            'appkey' => 'required|string',
            'auth_type' => 'nullable|string|in:appkey,pat,login_password',
            'pat' => 'nullable|string',
            'login' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validation = $this->validateDellinKey(
            $request->appkey,
            $request->get('auth_type', 'appkey'),
            $request->get('pat'),
            $request->get('login'),
            $request->get('password')
        );

        return response()->json([
            'success' => $validation['valid'],
            'message' => $validation['valid'] ? 'API ключ Деловых линий валиден' : $validation['error'],
            'data' => [
                'valid' => $validation['valid'],
                'error' => $validation['error'] ?? null,
                'session_id' => $validation['session_id'] ?? null,
                'session_expires_at' => ! empty($validation['session_id']) ? now()->addDays(30)->toDateTimeString() : null,
                'raw' => $validation['raw'] ?? null,
            ],
        ]);
    }

    /**
     * Проверить валидность API ключа Деловых линий
     */
    private function validateDellinKey($appkey, string $authType = 'appkey', ?string $pat = null, ?string $login = null, ?string $password = null)
    {
        try {
            $sessionId = null;

            if ($authType === 'pat' && $pat) {
                $authResponse = Http::withOptions([
                    'verify' => $this->getDellinVerifyOption(),
                    'timeout' => 30,
                ])->post('https://api.dellin.ru/v4/auth/login.json', [
                    'appkey' => $appkey,
                    'pat' => $pat,
                ]);

                $authData = $authResponse->json();
                $sessionId = $authData['data']['sessionID'] ?? null;
                if (! $authResponse->successful() || ! $sessionId) {
                    return [
                        'valid' => false,
                        'error' => $this->extractDellinError($authData, 'Не удалось авторизоваться по PAT-токену'),
                        'raw' => $authData,
                    ];
                }
            }

            if ($authType === 'login_password' && $login && $password) {
                $authResponse = Http::withOptions([
                    'verify' => $this->getDellinVerifyOption(),
                    'timeout' => 30,
                ])->post('https://api.dellin.ru/v3/auth/login.json', [
                    'appkey' => $appkey,
                    'login' => $login,
                    'password' => $password,
                ]);

                $authData = $authResponse->json();
                $sessionId = $authData['data']['sessionID'] ?? null;
                if (! $authResponse->successful() || ! $sessionId) {
                    return [
                        'valid' => false,
                        'error' => $this->extractDellinError($authData, 'Не удалось авторизоваться по логину и паролю'),
                        'raw' => $authData,
                    ];
                }
            }

            $payload = [
                'appkey' => $appkey,
                'delivery' => [
                    'deliveryType' => ['type' => 'auto'],
                    'derival' => [
                        'produceDate' => $this->getNextDellinProduceDate(),
                        'variant' => 'terminal',
                        'terminalID' => '314',
                    ],
                    'arrival' => [
                        'variant' => 'terminal',
                        'terminalID' => '264',
                    ],
                ],
                'cargo' => [
                    'quantity' => 1,
                    'length' => 0.6,
                    'width' => 0.3,
                    'height' => 0.39,
                    'weight' => 8.5,
                    'totalVolume' => 0.1,
                    'totalWeight' => 8.5,
                    'hazardClass' => 0,
                    'insurance' => [
                        'statedValue' => 5000,
                        'term' => true,
                    ],
                ],
            ];

            if ($sessionId) {
                $payload['sessionID'] = $sessionId;
            }

            $response = Http::withOptions([
                'verify' => $this->getDellinVerifyOption(),
                'timeout' => 30,
            ])->post('https://api.dellin.ru/v2/calculator.json', $payload);

            if ($response->successful()) {
                $data = $response->json();
                $metadataStatus = (int) ($data['metadata']['status'] ?? 0);
                if ($metadataStatus === 200 || isset($data['data'])) {
                    return [
                        'valid' => true,
                        'session_id' => $sessionId,
                        'raw' => $data,
                    ];
                }

                return [
                    'valid' => false,
                    'error' => $this->extractDellinError($data, 'Ошибка проверки ключа'),
                    'raw' => $data,
                ];
            }

            // Если ошибка авторизации, ключ невалиден
            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'valid' => false,
                    'error' => 'Неверный API ключ',
                ];
            }

            // Другие ошибки
            $errorData = $response->json();
            $errorMessage = $this->extractDellinError($errorData, 'Ошибка проверки ключа');

            return [
                'valid' => false,
                'error' => $errorMessage,
                'raw' => $errorData,
            ];
        } catch (\Exception $e) {
            // Если API недоступен или произошла ошибка, проверяем формат ключа
            // API ключ Деловых линий обычно имеет определенный формат
            if (empty($appkey) || strlen($appkey) < 10) {
                return [
                    'valid' => false,
                    'error' => 'API ключ слишком короткий',
                ];
            }

            return [
                'valid' => false,
                'error' => 'Не удалось проверить ключ через API Деловых линий: '.$e->getMessage(),
            ];
        }
    }

    private function extractDellinError($data, string $fallback): string
    {
        if (! is_array($data)) {
            return $fallback;
        }

        return $data['errors'][0]['detail']
            ?? $data['errors'][0]['message']
            ?? $data['error']['message']
            ?? $data['message']
            ?? $fallback;
    }

    private function getDellinVerifyOption()
    {
        $caBundle = config('services.dellin.ca_bundle_path');
        if ($caBundle && file_exists($caBundle)) {
            return $caBundle;
        }

        return filter_var(config('services.dellin.verify_ssl', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function getNextDellinProduceDate(): string
    {
        $date = now()->addDays(3);

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->format('Y-m-d');
    }
}
