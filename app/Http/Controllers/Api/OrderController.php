<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Получить детали заказа по номеру
     */
    public function getOrderDetails(Request $request)
    {
        try {
            $orderNumber = $request->query('order_number');
            
            if (!$orderNumber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Номер заказа не указан'
                ], 400);
            }

            $order = ShopOrder::with('status')->where('order_number', $orderNumber)->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ не найден'
                ], 404);
            }

            // Получаем данные о товарах из JSON поля
            $items = $order->getItemsWithDetails();

            return response()->json([
                'success' => true,
                'data' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status ? $order->status->name : 'Обрабатывается',
                    'created_at' => $order->created_at,
                    'subtotal' => $order->subtotal,
                    'delivery_cost' => $order->delivery_cost,
                    'total' => $order->total_amount,
                    'payment_method' => $order->payment_method,
                    'shipping_method' => $order->shipping_method,
                    'shipping_address' => $order->shipping_address,
                    'notes' => $order->notes,
                    // Скидки
                    'sale_discount_amount' => $order->sale_discount_amount,
                    'registered_user_discount_amount' => $order->registered_user_discount_amount,
                    'promo_code_discount_amount' => $order->promo_code_discount_amount,
                    'birthday_discount_amount' => $order->birthday_discount_amount,
                    'total_discount_amount' => $order->total_discount_amount,
                    'customer' => [
                        'name' => $order->customer_name ?? '',
                        'email' => $order->customer_email ?? '',
                        'phone' => $order->customer_phone ?? ''
                    ],
                    'items' => $items
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('OrderController: Error getting order details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }
}
