<?php

namespace App\Http\Controllers;

use App\Models\Promocode;
use App\Models\PromocodeUsage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
        $promocode = Promocode::with('users')->where('code', $code)->first();

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
        $allowedUserIds = $promocode->users()->select('users.id')->pluck('users.id')->toArray();
        \Illuminate\Support\Facades\Log::info('Promocode apply check', [
            'promocode_id' => $promocode->id,
            'promocode_code' => $promocode->code,
            'user_id' => $userId,
            'user_from_token' => $user ? $user->id : null,
            'auth_id' => Auth::id(),
            'allowed_users_count' => $promocode->users()->count(),
            'allowed_user_ids' => $allowedUserIds,
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
}
