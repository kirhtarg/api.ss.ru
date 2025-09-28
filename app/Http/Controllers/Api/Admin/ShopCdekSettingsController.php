<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopCdekSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class ShopCdekSettingsController extends Controller
{
    /**
     * Проверка доступа к контроллеру
     */
    private function checkAccess($request)
    {
        $user = $request->user();
        
        // Проверяем авторизацию
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация.'
            ], 401);
        }
        
        // Проверяем, что пользователь имеет роль admin или shop
        if (!$user->hasRole('admin') && !$user->hasRole('shop')) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен. Требуется роль администратора или менеджера магазина.'
            ], 403);
        }
        
        return null;
    }

    /**
     * Получить настройки СДЭК (только одну запись)
     */
    public function index(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopCdekSettings::first();
        
        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Получить активные настройки СДЭК
     */
    public function getActive(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopCdekSettings::getActive();
        
        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Создать или обновить настройки СДЭК
     */
    public function store(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string|max:255',
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
            'sender_country_code' => 'nullable|string|max:10',
            'default_weight' => 'nullable|numeric|min:0.01|max:1000',
            'default_length' => 'nullable|numeric|min:1|max:1000',
            'default_width' => 'nullable|numeric|min:1|max:1000',
            'default_height' => 'nullable|numeric|min:1|max:1000',
            'tariffs' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Проверяем валидность ключей СДЭК
        $keyValidation = $this->validateCdekKeys($request->client_id, $request->client_secret);
        if (!$keyValidation['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации ключей СДЭК: ' . $keyValidation['error']
            ], 422);
        }

        // Ищем существующую запись или создаем новую
        $settings = ShopCdekSettings::first();
        if ($settings) {
            $settings->update($request->all());
        } else {
            $settings = ShopCdekSettings::create($request->all());
        }

        return response()->json([
            'success' => true,
            'message' => 'Настройки СДЭК сохранены успешно',
            'data' => $settings
        ]);
    }

    /**
     * Обновить настройки СДЭК
     */
    public function update(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopCdekSettings::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'client_id' => 'sometimes|string|max:255',
            'client_secret' => 'sometimes|string|max:255',
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
            'sender_country_code' => 'nullable|string|max:10',
            'default_weight' => 'nullable|numeric|min:0.01|max:1000',
            'default_length' => 'nullable|numeric|min:1|max:1000',
            'default_width' => 'nullable|numeric|min:1|max:1000',
            'default_height' => 'nullable|numeric|min:1|max:1000',
            'tariffs' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Если обновляются ключи, проверяем их валидность
        if ($request->has('client_id') || $request->has('client_secret')) {
            $clientId = $request->client_id ?? $settings->client_id;
            $clientSecret = $request->client_secret ?? $settings->client_secret;
            
            $keyValidation = $this->validateCdekKeys($clientId, $clientSecret);
            if (!$keyValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации ключей СДЭК: ' . $keyValidation['error']
                ], 422);
            }
        }

        $settings->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Настройки СДЭК обновлены успешно',
            'data' => $settings
        ]);
    }

    /**
     * Удалить настройки СДЭК
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopCdekSettings::findOrFail($id);
        $settings->delete();

        return response()->json([
            'success' => true,
            'message' => 'Настройки СДЭК удалены успешно'
        ]);
    }

    /**
     * Активировать настройки СДЭК
     */
    public function activate(Request $request, $id): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $settings = ShopCdekSettings::findOrFail($id);
        
        // Деактивируем все остальные
        ShopCdekSettings::where('id', '!=', $id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $settings->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Настройки СДЭК активированы успешно',
            'data' => $settings
        ]);
    }

    /**
     * Проверить валидность ключей СДЭК
     */
    public function validateKeys(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $validation = $this->validateCdekKeys($request->client_id, $request->client_secret);

        return response()->json([
            'success' => $validation['valid'],
            'message' => $validation['valid'] ? 'Ключи СДЭК валидны' : $validation['error'],
            'data' => $validation
        ]);
    }

    /**
     * Получить доступные тарифы СДЭК
     */
    public function getAvailableTariffs(Request $request): JsonResponse
    {
        $accessCheck = $this->checkAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        // Получаем ключи из запроса, если они переданы
        $clientId = $request->query('client_id');
        $clientSecret = $request->query('client_secret');
        
        // Если ключи не переданы, пытаемся получить из настроек
        if (!$clientId || !$clientSecret) {
            $settings = ShopCdekSettings::first();
            if (!$settings || !$settings->client_id || !$settings->client_secret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены или не заполнены ключи. Передайте client_id и client_secret в параметрах запроса.'
                ], 400);
            }
            $clientId = $settings->client_id;
            $clientSecret = $settings->client_secret;
        }

        try {
            \Log::info('CDEK Tariffs: Starting request with client_id: ' . $clientId);
            
            $token = $this->getCdekToken($clientId, $clientSecret);
            \Log::info('CDEK Tariffs: Token received successfully');
            
            // Получаем тарифы с помощью тестовых данных
            $requestData = [
                'type' => 1,
                'currency' => 1,
                'from_location' => [
                    'address' => 'Москва'
                ],
                'to_location' => [
                    'address' => 'Санкт-Петербург'
                ],
                'packages' => [
                    [
                        'weight' => 1000, // 1 кг в граммах
                        'length' => 10,
                        'width' => 10,
                        'height' => 10,
                    ]
                ]
            ];
            
            \Log::info('CDEK Tariffs: Request data: ' . json_encode($requestData));
            
            $response = Http::withOptions([
                'verify' => config('cdek.ssl_verify', false),
                'timeout' => config('cdek.timeout', 30),
            ])->withToken($token)->post(config('cdek.api_url', 'https://api.cdek.ru/v2') . '/calculator/tarifflist', $requestData);

            \Log::info('CDEK Tariffs: Response status: ' . $response->status());
            
            if ($response->successful()) {
                $data = $response->json();
                $tariffs = $data['tariff_codes'] ?? [];
                \Log::info('CDEK Tariffs: Received ' . count($tariffs) . ' tariffs');
                
                return response()->json([
                    'success' => true,
                    'data' => $tariffs
                ]);
            } else {
                $errorBody = $response->body();
                \Log::error('CDEK API Error: Status ' . $response->status() . ', Body: ' . $errorBody);
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения тарифов от СДЭК (статус ' . $response->status() . '): ' . $errorBody
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('CDEK Tariffs Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения тарифов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить валидность ключей СДЭК
     */
    private function validateCdekKeys($clientId, $clientSecret)
    {
        try {
            $token = $this->getCdekToken($clientId, $clientSecret);
            return ['valid' => true, 'token' => $token];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получить токен СДЭК
     */
    private function getCdekToken($clientId, $clientSecret)
    {
        $response = Http::withOptions([
            'verify' => config('cdek.ssl_verify', false),
            'timeout' => config('cdek.timeout', 30),
        ])->asForm()->post(config('cdek.api_url', 'https://api.cdek.ru/v2') . '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ]);

        if (!$response->successful()) {
            $data = $response->json();
            throw new \Exception($data['error_description'] ?? $data['error'] ?? 'Ошибка авторизации в СДЭК');
        }

        $data = $response->json();
        if (!isset($data['access_token'])) {
            throw new \Exception('Не получен access_token от СДЭК');
        }

        return $data['access_token'];
    }
}