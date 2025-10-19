<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ShopOrdersController extends Controller
{
    /**
     * Получить список заказов
     */
    public function index(Request $request): JsonResponse
    {
        // Пока возвращаем моковые данные
        $orders = [
            [
                'id' => 1,
                'order_number' => 'ORD-001',
                'customer_name' => 'Иван Петров',
                'customer_email' => 'ivan@example.com',
                'customer_phone' => '+7 (999) 123-45-67',
                'status' => 'processing',
                'total_amount' => 15000,
                'discount_amount' => 1000,
                'payment_method' => 'Карта',
                'shipping_method' => 'Курьер',
                'items_count' => 2,
                'total_quantity' => 3,
                'created_at' => now()->toISOString(),
                'items' => [
                    [
                        'id' => 1,
                        'name' => 'Кроссовки Nike Air Max',
                        'sku' => 'NIKE-AM-001',
                        'price' => 8000,
                        'quantity' => 1,
                        'total' => 8000,
                        'image' => null
                    ],
                    [
                        'id' => 2,
                        'name' => 'Футболка Adidas',
                        'sku' => 'ADIDAS-T-001',
                        'price' => 3500,
                        'quantity' => 2,
                        'total' => 7000,
                        'image' => null
                    ]
                ]
            ],
            [
                'id' => 2,
                'order_number' => 'ORD-002',
                'customer_name' => 'Мария Сидорова',
                'customer_email' => 'maria@example.com',
                'customer_phone' => '+7 (999) 765-43-21',
                'status' => 'pending',
                'total_amount' => 8500,
                'discount_amount' => 0,
                'payment_method' => 'Наличные',
                'shipping_method' => 'Самовывоз',
                'items_count' => 1,
                'total_quantity' => 1,
                'created_at' => now()->subHours(2)->toISOString(),
                'items' => [
                    [
                        'id' => 3,
                        'name' => 'Рюкзак Puma',
                        'sku' => 'PUMA-BP-001',
                        'price' => 8500,
                        'quantity' => 1,
                        'total' => 8500,
                        'image' => null
                    ]
                ]
            ]
        ];

        // Фильтрация по поиску
        if ($request->filled('search')) {
            $search = $request->get('search');
            $orders = array_filter($orders, function ($order) use ($search) {
                return stripos($order['order_number'], $search) !== false ||
                       stripos($order['customer_name'], $search) !== false ||
                       stripos($order['customer_email'], $search) !== false;
            });
        }

        // Фильтрация по статусу
        if ($request->filled('status')) {
            $status = $request->get('status');
            $orders = array_filter($orders, function ($order) use ($status) {
                return $order['status'] === $status;
            });
        }

        // Фильтрация по дате
        if ($request->filled('date_filter')) {
            $dateFilter = $request->get('date_filter');
            $now = now();
            
            $orders = array_filter($orders, function ($order) use ($dateFilter, $now) {
                $orderDate = \Carbon\Carbon::parse($order['created_at']);
                
                switch ($dateFilter) {
                    case 'today':
                        return $orderDate->isToday();
                    case 'week':
                        return $orderDate->isAfter($now->subWeek());
                    case 'month':
                        return $orderDate->isAfter($now->subMonth());
                    default:
                        return true;
                }
            });
        }

        return response()->json([
            'success' => true,
            'data' => array_values($orders)
        ]);
    }

    /**
     * Получить заказ по ID
     */
    public function show($id): JsonResponse
    {
        // Пока возвращаем моковые данные
        $order = [
            'id' => $id,
            'order_number' => 'ORD-' . str_pad($id, 3, '0', STR_PAD_LEFT),
            'customer_name' => 'Иван Петров',
            'customer_email' => 'ivan@example.com',
            'customer_phone' => '+7 (999) 123-45-67',
            'status' => 'processing',
            'total_amount' => 15000,
            'discount_amount' => 1000,
            'payment_method' => 'Карта',
            'shipping_method' => 'Курьер',
            'items_count' => 2,
            'total_quantity' => 3,
            'created_at' => now()->toISOString(),
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Кроссовки Nike Air Max',
                    'sku' => 'NIKE-AM-001',
                    'price' => 8000,
                    'quantity' => 1,
                    'total' => 8000,
                    'image' => null
                ],
                [
                    'id' => 2,
                    'name' => 'Футболка Adidas',
                    'sku' => 'ADIDAS-T-001',
                    'price' => 3500,
                    'quantity' => 2,
                    'total' => 7000,
                    'image' => null
                ]
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Создать новый заказ
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:20',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|string|max:255',
            'shipping_method' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Здесь будет логика создания заказа
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Заказ создан успешно',
            'data' => [
                'id' => rand(100, 999),
                'order_number' => 'ORD-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT)
            ]
        ], 201);
    }

    /**
     * Обновить заказ
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled',
            'customer_name' => 'sometimes|string|max:255',
            'customer_email' => 'sometimes|email|max:255',
            'customer_phone' => 'sometimes|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Здесь будет логика обновления заказа
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Заказ обновлен успешно'
        ]);
    }

    /**
     * Удалить заказ
     */
    public function destroy($id): JsonResponse
    {
        // Здесь будет логика удаления заказа
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Заказ удален успешно'
        ]);
    }

    /**
     * Обновить статус заказа
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,processing,shipped,delivered,cancelled'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Здесь будет логика обновления статуса заказа
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Статус заказа обновлен успешно'
        ]);
    }

    /**
     * Экспорт заказов
     */
    public function export(Request $request): JsonResponse
    {
        // Здесь будет логика экспорта заказов
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Экспорт заказов будет реализован позже'
        ]);
    }
}
