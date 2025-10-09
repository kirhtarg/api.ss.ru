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

        // Находим промокод
        $promocode = Promocode::where('code', $code)->first();

        if (!$promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден'
            ], 404);
        }

        // Проверяем, можно ли использовать промокод
        if (!$promocode->canBeUsed()) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод неактивен или исчерпан лимит использований'
            ], 422);
        }

        // Получаем ID пользователя или сессии
        $userId = Auth::id();
        $sessionId = $request->header('X-Session-ID');

        if (!$promocode->canBeUsedByUser($userId, $sessionId)) {
            return response()->json([
                'success' => false,
                'message' => 'Вы уже использовали этот промокод максимальное количество раз'
            ], 422);
        }

        // Проверяем применимость к заказу
        if (!$promocode->isApplicableToOrder($cartItems, $orderAmount)) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не применим к данному заказу'
            ], 422);
        }

        // Рассчитываем скидку
        $discountAmount = $promocode->calculateDiscount($orderAmount, $cartItems);

        if ($discountAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не дает скидку для данного заказа'
            ], 422);
        }

        // Возвращаем информацию о примененном промокоде
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
                ],
                'discount_amount' => $discountAmount,
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

        if (!$promocode->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод неактивен'
            ], 422);
        }

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
                'can_be_used' => $promocode->canBeUsed(),
            ]
        ]);
    }
}
