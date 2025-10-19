<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $query = ShopOrder::where('user_id', $user->id)
                ->with(['status'])
                ->orderBy('created_at', 'desc');

            // Фильтр по статусу
            if ($request->has('status') && $request->input('status')) {
                $statusName = $request->input('status');
                $query->whereHas('status', function ($q) use ($statusName) {
                    $q->where('name', $statusName);
                });
            }

            // Поиск по номеру заказа
            if ($request->has('search') && $request->input('search')) {
                $search = $request->input('search');
                $query->where('order_number', 'like', "%{$search}%");
            }

            $orders = $query->paginate($request->input('per_page', 10));

            // Обогащаем данные заказов
            $orders->getCollection()->transform(function ($order) {
                $order->items = $order->getItemsWithDetails();
                return $order;
            });

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказов: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = ShopOrder::where('id', $id)
                ->where('user_id', $user->id)
                ->with(['status'])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Обогащаем данные заказа
            $order->items = $order->getItemsWithDetails();

            return response()->json([
                'success' => true,
                'data' => $order
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancel(int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = ShopOrder::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ нельзя отменить в текущем статусе'
                ], 400);
            }

            $order->update(['status' => 'cancelled']);

            // Возвращаем бонусы если использовались
            if ($order->bonus_points_used > 0) {
                $bonusService = app(\App\Services\BonusService::class);
                $bonusService->refundOrderBonuses($order);
            }

            return response()->json([
                'success' => true,
                'message' => 'Заказ отменен'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отмены заказа: ' . $e->getMessage()
            ], 500);
        }
    }
}
