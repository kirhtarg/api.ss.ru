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

        // Если есть ошибки, возвращаем их все
        if (!empty($allErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не может быть применен',
                'errors' => $allErrors
            ], 422);
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
        $validated = $request->validate([
            'good_id' => 'required|integer|exists:shop_goods,id',
        ]);

        // Получаем пользователя из токена
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация'
            ], 401);
        }

        $goodId = $validated['good_id'];
        $userId = $user->id;

        // Проверяем, не получал ли пользователь уже промокод на этот товар
        $existingUsage = AbsentPromocodeUsage::where('user_id', $userId)
            ->where('good_id', $goodId)
            ->first();

        if ($existingUsage) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод для этого товара уже был получен'
            ], 422);
        }

        // Получаем параметры сайта
        $absentPromocodePercent = Setting::where('key', 'absent_promocode_percent')->first();
        $absentPromocodePercentDays = Setting::where('key', 'absent_promocode_percent_days')->first();

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
        $good = ShopGood::find($goodId);
        if (!$good) {
            return response()->json([
                'success' => false,
                'message' => 'Товар не найден'
            ], 404);
        }

        // Генерируем уникальный код промокода
        $code = $this->generateUniquePromocodeCode();

        // Создаем промокод в транзакции
        try {
            DB::beginTransaction();

            // Создаем промокод
            $promocode = Promocode::create([
                'code' => $code,
                'name' => "отсутствие товара id {$goodId} для пользователя {$user->name}",
                'type' => 'percentage',
                'value' => $percentValue,
                'usage_limit' => 1,
                'is_active' => true,
                'user_id' => $userId,
                'expires_at' => Carbon::now()->addDays($daysValue),
            ]);

            // Создаем запись об использовании
            AbsentPromocodeUsage::create([
                'user_id' => $userId,
                'good_id' => $goodId,
                'promocode_id' => $promocode->id,
            ]);

            DB::commit();

            return response()->json([
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
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error creating absent promocode: ' . $e->getMessage());
            
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

        // Получаем пользователя из токена
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'can_get' => false,
                'message' => 'Необходима авторизация'
            ]);
        }

        $goodId = $validated['good_id'];
        $userId = $user->id;

        // Проверяем, не получал ли пользователь уже промокод на этот товар
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

        // Проверяем параметр сайта
        $absentPromocodePercent = Setting::where('key', 'absent_promocode_percent')->first();
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
