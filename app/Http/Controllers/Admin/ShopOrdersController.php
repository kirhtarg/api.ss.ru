<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopStock;
use App\Models\ShopWarehouse;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use App\Services\CdekService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ShopOrdersController extends Controller
{
    /**
     * Получить список заказов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopOrder::with(['status', 'user', 'paymentMethod', 'deliveryMethod'])
                ->orderBy('created_at', 'desc');

            // Поиск по номеру заказа, имени клиента, комментариям, телефону, email и сумме заказа
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('customer_phone', 'like', "%{$search}%")
                      ->orWhere('customer_email', 'like', "%{$search}%")
                      ->orWhere('comment', 'like', "%{$search}%");
                    
                    // Поиск по сумме заказа (точное совпадение или частичное)
                    if (is_numeric($search)) {
                        $q->orWhere('total_amount', $search)
                          ->orWhereRaw('CAST(total_amount AS CHAR) LIKE ?', ["%{$search}%"]);
                    } else {
                        $q->orWhereRaw('CAST(total_amount AS CHAR) LIKE ?', ["%{$search}%"]);
                    }
                });
            }

            // Фильтрация по статусам (по имени статуса)
            if ($request->filled('status')) {
                $statuses = $request->get('status');
                // Если передан массив статусов
                if (is_array($statuses) && count($statuses) > 0) {
                    // Проверяем, есть ли специальное значение "not_finished"
                    $hasNotFinished = in_array('not_finished', $statuses);
                    $regularStatuses = array_filter($statuses, function($status) {
                        return $status !== 'not_finished';
                    });
                    
                    $query->where(function ($q) use ($hasNotFinished, $regularStatuses) {
                        // Если выбрано "НЕ ЗАВЕРШЕННЫЕ", фильтруем по is_finished=false и is_cancelled=false
                        if ($hasNotFinished) {
                            $q->whereHas('status', function ($subQ) {
                                $subQ->where('is_finished', false)
                                     ->where('is_cancelled', false);
                            });
                        }
                        
                        // Если выбраны обычные статусы, добавляем их через OR
                        if (count($regularStatuses) > 0) {
                            if ($hasNotFinished) {
                                $q->orWhereHas('status', function ($subQ) use ($regularStatuses) {
                                    $subQ->whereIn('name', $regularStatuses);
                                });
                            } else {
                                $q->whereHas('status', function ($subQ) use ($regularStatuses) {
                                    $subQ->whereIn('name', $regularStatuses);
                                });
                            }
                        }
                    });
                } elseif (!is_array($statuses)) {
                    // Если передан один статус (для обратной совместимости)
                    if ($statuses === 'not_finished') {
                        // Фильтруем по is_finished=false и is_cancelled=false
                        $query->whereHas('status', function ($q) {
                            $q->where('is_finished', false)
                              ->where('is_cancelled', false);
                        });
                    } else {
                        // Обычный статус
                        $query->whereHas('status', function ($q) use ($statuses) {
                            $q->where('name', $statuses);
                        });
                    }
                }
            }

            // Фильтрация по типу оплаты
            if ($request->filled('payment_type')) {
                $paymentTypes = $request->get('payment_type');
                // Если передан массив типов оплаты
                if (is_array($paymentTypes) && count($paymentTypes) > 0) {
                    $query->whereHas('paymentMethod', function ($q) use ($paymentTypes) {
                        $q->whereIn('type', $paymentTypes);
                    });
                } elseif (!is_array($paymentTypes)) {
                    // Если передан один тип оплаты (для обратной совместимости)
                    $query->whereHas('paymentMethod', function ($q) use ($paymentTypes) {
                        $q->where('type', $paymentTypes);
                    });
                }
            }

            // Фильтрация по методу доставки (shipping_method_id)
            if ($request->filled('delivery_method_id')) {
                $deliveryMethodIds = $request->get('delivery_method_id');
                // Если передан массив ID методов доставки
                if (is_array($deliveryMethodIds) && count($deliveryMethodIds) > 0) {
                    $query->whereIn('shipping_method_id', $deliveryMethodIds);
                } elseif (!is_array($deliveryMethodIds)) {
                    // Если передан один ID метода доставки (для обратной совместимости)
                    $query->where('shipping_method_id', $deliveryMethodIds);
                }
            }

            // Фильтрация по активности
            if ($request->filled('is_active')) {
                $isActive = $request->boolean('is_active');
                $query->where('is_active', $isActive);
            }

            // Фильтрация по статусу оплаты
            if ($request->filled('payed')) {
                $payed = $request->boolean('payed');
                $query->where('payed', $payed);
            }

            // Фильтрация по дате
            if ($request->filled('date_from') && $request->filled('date_to')) {
                // Если заданы обе даты - фильтруем по диапазону
                $dateFrom = $request->get('date_from');
                $dateTo = $request->get('date_to');
                $query->whereDate('created_at', '>=', $dateFrom)
                      ->whereDate('created_at', '<=', $dateTo);
            } elseif ($request->filled('date_from')) {
                // Если задана только дата от - фильтруем по точной дате
                $dateFrom = $request->get('date_from');
                $query->whereDate('created_at', $dateFrom);
            } elseif ($request->filled('date_to')) {
                // Если задана только дата до - фильтруем до этой даты включительно
                $dateTo = $request->get('date_to');
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Фильтрация "Требуется возврат средств" (отмененные и оплаченные заказы)
            if ($request->filled('refund_required') && $request->get('refund_required') == '1') {
                $query->whereHas('status', function ($q) {
                    $q->where('is_cancelled', true);
                })->where('payed', true);
            }

            // Фильтрация "С комментарием" (заказы с непустым полем comment)
            if ($request->filled('has_comment') && $request->get('has_comment') == '1') {
                $query->whereNotNull('comment')
                      ->where('comment', '!=', '');
            }

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $orders = $query->paginate($perPage);

            // Форматируем данные для ответа
            $formattedOrders = $orders->map(function ($order) {
                $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
                $items = $items ?: [];
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_id' => $order->user_id,
                    'user_name' => $order->user ? $order->user->name : null,
                    'customer_name' => $order->customer_name,
                    'customer_email' => $order->customer_email,
                    'customer_phone' => $order->customer_phone,
                    'status_id' => $order->status_id,
                    'status' => $order->status ? $order->status->name : 'pending',
                    'status_display' => $order->status ? $order->status->display_name : 'Неизвестно',
                    'status_color' => $order->status ? $order->status->color : '#6B7280',
                    'status_is_finished' => $order->status ? (bool) $order->status->is_finished : false,
                    'status_is_cancelled' => $order->status ? (bool) $order->status->is_cancelled : false,
                    'payed' => (bool) $order->payed,
                    'is_active' => (bool) ($order->is_active ?? false),
                'order_bonus_points' => (int) ($order->order_bonus_points ?? 0),
                'user_bonus_points' => $order->user_id ? (\App\Models\UserBonus::where('user_id', $order->user_id)->value('points') ?? 0) : 0,
                'use_bonus_points' => (bool) ($order->use_bonus_points ?? false),
                'bonus_points_to_use' => (int) ($order->bonus_points_to_use ?? 0),
                'promo_code' => $order->promo_code,
                'total_amount' => (float) $order->total_amount,
                    'subtotal' => (float) $order->subtotal,
                    'discount_amount' => (float) $order->discount_amount,
                    'sale_discount_amount' => (float) ($order->sale_discount_amount ?? 0),
                    'registered_user_discount_amount' => (float) ($order->registered_user_discount_amount ?? 0),
                    'promo_code_discount_amount' => (float) ($order->promo_code_discount_amount ?? 0),
                    'total_discount_amount' => (float) ($order->total_discount_amount ?? 0),
                    'delivery_cost' => (float) ($order->delivery_cost ?? 0),
                    'payment_method' => $order->payment_method,
                    'shipping_method' => $order->shipping_method,
                    'shipping_method_id' => $order->shipping_method_id,
                    'deliveryMethod' => $order->deliveryMethod ? [
                        'id' => $order->deliveryMethod->id,
                        'name' => $order->deliveryMethod->name,
                        'type' => $order->deliveryMethod->type,
                    ] : null,
                    'shipping_address' => $order->shipping_address,
                    'cdek_order_uuid' => $order->cdek_order_uuid,
                    'delivery_status' => $order->delivery_status ? json_decode($order->delivery_status, true) : null,
                    'notes' => $order->notes,
                    'comment' => $order->comment,
                    'items_count' => count($items),
                    'total_quantity' => $order->total_quantity ?? 0,
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString(),
                    'items' => $order->getItemsWithDetails()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedOrders,
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить заказ по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $order = ShopOrder::with(['status', 'user', 'deliveryStatus', 'paymentMethod', 'deliveryMethod'])
                ->findOrFail($id);

            $formattedOrder = $this->formatOrderForResponse($order);
            
            // Добавляем информацию о количестве товаров на складе для каждого товара
            // Используем stock_quantity из таблиц shop_goods или shop_good_variations
            if (isset($formattedOrder['items']) && is_array($formattedOrder['items'])) {
                foreach ($formattedOrder['items'] as &$item) {
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
            
            // Добавляем информацию о пользователе и его бонусах
            if ($order->user_id) {
                $user = $order->user;
                $userBonus = UserBonus::where('user_id', $order->user_id)->first();
                $currentBonusPoints = $userBonus ? $userBonus->points : 0;
                $bonusPointsToUse = (int) ($order->bonus_points_to_use ?? 0);
                
                $formattedOrder['user_info'] = [
                    'id' => $user->id ?? null,
                    'name' => $user->name ?? $order->customer_name ?? 'Не указано',
                    'email' => $user->email ?? $order->customer_email ?? null,
                    'current_bonus_points' => $currentBonusPoints,
                    'bonus_points_after_cancel' => $currentBonusPoints + $bonusPointsToUse, // Для отмены - добавляем
                    'bonus_points_after_restore' => max(0, $currentBonusPoints - $bonusPointsToUse) // Для восстановления - вычитаем
                ];
            } else {
                $formattedOrder['user_info'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $formattedOrder
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказа: ' . $e->getMessage()
            ], 500);
        }
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
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'sometimes|string',
                'customer_name' => 'sometimes|string|max:255',
                'customer_email' => 'sometimes|email|max:255',
                'customer_phone' => 'sometimes|string|max:20',
                'shipping_address' => 'sometimes|string',
                'notes' => 'sometimes|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);

            // Обновление статуса
            if ($request->filled('status')) {
                $statusName = $request->get('status');
                $status = ShopOrderStatus::where('name', $statusName)->first();
                
                if ($status) {
                    $order->status_id = $status->id;
                }
            }

            // Обновление других полей
            if ($request->filled('customer_name')) {
                $order->customer_name = $request->get('customer_name');
            }
            if ($request->filled('customer_email')) {
                $order->customer_email = $request->get('customer_email');
            }
            if ($request->filled('customer_phone')) {
                $order->customer_phone = $request->get('customer_phone');
            }
            if ($request->filled('shipping_address')) {
                $order->shipping_address = $request->get('shipping_address');
            }
            if ($request->filled('notes')) {
                $order->notes = $request->get('notes');
            }

            $order->save();
            $order->load(['status']);

            return response()->json([
                'success' => true,
                'message' => 'Заказ обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status ? $order->status->name : null,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить заказ
     */
    public function destroy($id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Заказ удален успешно'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список статусов заказов
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
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статусов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить статус заказа
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status_id' => 'required|integer|exists:shop_order_statuses,id',
                'is_restore' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            $statusId = $request->get('status_id');
            $newStatus = ShopOrderStatus::findOrFail($statusId);
            $oldStatus = $order->status;

            // Проверка на завершенный статус для неоплаченного заказа
            if ($newStatus->is_finished && !$order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя установить завершенный статус для неоплаченного заказа'
                ], 422);
            }

            // Если новый статус - отмена заказа, возвращаем товары на склад и бонусы пользователю
            if ($newStatus->is_cancelled) {
                $this->restoreOrderItemsToStock($order);
                $this->restoreUserBonuses($order);
            }
            
            // Если старый статус был отмененным и выбирается другой статус (восстановление), вычитаем товары и бонусы
            if ($oldStatus && $oldStatus->is_cancelled && !$newStatus->is_cancelled && $request->get('is_restore', false)) {
                $this->deductOrderItemsFromStock($order);
                $this->deductUserBonuses($order);
            }

            $order->status_id = $statusId;
            $order->save();

            // Загружаем обновленные связи
            $order->load(['status']);

            return response()->json([
                'success' => true,
                'message' => 'Статус заказа обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'status_id' => $order->status_id,
                    'status' => $order->status->name,
                    'status_display' => $order->status->display_name,
                    'status_color' => $order->status->color,
                    'status_is_finished' => (bool) $order->status->is_finished,
                    'status_is_cancelled' => (bool) $order->status->is_cancelled,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Вычитание товаров заказа со склада при восстановлении из отмененных
     * Обновляет stock_quantity в таблицах shop_goods или shop_good_variations
     * Не уходит в минус - минимальное значение 0
     */
    private function deductOrderItemsFromStock(ShopOrder $order): void
    {
        try {
            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
            
            if (!is_array($items) || empty($items)) {
                Log::info('Заказ не содержит товаров для вычитания со склада', [
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

                // Если есть вариация, вычитаем stock_quantity из shop_good_variations
                if ($variationId) {
                    $variation = ShopGoodVariation::find($variationId);
                    if ($variation) {
                        $oldQuantity = $variation->stock_quantity ?? 0;
                        $newQuantity = max(0, $oldQuantity - $quantity); // Не уходим в минус
                        $variation->stock_quantity = $newQuantity;
                        $variation->save();

                        Log::info('Товар (вариация) вычтен со склада при восстановлении заказа', [
                            'order_id' => $order->id,
                            'good_id' => $goodId,
                            'variation_id' => $variationId,
                            'quantity_deducted' => $quantity,
                            'old_quantity' => $oldQuantity,
                            'new_quantity' => $newQuantity
                        ]);
                    }
                } else {
                    // Если нет вариации, вычитаем stock_quantity из shop_goods
                    $good = ShopGood::find($goodId);
                    if ($good) {
                        $oldQuantity = $good->stock_quantity ?? 0;
                        $newQuantity = max(0, $oldQuantity - $quantity); // Не уходим в минус
                        $good->stock_quantity = $newQuantity;
                        $good->save();

                        Log::info('Товар вычтен со склада при восстановлении заказа', [
                            'order_id' => $order->id,
                            'good_id' => $goodId,
                            'quantity_deducted' => $quantity,
                            'old_quantity' => $oldQuantity,
                            'new_quantity' => $newQuantity
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при вычитании товаров со склада: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
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
     * Списывание бонусов у пользователя при восстановлении заказа
     */
    private function deductUserBonuses(ShopOrder $order): void
    {
        try {
            if (!$order->user_id || !$order->use_bonus_points || !$order->bonus_points_to_use || $order->bonus_points_to_use <= 0) {
                return;
            }

            $userBonus = UserBonus::getOrCreateForUser($order->user_id);
            $bonusPoints = (int) $order->bonus_points_to_use;

            // Проверяем, достаточно ли бонусов
            if ($userBonus->points < $bonusPoints) {
                Log::warning('Недостаточно бонусов для списания при восстановлении заказа', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'required' => $bonusPoints,
                    'available' => $userBonus->points
                ]);
                // Списываем только доступное количество
                $bonusPoints = $userBonus->points;
            }

            if ($bonusPoints > 0) {
                // Списываем бонусы
                $userBonus->points -= $bonusPoints;
                $userBonus->total_spent += $bonusPoints;
                $userBonus->save();

                // Создаем транзакцию о списании
                UserBonusTransaction::create([
                    'user_id' => $order->user_id,
                    'type' => 'spend',
                    'points' => -$bonusPoints,
                    'description' => "Списание бонусов за восстановленный заказ #{$order->order_number}",
                    'order_id' => $order->id,
                    'metadata' => [
                        'order_number' => $order->order_number,
                        'action' => 'restore_order_deduction'
                    ]
                ]);

                Log::info('Бонусы списаны у пользователя при восстановлении заказа', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'bonus_points_deducted' => $bonusPoints,
                    'new_balance' => $userBonus->points
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при списании бонусов у пользователя: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Возврат товаров заказа на склад при отмене
     * Обновляет stock_quantity в таблицах shop_goods или shop_good_variations
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
                    // Если нет вариации, обновляем stock_quantity в shop_goods
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
     * Обновить статус оплаты заказа (payed)
     */
    public function updatePayed(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'payed' => 'required|boolean',
                'bonus_points' => 'nullable|integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            $order->load('user'); // Загружаем пользователя для получения имени
            $newPayedStatus = $request->get('payed');
            $oldPayedStatus = $order->payed;
            // Используем переданное значение bonus_points, если оно есть, иначе берем из заказа
            $bonusPoints = $request->has('bonus_points') 
                ? (int) $request->get('bonus_points') 
                : (int) ($order->order_bonus_points ?? 0);
            
            // Получаем текущее количество бонусов пользователя для ответа
            $currentBonusPoints = 0;
            if ($order->user_id) {
                $userBonus = \App\Models\UserBonus::where('user_id', $order->user_id)->first();
                $currentBonusPoints = $userBonus ? $userBonus->points : 0;
            }

            // Если статус не меняется, просто возвращаем успех
            if ($oldPayedStatus === $newPayedStatus) {
                return response()->json([
                    'success' => true,
                    'message' => 'Статус оплаты не изменился',
                    'data' => [
                        'id' => $order->id,
                        'payed' => (bool) $order->payed,
                    ]
                ]);
            }

            // Работа с бонусами только для зарегистрированных пользователей
            if ($order->user_id && $bonusPoints > 0) {
                $userBonus = \App\Models\UserBonus::getOrCreateForUser($order->user_id);

                if ($newPayedStatus) {
                    // Меняем статус на ОПЛАЧЕНО - начисляем бонусы
                    $userBonus->addPoints(
                        $bonusPoints,
                        "Начисление бонусов за оплату заказа #{$order->order_number}",
                        $order->id
                    );
                } else {
                    // Меняем статус на НЕ ОПЛАЧЕНО - списываем бонусы
                    // Проверяем, достаточно ли бонусов для списания
                    if ($userBonus->points < $bonusPoints) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Недостаточно бонусных баллов для списания. У пользователя: ' . $userBonus->points . ', требуется: ' . $bonusPoints
                        ], 422);
                    }

                    // Ищем транзакцию начисления бонусов за этот заказ
                    $earnTransaction = \App\Models\UserBonusTransaction::where('user_id', $order->user_id)
                        ->where('order_id', $order->id)
                        ->where('type', 'earn')
                        ->where('points', '>', 0)
                        ->first();

                    if ($earnTransaction) {
                        // Создаем транзакцию возврата (refund)
                        $userBonus->transactions()->create([
                            'type' => 'refund',
                            'points' => -$bonusPoints,
                            'description' => "Возврат бонусов за отмену оплаты заказа #{$order->order_number}",
                            'order_id' => $order->id,
                            'metadata' => ['original_transaction_id' => $earnTransaction->id]
                        ]);

                        // Уменьшаем бонусы
                        $userBonus->points -= $bonusPoints;
                        $userBonus->total_spent += $bonusPoints;
                        $userBonus->save();
                    } else {
                        // Если транзакции нет, просто списываем через spendPoints
                        try {
                            $userBonus->spendPoints(
                                $bonusPoints,
                                "Списание бонусов за отмену оплаты заказа #{$order->order_number}",
                                $order->id
                            );
                        } catch (\Exception $e) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Ошибка списания бонусов: ' . $e->getMessage()
                            ], 422);
                        }
                    }
                }
            }

            // Обновляем статус оплаты
            $order->payed = $newPayedStatus;
            $order->save();

            // Получаем обновленное количество бонусов после изменения
            $newBonusPoints = $currentBonusPoints;
            if ($order->user_id) {
                $userBonus = \App\Models\UserBonus::where('user_id', $order->user_id)->first();
                $newBonusPoints = $userBonus ? $userBonus->points : 0;
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Статус оплаты обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'payed' => (bool) $order->payed,
                    'user_name' => $order->user ? $order->user->name : null,
                    'current_bonus_points' => $currentBonusPoints,
                    'new_bonus_points' => $newBonusPoints,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса оплаты: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить статус активности заказа (is_active)
     */
    public function updateIsActive(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            $order->is_active = $request->get('is_active');
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Статус активности обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'is_active' => (bool) $order->is_active,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса активности: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить комментарий заказа
     */
    public function updateComment(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'comment' => 'nullable|string|max:5000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            
            // Проверяем, что комментарий можно изменить только если он пустой
            if (!empty($order->comment) && $request->filled('comment')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Комментарий можно изменить только если он пустой'
                ], 422);
            }

            $order->comment = $request->get('comment', '');
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Комментарий обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'comment' => $order->comment,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления комментария: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить статус доставки из СДЭК
     */
    public function updateDeliveryStatus(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            
            // Проверяем, что заказ использует СДЭК
            if (!$order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК'
                ], 400);
            }

            // Получаем статус из СДЭК
            $cdekService = new CdekService();
            $statusResult = $cdekService->getOrderStatus($order->cdek_order_uuid);

            if (!$statusResult['success']) {
                // Если заказ не найден в СДЭК (удален из ЛК) или любая ошибка получения статуса
                // Обновляем статус на "заказ не найден" при любой ошибке получения статуса
                $isNotFound = isset($statusResult['not_found']) && $statusResult['not_found'];
                
                // Если это явно "не найден" или просто ошибка получения статуса - обновляем статус
                if ($isNotFound || strpos($statusResult['message'] ?? '', 'Ошибка при получении статуса заказа') !== false) {
                    $deliveryStatus = [
                        'code' => 'NOT_FOUND',
                        'name' => 'Заказ не найден',
                    ];
                    
                    // Сохраняем статус в заказ
                    $order->delivery_status = json_encode($deliveryStatus, JSON_UNESCAPED_UNICODE);
                    $order->save();
                    $order->refresh();
                    
                    Log::info('CDEK order status updated to NOT_FOUND', [
                        'order_id' => $order->id,
                        'cdek_uuid' => $order->cdek_order_uuid,
                        'is_not_found' => $isNotFound
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Статус доставки обновлен: заказ не найден в СДЭК',
                        'data' => [
                            'id' => $order->id,
                            'delivery_status' => $deliveryStatus,
                        ]
                    ]);
                }
                
                // Для других ошибок (например, проблемы с настройками) возвращаем ошибку
                return response()->json([
                    'success' => false,
                    'message' => $statusResult['message'] ?? 'Ошибка получения статуса из СДЭК'
                ], 500);
            }

            $cdekData = $statusResult['data'];
            
            // Логируем структуру ответа для отладки
            Log::info('CDEK order status response', [
                'order_id' => $order->id,
                'cdek_uuid' => $order->cdek_order_uuid,
                'response_structure' => [
                    'has_entity' => isset($cdekData['entity']),
                    'has_statuses' => isset($cdekData['statuses']),
                    'has_entity_statuses' => isset($cdekData['entity']['statuses']),
                    'has_entity_tariff_code' => isset($cdekData['entity']['tariff_code']),
                    'has_tariff_code' => isset($cdekData['tariff_code']),
                ]
            ]);
            
            // Извлекаем статус доставки
            $deliveryStatus = [];
            $tariffCode = null;
            $tariffName = null;
            
            // Извлекаем тариф из ответа (проверяем разные возможные места)
            if (isset($cdekData['entity']['tariff_code'])) {
                $tariffCode = $cdekData['entity']['tariff_code'];
            } elseif (isset($cdekData['tariff_code'])) {
                $tariffCode = $cdekData['tariff_code'];
            }
            
            Log::info('CDEK tariff extracted', [
                'order_id' => $order->id,
                'tariff_code' => $tariffCode
            ]);
            
            // Получаем название тарифа из настроек СДЭК
            if ($tariffCode) {
                $cdekSettings = \App\Models\ShopCdekSettings::getActive();
                if ($cdekSettings && $cdekSettings->tariffs) {
                    $tariffs = is_array($cdekSettings->tariffs) ? $cdekSettings->tariffs : json_decode($cdekSettings->tariffs, true);
                    if (is_array($tariffs)) {
                        foreach ($tariffs as $tariff) {
                            if (isset($tariff['tariff_code']) && $tariff['tariff_code'] == $tariffCode) {
                                $tariffName = $tariff['name'] ?? null;
                                break;
                            }
                        }
                    }
                }
            }
            
            // Извлекаем статус доставки (проверяем разные возможные места в ответе)
            if (isset($cdekData['entity']['statuses']) && is_array($cdekData['entity']['statuses']) && count($cdekData['entity']['statuses']) > 0) {
                // Берем последний статус из entity.statuses (приоритет)
                $lastStatus = end($cdekData['entity']['statuses']);
                $deliveryStatus = [
                    'code' => $lastStatus['code'] ?? null,
                    'name' => $lastStatus['name'] ?? null,
                    'city' => $lastStatus['city'] ?? null,
                ];
            } elseif (isset($cdekData['statuses']) && is_array($cdekData['statuses']) && count($cdekData['statuses']) > 0) {
                // Берем последний статус из корня
                $lastStatus = end($cdekData['statuses']);
                $deliveryStatus = [
                    'code' => $lastStatus['code'] ?? null,
                    'name' => $lastStatus['name'] ?? null,
                    'city' => $lastStatus['city'] ?? null,
                ];
            } elseif (isset($cdekData['entity']['status'])) {
                $deliveryStatus = [
                    'code' => $cdekData['entity']['status'],
                    'name' => $this->getCdekStatusName($cdekData['entity']['status']),
                ];
            } else {
                // Если статус не найден, создаем базовый статус
                $deliveryStatus = [
                    'code' => 'UNKNOWN',
                    'name' => 'Статус не определен',
                ];
            }
            
            // Добавляем тариф в статус доставки
            if ($tariffCode) {
                $deliveryStatus['tariff_code'] = $tariffCode;
                if ($tariffName) {
                    $deliveryStatus['tariff_name'] = $tariffName;
                }
            }

            Log::info('CDEK delivery status before save', [
                'order_id' => $order->id,
                'delivery_status' => $deliveryStatus,
                'tariff_code' => $tariffCode,
                'tariff_name' => $tariffName
            ]);

            // Сохраняем статус в заказ
            $order->delivery_status = json_encode($deliveryStatus, JSON_UNESCAPED_UNICODE);
            $order->save();
            
            // Обновляем заказ из БД для получения актуальных данных
            $order->refresh();
            
            // Проверяем, что данные сохранились
            $savedStatus = json_decode($order->delivery_status, true);

            Log::info('CDEK delivery status saved', [
                'order_id' => $order->id,
                'delivery_status' => $deliveryStatus,
                'saved_status' => $savedStatus,
                'has_tariff_code' => isset($savedStatus['tariff_code']),
                'has_tariff_name' => isset($savedStatus['tariff_name'])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Статус доставки обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'delivery_status' => $deliveryStatus,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления статуса доставки: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса доставки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить название статуса СДЭК по коду
     */
    private function getCdekStatusName($code)
    {
        $statuses = [
            'ACCEPTED' => 'Принят',
            'CREATED' => 'Создан',
            'RECEIVED_AT_SHIPMENT_WAREHOUSE' => 'Принят на склад отправителя',
            'READY_TO_SHIP_AT_SENDING_OFFICE' => 'Выдан на отправку в г. отправителе',
            'READY_FOR_SHIPMENT_IN_SENDER_CITY' => 'Готов к отправке в г. отправителе',
            'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => 'Сдан перевозчику в г. отправителе',
            'SENT_TO_TRANSIT_CITY' => 'Отправлен в г. транзит',
            'ACCEPTED_IN_TRANSIT_CITY' => 'Встречен в г. транзите',
            'SENT_TO_RECIPIENT_CITY' => 'Отправлен в г. получатель',
            'ACCEPTED_IN_RECIPIENT_CITY' => 'Встречен в г. получателе',
            'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE' => 'Принят на склад доставки',
            'ACCEPTED_AT_PICK_UP_POINT' => 'Принят на склад до востребования',
            'TAKEN_BY_COURIER' => 'Выдан на доставку',
            'DELIVERED' => 'Вручен',
            'NOT_DELIVERED' => 'Не вручен',
            'INVALID' => 'Некорректный заказ',
        ];

        return $statuses[$code] ?? $code;
    }

    /**
     * Завершить заказ (установить статус с is_finished=1)
     */
    public function finishOrder($id): JsonResponse
    {
        try {
            $order = ShopOrder::with(['status'])->findOrFail($id);
            
            // Проверяем, что заказ оплачен
            if (!$order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ не может быть завершен, так как он не оплачен'
                ], 422);
            }
            
            // Находим статус с is_finished=1
            $finishedStatus = ShopOrderStatus::where('is_finished', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
            
            if (!$finishedStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не найден статус для завершенных заказов'
                ], 404);
            }
            
            // Обновляем статус заказа
            $order->status_id = $finishedStatus->id;
            $order->save();
            
            // Загружаем обновленные связи
            $order->load(['status']);
            
            return response()->json([
                'success' => true,
                'message' => 'Заказ успешно завершен',
                'data' => [
                    'id' => $order->id,
                    'status_id' => $order->status_id,
                    'status' => $order->status->name,
                    'status_display' => $order->status->display_name,
                    'status_color' => $order->status->color,
                    'status_is_finished' => (bool) $order->status->is_finished,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка завершения заказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Добавить товар в заказ
     */
    public function addItem(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::with(['status'])->findOrFail($id);
            
            // Проверяем, что заказ не оплачен
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя изменять оплаченный заказ'
                ], 422);
            }
            
            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer|exists:shop_goods,id',
                'quantity' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodId = $request->get('good_id');
            $quantity = $request->get('quantity');
            
            // Получаем товар
            $good = \App\Models\ShopGood::findOrFail($goodId);
            
            // Получаем текущие товары заказа
            $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
            $items = $items ?: [];
            
            // Проверяем, есть ли уже этот товар в заказе
            $existingItemIndex = null;
            foreach ($items as $index => $item) {
                if (($item['good_id'] ?? null) == $goodId && !isset($item['variation_id'])) {
                    $existingItemIndex = $index;
                    break;
                }
            }
            
            $price = $good->sale_price ?? $good->price ?? 0;
            
            if ($existingItemIndex !== null) {
                // Увеличиваем количество существующего товара
                $items[$existingItemIndex]['quantity'] = ($items[$existingItemIndex]['quantity'] ?? 1) + $quantity;
                $items[$existingItemIndex]['total'] = $items[$existingItemIndex]['quantity'] * $price;
            } else {
                // Добавляем новый товар
                $items[] = [
                    'good_id' => $goodId,
                    'good_name' => $good->name,
                    'good_slug' => $good->slug ?? null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $price * $quantity
                ];
            }
            
            // Пересчитываем суммы заказа
            $subtotal = 0;
            $totalQuantity = 0;
            foreach ($items as $item) {
                $subtotal += $item['total'] ?? 0;
                $totalQuantity += $item['quantity'] ?? 0;
            }
            
            $order->items = $items;
            $order->subtotal = $subtotal;
            $order->total_quantity = $totalQuantity;
            // Пересчитываем итоговую сумму (учитывая скидки и доставку)
            $order->total_amount = $subtotal - ($order->total_discount_amount ?? 0) + ($order->delivery_cost ?? 0);
            $order->save();
            
            // Загружаем обновленный заказ
            $order->load(['status', 'user', 'paymentMethod', 'deliveryMethod']);
            
            return response()->json([
                'success' => true,
                'message' => 'Товар добавлен в заказ',
                'data' => $this->formatOrderForResponse($order)
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ или товар не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить товар из заказа
     */
    public function removeItem($orderId, $itemId): JsonResponse
    {
        try {
            $order = ShopOrder::with(['status'])->findOrFail($orderId);
            
            // Проверяем, что заказ не оплачен
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя изменять оплаченный заказ'
                ], 422);
            }
            
            // Получаем текущие товары заказа
            $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
            $items = $items ?: [];
            
            // Находим и удаляем товар
            $itemFound = false;
            foreach ($items as $index => $item) {
                if (($item['good_id'] ?? null) == $itemId || ($item['id'] ?? null) == $itemId) {
                    unset($items[$index]);
                    $itemFound = true;
                    break;
                }
            }
            
            if (!$itemFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в заказе'
                ], 404);
            }
            
            // Переиндексируем массив
            $items = array_values($items);
            
            // Пересчитываем суммы заказа
            $subtotal = 0;
            $totalQuantity = 0;
            foreach ($items as $item) {
                $subtotal += $item['total'] ?? 0;
                $totalQuantity += $item['quantity'] ?? 0;
            }
            
            $order->items = $items;
            $order->subtotal = $subtotal;
            $order->total_quantity = $totalQuantity;
            // Пересчитываем итоговую сумму
            $order->total_amount = $subtotal - ($order->total_discount_amount ?? 0) + ($order->delivery_cost ?? 0);
            $order->save();
            
            // Загружаем обновленный заказ
            $order->load(['status', 'user', 'paymentMethod', 'deliveryMethod']);
            
            return response()->json([
                'success' => true,
                'message' => 'Товар удален из заказа',
                'data' => $this->formatOrderForResponse($order)
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Форматировать заказ для ответа
     */
    private function formatOrderForResponse($order)
    {
        $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
        $items = $items ?: [];
        
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'status' => $order->status ? $order->status->name : 'pending',
            'status_id' => $order->status_id,
            'status_display' => $order->status ? $order->status->display_name : 'Неизвестно',
            'status_color' => $order->status ? $order->status->color : '#6B7280',
            'status_is_finished' => $order->status ? (bool) $order->status->is_finished : false,
            'status_is_cancelled' => $order->status ? (bool) $order->status->is_cancelled : false,
            'payed' => (bool) $order->payed,
            'is_active' => (bool) ($order->is_active ?? false),
            'delivery_status_id' => $order->delivery_status_id,
            'delivery_status' => $order->deliveryStatus ? [
                'id' => $order->deliveryStatus->id,
                'name' => $order->deliveryStatus->name,
                'display_name' => $order->deliveryStatus->display_name,
            ] : null,
            'total_amount' => (float) $order->total_amount,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'sale_discount_amount' => (float) ($order->sale_discount_amount ?? 0),
            'registered_user_discount_amount' => (float) ($order->registered_user_discount_amount ?? 0),
            'promo_code_discount_amount' => (float) ($order->promo_code_discount_amount ?? 0),
            'total_discount_amount' => (float) ($order->total_discount_amount ?? 0),
            'delivery_cost' => (float) ($order->delivery_cost ?? 0),
            'total_quantity' => $order->total_quantity ?? 0,
            'payment_method' => $order->payment_method,
            'payment_method_id' => $order->payment_method_id,
            'shipping_method' => $order->shipping_method,
            'shipping_address' => $order->shipping_address,
            'cdek_order_uuid' => $order->cdek_order_uuid,
            'delivery_status' => $order->delivery_status ? json_decode($order->delivery_status, true) : null,
            'notes' => $order->notes,
            'comment' => $order->comment,
            'promo_code' => $order->promo_code,
            'promo_code_id' => $order->promo_code_id,
            'use_bonus_points' => $order->use_bonus_points ?? false,
            'bonus_points_to_use' => $order->bonus_points_to_use ?? 0,
            'order_bonus_points' => $order->order_bonus_points ?? 0,
            'user_bonus_points' => $order->user_id ? (\App\Models\UserBonus::where('user_id', $order->user_id)->value('points') ?? 0) : 0,
            'items_count' => count($items),
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
            'items' => $order->getItemsWithDetails(),
            'metadata' => $order->metadata,
        ];
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

    /**
     * Получить штрихкод для заказа СДЭК
     */
    public function getCdekBarcode(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            
            // Проверяем, что заказ использует СДЭК
            if (!$order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК'
                ], 400);
            }

            $cdekService = new CdekService();
            $result = $cdekService->getBarcode(
                $order->cdek_order_uuid,
                $request->get('copy_count', 1),
                $request->get('format', 'A4'),
                $request->get('lang', 'RUS')
            );

            if ($result['success']) {
                // Возвращаем URL прокси вместо прямого URL СДЭК
                $proxyUrl = url('/api/admin/shop/orders/' . $id . '/cdek/barcode/download');
                return response()->json([
                    'success' => true,
                    'url' => $proxyUrl,
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка получения штрихкода СДЭК: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения штрихкода: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать штрихкод для заказа СДЭК (прокси)
     */
    public function downloadCdekBarcode(Request $request, $id)
    {
        try {
            $order = ShopOrder::findOrFail($id);
            
            if (!$order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК'
                ], 400);
            }

            $cdekService = new CdekService();
            $result = $cdekService->getBarcode(
                $order->cdek_order_uuid,
                $request->get('copy_count', 1),
                $request->get('format', 'A4'),
                $request->get('lang', 'RUS')
            );

            if (!$result['success'] || !isset($result['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Ошибка получения штрихкода'
                ], 500);
            }

            // Получаем токен СДЭК
            $token = $cdekService->getAccessToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК'
                ], 500);
            }

            // Скачиваем PDF через прокси
            $response = Http::withOptions([
                'verify' => config('cdek.ssl_verify', false),
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($result['url']);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="cdek-barcode-' . $order->order_number . '.pdf"');
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка скачивания штрихкода из СДЭК'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания штрихкода СДЭК: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания штрихкода: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить накладную для заказа СДЭК
     */
    public function getCdekWaybill(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            
            // Проверяем, что заказ использует СДЭК
            if (!$order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК'
                ], 400);
            }

            $cdekService = new CdekService();
            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if ($result['success']) {
                // Возвращаем URL прокси вместо прямого URL СДЭК
                $proxyUrl = url('/api/admin/shop/orders/' . $id . '/cdek/waybill/download');
                return response()->json([
                    'success' => true,
                    'url' => $proxyUrl,
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка получения накладной СДЭК: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения накладной: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать накладную для заказа СДЭК (прокси)
     */
    public function downloadCdekWaybill(Request $request, $id)
    {
        try {
            $order = ShopOrder::findOrFail($id);
            
            if (!$order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК'
                ], 400);
            }

            $cdekService = new CdekService();
            
            // Получаем токен СДЭК заранее
            $token = $cdekService->getAccessToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК'
                ], 500);
            }

            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if (!$result['success'] || !isset($result['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Ошибка получения накладной'
                ], 500);
            }

            // Скачиваем PDF через прокси
            $response = Http::withOptions([
                'verify' => config('cdek.ssl_verify', false),
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($result['url']);

            if ($response->successful()) {
                // Проверяем, что ответ действительно PDF
                $contentType = $response->header('Content-Type');
                if (strpos($contentType, 'application/pdf') !== false || strpos($contentType, 'application/octet-stream') !== false) {
                    return response($response->body(), 200)
                        ->header('Content-Type', 'application/pdf')
                        ->header('Content-Disposition', 'inline; filename="cdek-waybill-' . $order->order_number . '.pdf"');
                } else {
                    // Если ответ не PDF, возвращаем ошибку
                    $responseBody = $response->body();
                    $errorData = json_decode($responseBody, true);
                    $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Ошибка скачивания накладной из СДЭК';
                    
                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage
                    ], 500);
                }
            } else {
                $statusCode = $response->status();
                $responseBody = $response->body();
                
                // Если 404 - накладная еще не сгенерирована
                if ($statusCode === 404) {
                    // Получаем информацию о заказе для проверки статуса
                    $orderStatus = $cdekService->getOrderStatus($order->cdek_order_uuid);
                    
                    if ($orderStatus['success']) {
                        $statusData = $orderStatus['data'] ?? [];
                        $orderStatusName = $statusData['entity']['statuses'][0]['name'] ?? 'Неизвестно';
                        
                        return response()->json([
                            'success' => false,
                            'message' => 'Накладная еще не сгенерирована. Статус заказа: ' . $orderStatusName . '. Накладная будет доступна после обработки заказа в СДЭК.'
                        ], 404);
                    }
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Накладная еще не сгенерирована для этого заказа. Попробуйте позже или проверьте статус заказа в личном кабинете СДЭК.'
                    ], 404);
                }
                
                // Пытаемся распарсить как JSON для получения сообщения об ошибке
                $errorData = json_decode($responseBody, true);
                $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Ошибка скачивания накладной из СДЭК (HTTP ' . $statusCode . ')';
                
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания накладной СДЭК: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания накладной: ' . $e->getMessage()
            ], 500);
        }
    }
}
