<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Services\OrderCalculationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopOrdersController extends Controller
{
    protected $calculationService;
    protected $notificationService;

    public function __construct(OrderCalculationService $calculationService, NotificationService $notificationService)
    {
        $this->calculationService = $calculationService;
        $this->notificationService = $notificationService;
    }
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'customer_city' => 'required|string|max:255',
                'delivery_method' => 'required|string|max:255',
                'shipping_address' => 'nullable|string',
                'payment_method' => 'required|string|max:255',
                'payment_method_id' => 'nullable|integer|exists:shop_payment_methods,id',
                'subtotal' => 'required|numeric|min:0',
                'delivery_cost' => 'required|numeric|min:0',
                'total' => 'required|numeric|min:0',
                'items' => 'required|array|min:1',
                'items.*.good_id' => 'required|integer|exists:shop_goods,id',
                'items.*.variation_id' => 'nullable|integer|exists:shop_good_variations,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
                'bonus_points_used' => 'nullable|integer|min:0',
                'promo_code' => 'nullable|string|max:255',
                'promo_code_id' => 'nullable|integer|exists:promocodes,id',
                'promo_code_discount_amount' => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

            // Генерируем номер заказа
            $orderNumber = 'ORD-'.date('Ymd').'-'.str_pad(ShopOrder::count() + 1, 3, '0', STR_PAD_LEFT);

            // Пересчитываем товары и бонусы через сервис
            $orderItems = [];
            $totalOrderBonuses = 0;
            $calculatedSubtotal = 0;

            foreach ($request->items as $item) {
                $good = ShopGood::find($item['good_id']);
                $variation = isset($item['variation_id']) ? ShopGoodVariation::find($item['variation_id']) : null;
                $user = auth()->user();

                if ($good) {
                    $calcResult = $this->calculationService->calculateFinalUnitPrice($good, $variation, $user);
                    $bonus = $this->calculationService->calculateItemBonuses($calcResult);
                    
                    $orderItems[] = array_merge($item, [
                        'price' => $calcResult['final_price'],
                        'base_price' => $calcResult['base_price'],
                        'sale_price' => $calcResult['sale_price'],
                        'bonus_points' => $bonus,
                        'total' => $calcResult['final_price'] * $item['quantity']
                    ]);
                    
                    $calculatedSubtotal += $calcResult['final_price'] * $item['quantity'];
                    $totalOrderBonuses += $bonus * $item['quantity'];
                }
            }

            // Создаем заказ
            $order = ShopOrder::create([
                'order_number' => $orderNumber,
                'user_id' => auth()->id(),
                'status_id' => 1, // Статус "Новый"
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone,
                'items' => $orderItems,
                'subtotal' => $calculatedSubtotal,
                'sale_discount_amount' => $request->sale_discount_amount ?? 0,
                'registered_user_discount_amount' => $request->registered_user_discount_amount ?? 0,
                'birthday_discount_amount' => $request->birthday_discount_amount ?? 0,
                'discount_amount' => $request->promo_code_discount_amount ?? 0,
                'promo_code_discount_amount' => $request->promo_code_discount_amount ?? 0,
                'total_discount_amount' => $request->total_discount_amount ?? ($request->promo_code_discount_amount ?? 0),
                'promo_code' => $request->promo_code,
                'promo_code_id' => $request->promo_code_id,
                'total_amount' => $calculatedSubtotal + $request->delivery_cost - ($request->promo_code_discount_amount ?? 0) - ($request->bonus_points_used ?? 0),
                'total_quantity' => array_sum(array_column($orderItems, 'quantity')),
                'order_bonus_points' => $totalOrderBonuses,
                'bonus_points_to_use' => $request->bonus_points_used ?? 0,
                'use_bonus_points' => ($request->bonus_points_used ?? 0) > 0,
                'payment_method' => $request->payment_method,
                'payment_method_id' => $request->payment_method_id,
                'shipping_method' => $request->delivery_method,
                'shipping_address' => $request->shipping_address,
                'notes' => $request->notes,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'delivery_cost' => $request->delivery_cost,
                    'bonus_points_used' => $request->bonus_points_used ?? 0,
                ],
            ]);

            // Создаем запись об использовании промокода, если он был применен
            if ($request->promo_code_id) {
                try {
                    $promocode = \App\Models\Promocode::find($request->promo_code_id);
                    if ($promocode) {
                        $sessionId = $request->header('X-Session-ID');
                        $discountAmount = $request->promo_code_discount_amount ?? 0;
                        $appliedTo = [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'items' => $request->items,
                        ];

                        $promocode->recordUsage(
                            auth()->id(),
                            $sessionId,
                            $order->id,
                            $discountAmount,
                            $appliedTo
                        );

                        Log::info('Promocode usage recorded', [
                            'promocode_id' => $promocode->id,
                            'order_id' => $order->id,
                            'discount_amount' => $discountAmount,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Ошибка создания записи об использовании промокода: '.$e->getMessage());
                    // Не прерываем создание заказа, если ошибка с промокодом
                }
            }

            DB::commit();

            // Отправляем уведомления
            try {
                $this->notificationService->notifyOrderCreated($order);
            } catch (\Exception $e) {
                Log::error('Ошибка отправки уведомления о новом заказе: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Заказ успешно создан',
                'order_id' => $order->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ошибка создания заказа: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании заказа',
            ], 500);
        }
    }
}
