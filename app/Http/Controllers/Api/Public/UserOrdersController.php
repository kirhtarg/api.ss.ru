<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
                ->with(['status', 'deliveryMethod'])
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
            Log::error('Ошибка загрузки заказов пользователя', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказов: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status', 'deliveryMethod'])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Обогащаем данные заказа
            $items = $order->getItemsWithDetails();
            
            // Добавляем информацию о количестве товаров на складе для каждого товара
            // Используем stock_quantity из таблиц shop_goods или shop_good_variations
            if (is_array($items)) {
                foreach ($items as &$item) {
                    $goodId = $item['good_id'] ?? null;
                    $variationId = $item['variation_id'] ?? null;
                    
                    if ($goodId) {
                        // Если есть вариация, берем stock_quantity из shop_good_variations
                        if ($variationId) {
                            $variation = ShopGoodVariation::find($variationId);
                            $item['current_stock_quantity'] = $variation ? ($variation->stock_quantity ?? 0) : 0;
                        } else {
                            // Если нет вариации, берем stock_quantity из shop_goods
                            $good = ShopGood::find($goodId);
                            $item['current_stock_quantity'] = $good ? ($good->stock_quantity ?? 0) : 0;
                        }
                    } else {
                        $item['current_stock_quantity'] = 0;
                    }
                }
            }
            
            $order->items = $items;

            return response()->json([
                'success' => true,
                'data' => $order
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки заказа пользователя', [
                'order_id' => $id,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancel($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status'])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Проверяем, что заказ не оплачен (для неоплаченных заказов можно отменять)
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя отменить оплаченный заказ'
                ], 400);
            }

            // Проверяем, что заказ не отменен и не завершен
            if ($order->status && ($order->status->is_cancelled || $order->status->is_finished)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ нельзя отменить в текущем статусе'
                ], 400);
            }

            // Находим статус отмены
            $cancelStatus = ShopOrderStatus::where('is_cancelled', true)->first();
            if (!$cancelStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'Статус отмены не найден в системе'
                ], 500);
            }

            // Возвращаем товары на склад
            $this->restoreOrderItemsToStock($order);
            
            // Возвращаем бонусы пользователю
            $this->restoreUserBonuses($order);

            // Обновляем статус заказа
            $order->status_id = $cancelStatus->id;
            $order->save();

            // Загружаем обновленные связи
            $order->load(['status']);

            // Отправляем уведомления об отмене заказа
            try {
                $notificationService = app(\App\Services\NotificationService::class);
                $notificationService->notifyOrderCancelled($order);
            } catch (\Exception $e) {
                Log::error('Order cancellation notification error: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Заказ успешно отменен',
                'data' => [
                    'id' => $order->id,
                    'status_id' => $order->status_id,
                    'status' => $order->status->name,
                    'status_display' => $order->status->display_name,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка отмены заказа: ' . $e->getMessage(), [
                'order_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отмены заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Возврат товаров заказа на склад при отмене
     */
    private function restoreOrderItemsToStock(ShopOrder $order): void
    {
        try {
            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
            
            if (!is_array($items) || empty($items)) {
                Log::info('Заказ не содержит товаров для возврата на склад', [
                    'order_id' => $order->id
                ]);
                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (!$goodId || $quantity <= 0) {
                    continue;
                }

                // Если есть вариация, обновляем stock_quantity в shop_good_variations
                if ($variationId) {
                    $variation = ShopGoodVariation::find($variationId);
                    if ($variation) {
                        $oldQuantity = $variation->stock_quantity ?? 0;
                        $variation->increment('stock_quantity', $quantity);
                        $variation->refresh();

                        Log::info('Товар (вариация) возвращен на склад при отмене заказа', [
                            'order_id' => $order->id,
                            'good_id' => $goodId,
                            'variation_id' => $variationId,
                            'quantity_returned' => $quantity,
                            'old_quantity' => $oldQuantity,
                            'new_quantity' => $variation->stock_quantity
                        ]);
                    }
                } else {
                    $good = ShopGood::find($goodId);
                    if ($good) {
                        $oldQuantity = $good->stock_quantity ?? 0;
                        $good->increment('stock_quantity', $quantity);
                        $good->refresh();

                        Log::info('Товар возвращен на склад при отмене заказа', [
                            'order_id' => $order->id,
                            'good_id' => $goodId,
                            'quantity_returned' => $quantity,
                            'old_quantity' => $oldQuantity,
                            'new_quantity' => $good->stock_quantity
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате товаров на склад: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Создать заявку на отмену заказа
     */
    public function requestCancellation($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status', 'deliveryMethod'])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Проверяем, что заявка еще не создана
            if ($order->cancellation_request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заявка на отмену заказа уже создана'
                ], 400);
            }

            // Устанавливаем флаг заявки на отмену
            $order->cancellation_request = true;
            $order->save();

            // Отправляем уведомления о заявке на отмену (только для оплаченных заказов)
            if ($order->payed) {
                try {
                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->notifyCancellationRequest($order);
                } catch (\Exception $e) {
                    Log::error('Cancellation request notification error: ' . $e->getMessage());
                }
            }

            Log::info('Заявка на отмену заказа создана', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'order_number' => $order->order_number
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Заявка на отмену заказа успешно создана',
                'data' => [
                    'id' => $order->id,
                    'cancellation_request' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка создания заявки на отмену заказа: ' . $e->getMessage(), [
                'order_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания заявки на отмену заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Возврат использованных бонусов пользователю при отмене заказа
     */
    private function restoreUserBonuses(ShopOrder $order): void
    {
        try {
            if (!$order->user_id || !$order->use_bonus_points || !$order->bonus_points_to_use || $order->bonus_points_to_use <= 0) {
                return;
            }

            $userBonus = UserBonus::getOrCreateForUser($order->user_id);
            $bonusPoints = (int) $order->bonus_points_to_use;

            // Возвращаем бонусы пользователю
            $userBonus->points += $bonusPoints;
            $userBonus->save();

            // Создаем транзакцию о возврате
            UserBonusTransaction::create([
                'user_id' => $order->user_id,
                'type' => 'refund',
                'points' => $bonusPoints,
                'description' => "Возврат бонусов за отмененный заказ #{$order->order_number}",
                'order_id' => $order->id,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'action' => 'cancel_order_refund'
                ]
            ]);

            Log::info('Бонусы возвращены пользователю при отмене заказа', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'bonus_points_returned' => $bonusPoints,
                'new_balance' => $userBonus->points
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате бонусов пользователю: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Получить список статусов заказов (публичный эндпоинт для пользователей)
     */
    public function getStatuses(): JsonResponse
    {
        try {
            $statuses = ShopOrderStatus::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($status) {
                    return [
                        'id' => $status->id,
                        'name' => $status->name,
                        'display_name' => $status->display_name,
                        'color' => $status->color,
                        'is_active' => (bool) $status->is_active,
                        'is_finished' => (bool) $status->is_finished,
                        'is_cancelled' => (bool) $status->is_cancelled,
                        'sort_order' => $status->sort_order,
                        'description' => $status->description,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $statuses
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки статусов заказов: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статусов: ' . $e->getMessage()
            ], 500);
        }
    }
}
