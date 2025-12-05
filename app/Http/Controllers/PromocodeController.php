<?php

namespace App\Http\Controllers;

use App\Models\Promocode;
use App\Models\PromocodeUsage;
use App\Models\AbsentPromocodeUsage;
use App\Models\Setting;
use App\Models\ShopGood;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PromocodeController extends Controller
{
    /**
     * Получить пользователя из токена Authorization
     */
    private function getUserFromToken(Request $request): ?\App\Models\User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$personalAccessToken) {
            return null;
        }

        return $personalAccessToken->tokenable;
    }

    /**
     * Применить промокод
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'cart_items' => 'required|array',
            'cart_items.*.good_id' => 'required|integer',
            'cart_items.*.variation_id' => 'nullable|integer',
            'cart_items.*.quantity' => 'required|integer|min:1',
            'cart_items.*.price' => 'required|numeric|min:0',
            'cart_items.*.categories' => 'nullable|array',
            'order_amount' => 'required|numeric|min:0',
        ]);

        $code = strtoupper(trim($validated['code']));
        $cartItems = $validated['cart_items'];
        $orderAmount = $validated['order_amount'];

        // Находим промокод с загруженными связями
        $promocode = Promocode::with('user')->where('code', $code)->first();

        if (!$promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден',
                'errors' => ['Промокод с таким кодом не существует']
            ], 404);
        }

        // Получаем пользователя из токена или через Auth
        $user = $this->getUserFromToken($request);
        $userId = $user ? $user->id : Auth::id();
        $sessionId = $request->header('X-Session-ID');

        // Логирование для отладки
        \Illuminate\Support\Facades\Log::info('Promocode apply check', [
            'promocode_id' => $promocode->id,
            'promocode_code' => $promocode->code,
            'promocode_user_id' => $promocode->user_id,
            'user_id' => $userId,
            'user_from_token' => $user ? $user->id : null,
            'auth_id' => Auth::id(),
            'is_personal' => $promocode->user_id !== null,
        ]);

        // Собираем все ошибки проверок
        $allErrors = [];

        // Проверяем, можно ли использовать промокод (общий лимит)
        $canBeUsedCheck = $promocode->canBeUsed();
        if (!$canBeUsedCheck['can_use']) {
            $allErrors = array_merge($allErrors, $canBeUsedCheck['errors']);
        }

        // Проверяем лимит на пользователя
        $canBeUsedByUserCheck = $promocode->canBeUsedByUser($userId, $sessionId);
        if (!$canBeUsedByUserCheck['can_use']) {
            $allErrors = array_merge($allErrors, $canBeUsedByUserCheck['errors']);
        }

        // Проверяем применимость к заказу
        $applicabilityCheck = $promocode->isApplicableToOrder($cartItems, $orderAmount, $userId);
        if (!$applicabilityCheck['is_applicable']) {
            $allErrors = array_merge($allErrors, $applicabilityCheck['errors']);
        }

        // Если есть ошибки, возвращаем их все вместе с информацией о категориях
        if (!empty($allErrors)) {
            $responseData = [
                'success' => false,
                'message' => 'Промокод не может быть применен',
                'errors' => $allErrors
            ];
            
            // Добавляем информацию о категориях, если промокод применим только к категориям
            if (isset($applicabilityCheck['applicable_categories']) && !empty($applicabilityCheck['applicable_categories'])) {
                $responseData['applicable_categories'] = $applicabilityCheck['applicable_categories'];
            }
            
            return response()->json($responseData, 422);
        }

        // Рассчитываем скидку
        $discountResult = $promocode->calculateDiscount($orderAmount, $cartItems, $userId);

        // Для бесплатной доставки discount всегда 0, но промокод все равно применим
        if ($promocode->type !== 'free_delivery' && ($discountResult['discount'] <= 0 || !empty($discountResult['errors']))) {
            $errors = $discountResult['errors'] ?: ['Промокод не дает скидку для данного заказа'];
            return response()->json([
                'success' => false,
                'message' => 'Промокод не может быть применен',
                'errors' => $errors
            ], 422);
        }

        // Возвращаем информацию о примененном промокоде
        // Запись в promocode_usage будет создана при создании заказа
        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно применен',
            'data' => [
                'promocode' => [
                    'id' => $promocode->id,
                    'code' => $promocode->code,
                    'name' => $promocode->name,
                    'type' => $promocode->type,
                    'value' => $promocode->value,
                    'max_discount_amount' => $promocode->max_discount_amount,
                ],
                'discount_amount' => $discountResult['discount'],
                'is_free_delivery' => $promocode->type === 'free_delivery',
            ]
        ]);
    }

    /**
     * Удалить промокод из заказа
     */
    public function remove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $code = strtoupper(trim($validated['code']));

        return response()->json([
            'success' => true,
            'message' => 'Промокод удален из заказа',
            'data' => [
                'removed_code' => $code
            ]
        ]);
    }

    /**
     * Получить информацию о промокоде без применения
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $code = strtoupper(trim($validated['code']));
        $promocode = Promocode::where('code', $code)->first();

        if (!$promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден'
            ], 404);
        }

        $canBeUsedCheck = $promocode->canBeUsed();
        
        return response()->json([
            'success' => true,
            'data' => [
                'code' => $promocode->code,
                'name' => $promocode->name,
                'description' => $promocode->description,
                'type' => $promocode->type,
                'value' => $promocode->value,
                'min_order_amount' => $promocode->min_order_amount,
                'is_active' => $promocode->isActive(),
                'can_be_used' => $canBeUsedCheck['can_use'],
                'errors' => $canBeUsedCheck['errors'] ?? [],
            ]
        ]);
    }

    /**
     * Создать промокод за отсутствующий товар
     */
    public function createAbsentPromocode(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id',
                'is_unregistered' => 'nullable',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        }

        $goodId = (int) $validated['good_id'];
        // Преобразуем is_unregistered в boolean
        $isUnregistered = filter_var($validated['is_unregistered'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Получаем пользователя из токена (опционально)
        $user = $this->getUserFromToken($request);
        
        // Проверяем параметр prom_absence_notreg
        $promAbsenceNotreg = Setting::where('key', 'prom_absence_notreg')->first();
        $promAbsenceNotregValue = $promAbsenceNotreg ? (int) $promAbsenceNotreg->value : 0;

        // Если пользователь не авторизован и prom_absence_notreg = 0, требуем авторизацию
        if (!$user && $promAbsenceNotregValue === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация'
            ], 401);
        }

        // Если пользователь авторизован, проверяем, не получал ли он уже промокод на этот товар
        if ($user) {
            $userId = $user->id;
            $existingUsage = AbsentPromocodeUsage::where('user_id', $userId)
                ->where('good_id', $goodId)
                ->first();

            if ($existingUsage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Промокод для этого товара уже был получен'
                ], 422);
            }
        } else {
            // Для незарегистрированных пользователей проверяем по IP адресу
            // Сначала пробуем получить IP из заголовков (для продакшена за прокси)
            $ipAddress = $request->header('X-Forwarded-For');
            if ($ipAddress) {
                // X-Forwarded-For может содержать несколько IP через запятую
                $ipAddress = trim(explode(',', $ipAddress)[0]);
            }
            
            // Если не получили из X-Forwarded-For, пробуем X-Real-IP
            if (!$ipAddress) {
                $ipAddress = $request->header('X-Real-IP');
            }
            
            // Если не получили из заголовков, используем $request->ip() (включая localhost)
            if (!$ipAddress) {
                $ipAddress = $request->ip();
            }
            
            // Проверяем по IP адресу как есть (включая localhost)
            // Если IP не получен, проверяем по 'unknown'
            $ipToCheck = $ipAddress ? $ipAddress : 'unknown';
            
            $existingUsage = AbsentPromocodeUsage::where('ip_address', $ipToCheck)
                ->where('good_id', $goodId)
                ->whereNull('user_id') // Только для незарегистрированных
                ->first();

            if ($existingUsage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Промокод для этого товара уже был получен'
                ], 422);
            }
        }

        // Получаем параметры сайта в зависимости от типа пользователя
        if ($isUnregistered || !$user) {
            // Для незарегистрированных используем специальные параметры
            $absentPromocodePercent = Setting::where('key', 'absent_prom_percent_notreg')->first();
            $absentPromocodePercentDays = Setting::where('key', 'absent_prom_percent_days_notreg')->first();
        } else {
            // Для зарегистрированных используем стандартные параметры
        $absentPromocodePercent = Setting::where('key', 'absent_promocode_percent')->first();
        $absentPromocodePercentDays = Setting::where('key', 'absent_promocode_percent_days')->first();
        }

        // Проверяем, что процент скидки не равен 0
        $percentValue = $absentPromocodePercent ? (float) $absentPromocodePercent->value : 0;
        if ($percentValue <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Промокоды за отсутствующие товары отключены'
            ], 422);
        }

        $daysValue = $absentPromocodePercentDays ? (int) $absentPromocodePercentDays->value : 30;

        // Получаем информацию о товаре
        $good = ShopGood::with('categories')->find($goodId);
        if (!$good) {
            return response()->json([
                'success' => false,
                'message' => 'Товар не найден'
            ], 404);
        }

        // Проверяем параметр prom_only_category
        $promOnlyCategory = Setting::where('key', 'prom_only_category')->first();
        $promOnlyCategoryValue = $promOnlyCategory ? (int) $promOnlyCategory->value : 0;

        // Генерируем уникальный код промокода
        $code = $this->generateUniquePromocodeCode();

        // Формируем название промокода
        if ($user) {
            $promocodeName = "отсутствие товара id {$goodId} для пользователя {$user->name}";
        } else {
            $promocodeName = "отсутствие товара id {$goodId} для незарегистрированного пользователя";
        }

        // Создаем промокод в транзакции
        try {
            DB::beginTransaction();

            // Создаем промокод
            $promocode = Promocode::create([
                'code' => $code,
                'name' => $promocodeName,
                'type' => 'percentage',
                'value' => $percentValue,
                'usage_limit' => 1,
                'is_active' => true,
                'user_id' => $user ? $user->id : null, // Для незарегистрированных user_id = null
                'expires_at' => Carbon::now()->addDays($daysValue),
            ]);

            // Если prom_only_category = 1, привязываем промокод к категориям товара
            if ($promOnlyCategoryValue === 1 && $good->categories && $good->categories->count() > 0) {
                $categoryIds = $good->categories->pluck('id')->toArray();
                $promocode->categories()->sync($categoryIds);
            }

            // Создаем запись об использовании
            $usageData = [
                'good_id' => $goodId,
                'promocode_id' => $promocode->id,
            ];
            
            if ($user) {
                // Для зарегистрированных пользователей сохраняем user_id
                $usageData['user_id'] = $user->id;
            } else {
                // Для незарегистрированных пользователей сохраняем IP адрес
                // Сначала пробуем получить IP из заголовков (для продакшена за прокси)
                $ipAddress = $request->header('X-Forwarded-For');
                if ($ipAddress) {
                    // X-Forwarded-For может содержать несколько IP через запятую
                    $ipAddress = trim(explode(',', $ipAddress)[0]);
                }
                
                // Если не получили из X-Forwarded-For, пробуем X-Real-IP
                if (!$ipAddress) {
                    $ipAddress = $request->header('X-Real-IP');
                }
                
                // Если не получили из заголовков, используем $request->ip() (включая localhost)
                if (!$ipAddress) {
                    $ipAddress = $request->ip();
                }
                
                // Сохраняем IP адрес как есть (включая localhost 127.0.0.1 и ::1)
                // Если IP не получен вообще, используем 'unknown'
                if ($ipAddress) {
                    $usageData['ip_address'] = $ipAddress;
                } else {
                    $usageData['ip_address'] = 'unknown';
                }
            }
            
            // Всегда создаем запись, чтобы предотвратить повторные запросы
            // Проверяем, что все необходимые поля заполнены
            if (!$user && empty($usageData['ip_address'])) {
                // Все равно пытаемся создать запись с 'unknown' IP
                $usageData['ip_address'] = 'unknown';
            }
            
            $createdUsage = AbsentPromocodeUsage::create($usageData);

            DB::commit();

            // Загружаем категории промокода, если они есть
            $promocode->load('categories');
            $applicableCategories = null;
            if ($promocode->categories && $promocode->categories->count() > 0) {
                $applicableCategories = $promocode->categories->map(function($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ];
                })->toArray();
            }
            
            $responseData = [
                'success' => true,
                'message' => 'Промокод успешно создан',
                'data' => [
                    'promocode' => [
                        'id' => $promocode->id,
                        'code' => $promocode->code,
                        'name' => $promocode->name,
                        'type' => $promocode->type,
                        'value' => $promocode->value,
                        'expires_at' => $promocode->expires_at->format('Y-m-d H:i:s'),
                    ]
                ]
            ];
            
            // Добавляем информацию о категориях, если промокод применим только к категориям
            if ($applicableCategories) {
                $responseData['data']['applicable_categories'] = $applicableCategories;
            }
            
            return response()->json($responseData);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error creating absent promocode', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'good_id' => $goodId,
                'has_user' => !is_null($user),
                'user_id' => $user ? $user->id : null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании промокода: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Генерация уникального кода промокода (8 символов, буквенно-цифровой)
     */
    private function generateUniquePromocodeCode(): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $maxAttempts = 100;
        $attempt = 0;

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            $exists = Promocode::where('code', $code)->exists();
            $attempt++;

            if (!$exists) {
                return $code;
            }
        } while ($attempt < $maxAttempts);

        throw new \Exception('Не удалось сгенерировать уникальный код промокода');
    }

    /**
     * Проверить, может ли пользователь получить промокод за отсутствующий товар
     */
    public function checkAbsentPromocode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'good_id' => 'required|integer|exists:shop_goods,id',
        ]);

        $goodId = $validated['good_id'];

        // Получаем пользователя из токена
        $user = $this->getUserFromToken($request);
        
        // Проверяем параметр prom_absence_notreg
        $promAbsenceNotreg = Setting::where('key', 'prom_absence_notreg')->first();
        $promAbsenceNotregValue = $promAbsenceNotreg ? (int) $promAbsenceNotreg->value : 0;

        // Если пользователь не авторизован и prom_absence_notreg = 0, требуем авторизацию
        if (!$user && $promAbsenceNotregValue === 0) {
            return response()->json([
                'success' => false,
                'can_get' => false,
                'message' => 'Необходима авторизация'
            ]);
        }

        // Проверяем, не получал ли пользователь уже промокод на этот товар
        if ($user) {
            $userId = $user->id;
            $existingUsage = AbsentPromocodeUsage::where('user_id', $userId)
                ->where('good_id', $goodId)
                ->first();

            if ($existingUsage) {
                return response()->json([
                    'success' => true,
                    'can_get' => false,
                    'message' => 'Промокод для этого товара уже был получен'
                ]);
            }
        } else {
            // Для незарегистрированных пользователей проверяем по IP адресу
            // Сначала пробуем получить IP из заголовков (для продакшена за прокси)
            $ipAddress = $request->header('X-Forwarded-For');
            if ($ipAddress) {
                // X-Forwarded-For может содержать несколько IP через запятую
                $ipAddress = trim(explode(',', $ipAddress)[0]);
            }
            
            // Если не получили из X-Forwarded-For, пробуем X-Real-IP
            if (!$ipAddress) {
                $ipAddress = $request->header('X-Real-IP');
            }
            
            // Если не получили из заголовков, используем $request->ip() (включая localhost)
            if (!$ipAddress) {
                $ipAddress = $request->ip();
            }
            
            // Проверяем по IP адресу как есть (включая localhost)
            // Если IP не получен, проверяем по 'unknown'
            $ipToCheck = $ipAddress ? $ipAddress : 'unknown';
            
            $existingUsage = AbsentPromocodeUsage::where('ip_address', $ipToCheck)
                ->where('good_id', $goodId)
                ->whereNull('user_id') // Только для незарегистрированных
                ->first();

            if ($existingUsage) {
                return response()->json([
                    'success' => true,
                    'can_get' => false,
                    'message' => 'Промокод для этого товара уже был получен'
                ]);
            }
        }

        // Проверяем параметр сайта в зависимости от типа пользователя
        if (!$user) {
            // Для незарегистрированных используем специальные параметры
            $absentPromocodePercent = Setting::where('key', 'absent_prom_percent_notreg')->first();
        } else {
            // Для зарегистрированных используем стандартные параметры
            $absentPromocodePercent = Setting::where('key', 'absent_promocode_percent')->first();
        }
        
        $percentValue = $absentPromocodePercent ? (float) $absentPromocodePercent->value : 0;

        if ($percentValue <= 0) {
            return response()->json([
                'success' => true,
                'can_get' => false,
                'message' => 'Промокоды за отсутствующие товары отключены'
            ]);
        }

        return response()->json([
            'success' => true,
            'can_get' => true,
            'message' => 'Промокод доступен'
        ]);
    }
}
