<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopOrderLogIcon;
use App\Models\ShopOrderStatus;
use App\Models\ShopPaymentTransaction;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use App\Services\CdekService;
use App\Services\TbankPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use App\Services\OrderCalculationService;

class ShopOrdersController extends Controller
{
    protected $calculationService;

    public function __construct(OrderCalculationService $calculationService)
    {
        $this->calculationService = $calculationService;
    }
    /**
     * Получить список заказов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopOrder::with(['status', 'user', 'paymentMethod', 'deliveryMethod']);

            // Сортировка
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            if ($sortBy === 'order_number') {
                $query->orderBy('order_number', $sortOrder);
            } elseif ($sortBy === 'last_log') {
                // Сортировка по дате последнего события в журнале
                $query->leftJoin('shop_order_logs', function ($join) {
                    $join->on('shop_orders.id', '=', 'shop_order_logs.entity_id')
                        ->whereRaw('shop_order_logs.created_at = (SELECT MAX(created_at) FROM shop_order_logs WHERE entity_id = shop_orders.id AND (section = "orders" OR section IS NULL))');
                })
                    ->select('shop_orders.*')
                    ->orderBy('shop_order_logs.created_at', $sortOrder);
            } else {
                $query->orderBy('created_at', $sortOrder);
            }

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
                    $regularStatuses = array_filter($statuses, function ($status) {
                        return $status !== 'not_finished';
                    });

                    $query->where(function ($q) use ($hasNotFinished, $regularStatuses) {
                        // Если выбрано "НЕ ЗАВЕРШЕННЫЕ", фильтруем:
                        // 1. Заказы с is_finished=false и is_cancelled=false
                        // 2. Заказы с заявкой на отмену (cancellation_request=true)
                        // 3. Отмененные заказы, которые оплачены (is_cancelled=true && payed=true)
                        if ($hasNotFinished) {
                            $q->where(function ($subQ) {
                                // Обычные незавершенные заказы
                                $subQ->whereHas('status', function ($statusQ) {
                                    $statusQ->where('is_finished', false)
                                        ->where('is_cancelled', false);
                                })
                                // ИЛИ заказы с заявкой на отмену
                                    ->orWhere('cancellation_request', true)
                                // ИЛИ отмененные оплаченные заказы
                                    ->orWhere(function ($cancelQ) {
                                        $cancelQ->whereHas('status', function ($statusQ) {
                                            $statusQ->where('is_cancelled', true);
                                        })->where('payed', true);
                                    });
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
                } elseif (! is_array($statuses)) {
                    // Если передан один статус (для обратной совместимости)
                    if ($statuses === 'not_finished') {
                        // Фильтруем:
                        // 1. Заказы с is_finished=false и is_cancelled=false
                        // 2. Заказы с заявкой на отмену (cancellation_request=true)
                        // 3. Отмененные заказы, которые оплачены (is_cancelled=true && payed=true)
                        $query->where(function ($q) {
                            // Обычные незавершенные заказы
                            $q->whereHas('status', function ($statusQ) {
                                $statusQ->where('is_finished', false)
                                    ->where('is_cancelled', false);
                            })
                            // ИЛИ заказы с заявкой на отмену
                                ->orWhere('cancellation_request', true)
                            // ИЛИ отмененные оплаченные заказы
                                ->orWhere(function ($cancelQ) {
                                    $cancelQ->whereHas('status', function ($statusQ) {
                                        $statusQ->where('is_cancelled', true);
                                    })->where('payed', true);
                                });
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
                } elseif (! is_array($paymentTypes)) {
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
                } elseif (! is_array($deliveryMethodIds)) {
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

            // Фильтрация "Заявка на отмену" (заказы с cancellation_request=true)
            if ($request->filled('cancellation_request') && $request->get('cancellation_request') == '1') {
                $query->where('cancellation_request', true);
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
                    'pay_agree' => (bool) ($order->pay_agree ?? false),
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
                    'birthday_discount_amount' => (float) ($order->birthday_discount_amount ?? 0),
                    'total_discount_amount' => (float) ($order->total_discount_amount ?? 0),
                    'delivery_cost' => (float) ($order->delivery_cost ?? 0),
                    'payment_method' => $order->payment_method,
                    'payment_url' => $order->payment_url,
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
                    'cancellation_request' => (bool) ($order->cancellation_request ?? false),
                    'items_count' => count($items),
                    'total_quantity' => $order->total_quantity ?? 0,
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString(),
                    'items' => $order->getItemsWithDetails(),
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
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказов: '.$e->getMessage(),
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
                    'bonus_points_after_restore' => max(0, $currentBonusPoints - $bonusPointsToUse), // Для восстановления - вычитаем
                ];
            } else {
                $formattedOrder['user_info'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $formattedOrder,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказа: '.$e->getMessage(),
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
                'errors' => $validator->errors(),
            ], 422);
        }

        // Здесь будет логика создания заказа
        // Пока возвращаем успешный ответ

        return response()->json([
            'success' => true,
            'message' => 'Заказ создан успешно',
            'data' => [
                'id' => rand(100, 999),
                'order_number' => 'ORD-'.str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
            ],
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
                'shipping_method' => 'sometimes|string|max:100',
                'shipping_address' => 'sometimes|string',
                'delivery_cost' => 'sometimes|numeric',
                'metadata' => 'sometimes|array',
                'notes' => 'sometimes|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
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
            if ($request->filled('shipping_method')) {
                $order->shipping_method = $request->get('shipping_method');
            }
            if ($request->filled('shipping_address')) {
                $order->shipping_address = $request->get('shipping_address');
            }
            if ($request->filled('delivery_cost')) {
                $order->delivery_cost = (float) $request->get('delivery_cost');
            }
            if ($request->has('metadata') && is_array($request->get('metadata'))) {
                $metadata = $order->metadata ?? [];
                $newMetadata = $request->get('metadata');
                $order->metadata = array_merge($metadata, $newMetadata);
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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления заказа: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить заказ
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);

            // Логгируем удаление перед удалением записи
            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            ShopOrderLog::logOrderDeleted(
                $order->id,
                $user ? $user->id : null,
                $userName,
                $order->order_number
            );

            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Заказ удален успешно',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления заказа: '.$e->getMessage(),
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
                'data' => $statuses,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статусов: '.$e->getMessage(),
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
                'is_restore' => 'sometimes|boolean',
                'comment' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            $statusId = $request->get('status_id');
            $newStatus = ShopOrderStatus::findOrFail($statusId);
            $oldStatus = $order->status;

            // Проверка на завершенный статус для неоплаченного заказа
            if ($newStatus->is_finished && ! $order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя установить завершенный статус для неоплаченного заказа',
                ], 422);
            }

            // Если новый статус - отмена заказа, возвращаем товары на склад и бонусы пользователю
            if ($newStatus->is_cancelled) {
                $this->restoreOrderItemsToStock($order);
                $this->restoreUserBonuses($order);
                // Снимаем отметку о заявке на отмену при установке статуса отмены
                if ($order->cancellation_request) {
                    $order->cancellation_request = false;
                }
            }

            // Если старый статус был отмененным и выбирается другой статус (восстановление), вычитаем товары и бонусы
            if ($oldStatus && $oldStatus->is_cancelled && ! $newStatus->is_cancelled && $request->get('is_restore', false)) {
                $this->deductOrderItemsFromStock($order);
                $this->deductUserBonuses($order);
            }

            $order->status_id = $statusId;
            $order->save();

            // Логируем смену статуса
            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            $oldStatusName = $oldStatus ? $oldStatus->display_name : 'Не установлен';
            $oldStatusColor = $oldStatus ? $oldStatus->color : '#6B7280';
            $newStatusName = $newStatus->display_name;
            $newStatusColor = $newStatus->color;

            // Формируем HTML-строку для действия с цветами
            $action = "<span style=\"color:{$oldStatusColor}\">{$oldStatusName}</span> → <span style=\"color:{$newStatusColor}\">{$newStatusName}</span>";

            ShopOrderLog::create([
                'entity_id' => $order->id,
                'action' => $action,
                'comment' => $request->get('comment'),
                'user_id' => $user ? $user->id : null,
                'user_name' => $userName,
                'section' => ShopOrderLog::SECTION_ORDERS,
                'info' => "Заказ № {$order->order_number}",
            ]);

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
                    'cancellation_request' => (bool) ($order->cancellation_request ?? false),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса: '.$e->getMessage(),
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

            if (! is_array($items) || empty($items)) {
                Log::info('Заказ не содержит товаров для вычитания со склада', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (! $goodId || $quantity <= 0) {
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
                            'new_quantity' => $newQuantity,
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
                            'new_quantity' => $newQuantity,
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при вычитании товаров со склада: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Возврат использованных бонусов пользователю при отмене заказа
     */
    private function restoreUserBonuses(ShopOrder $order): void
    {
        try {
            if (! $order->user_id || ! $order->use_bonus_points || ! $order->bonus_points_to_use || $order->bonus_points_to_use <= 0) {
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
                    'action' => 'cancel_order_refund',
                ],
            ]);

            Log::info('Бонусы возвращены пользователю при отмене заказа', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'bonus_points_returned' => $bonusPoints,
                'new_balance' => $userBonus->points,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате бонусов пользователю: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Списывание бонусов у пользователя при восстановлении заказа
     */
    private function deductUserBonuses(ShopOrder $order): void
    {
        try {
            if (! $order->user_id || ! $order->use_bonus_points || ! $order->bonus_points_to_use || $order->bonus_points_to_use <= 0) {
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
                    'available' => $userBonus->points,
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
                        'action' => 'restore_order_deduction',
                    ],
                ]);

                Log::info('Бонусы списаны у пользователя при восстановлении заказа', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'bonus_points_deducted' => $bonusPoints,
                    'new_balance' => $userBonus->points,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при списании бонусов у пользователя: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
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

            if (! is_array($items) || empty($items)) {
                Log::info('Заказ не содержит товаров для возврата на склад', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (! $goodId || $quantity <= 0) {
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
                            'new_quantity' => $variation->stock_quantity,
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
                            'new_quantity' => $good->stock_quantity,
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате товаров на склад: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
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
                'bonus_points' => 'nullable|integer|min:0',
                'comment' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
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
                    ],
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
                            'message' => 'Недостаточно бонусных баллов для списания. У пользователя: '.$userBonus->points.', требуется: '.$bonusPoints,
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
                            'metadata' => ['original_transaction_id' => $earnTransaction->id],
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
                                'message' => 'Ошибка списания бонусов: '.$e->getMessage(),
                            ], 422);
                        }
                    }
                }
            }

            // Обновляем статус оплаты
            $order->payed = $newPayedStatus;
            $order->save();

            // Логируем смену статуса оплаты
            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            ShopOrderLog::logPaymentStatusChange(
                $order->id,
                $newPayedStatus,
                $user ? $user->id : null,
                $userName,
                $request->get('comment'),
                ShopOrderLog::SECTION_ORDERS,
                $order->order_number
            );

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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса оплаты: '.$e->getMessage(),
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
                'is_active' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса активности: '.$e->getMessage(),
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
                'comment' => 'nullable|string|max:5000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);

            // Проверяем, что комментарий можно изменить только если он пустой
            if (! empty($order->comment) && $request->filled('comment')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Комментарий можно изменить только если он пустой',
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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления комментария: '.$e->getMessage(),
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
            if (! $order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            // Получаем статус из СДЭК
            $cdekService = new CdekService;
            $statusResult = $cdekService->getOrderStatus($order->cdek_order_uuid);

            if (! $statusResult['success']) {
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
                        'is_not_found' => $isNotFound,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Статус доставки обновлен: заказ не найден в СДЭК',
                        'data' => [
                            'id' => $order->id,
                            'delivery_status' => $deliveryStatus,
                        ],
                    ]);
                }

                // Для других ошибок (например, проблемы с настройками) возвращаем ошибку
                return response()->json([
                    'success' => false,
                    'message' => $statusResult['message'] ?? 'Ошибка получения статуса из СДЭК',
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
                ],
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
                'tariff_code' => $tariffCode,
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
                'tariff_name' => $tariffName,
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
                'has_tariff_name' => isset($savedStatus['tariff_name']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Статус доставки обновлен успешно',
                'data' => [
                    'id' => $order->id,
                    'delivery_status' => $deliveryStatus,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления статуса доставки: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса доставки: '.$e->getMessage(),
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
            if (! $order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ не может быть завершен, так как он не оплачен',
                ], 422);
            }

            // Находим статус с is_finished=1
            $finishedStatus = ShopOrderStatus::where('is_finished', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();

            // Если не найден по is_finished, пытаемся найти по имени 'finished'
            if (! $finishedStatus) {
                $finishedStatus = ShopOrderStatus::where('name', 'finished')
                    ->where('is_active', true)
                    ->first();
            }

            // Если все еще не найден, пытаемся найти по display_name 'Завершен'
            if (! $finishedStatus) {
                $finishedStatus = ShopOrderStatus::where('display_name', 'Завершен')
                    ->where('is_active', true)
                    ->first();
            }

            if (! $finishedStatus) {
                // Логируем для отладки
                \Log::warning('Не найден статус для завершенных заказов', [
                    'order_id' => $id,
                    'available_statuses' => ShopOrderStatus::where('is_active', true)->pluck('name', 'id')->toArray(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Не найден статус для завершенных заказов. Пожалуйста, убедитесь, что в системе есть активный статус с is_finished=true или name="finished"',
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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка завершения заказа: '.$e->getMessage(),
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
                    'message' => 'Нельзя изменять оплаченный заказ',
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer|exists:shop_goods,id',
                'variation_id' => 'nullable|integer|exists:shop_good_variations,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            // Получаем товар
            $good = \App\Models\ShopGood::findOrFail($goodId);
            $variation = null;
            $variationName = '';
            $price = $good->sale_price ?? $good->price ?? 0;

            if ($variationId) {
                $variation = \App\Models\ShopGoodVariation::where('id', $variationId)->where('good_id', $goodId)->first();
                if ($variation) {
                    $price = $variation->sale_price > 0 ? $variation->sale_price : ($variation->price > 0 ? $variation->price : $price);
                    $variationName = $this->formatVariationProperties($variation);
                }
            }

            // Получаем текущие товары заказа
            $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
            $items = $items ?: [];

            // Проверяем, есть ли уже этот товар в заказе
            $existingItemIndex = null;
            foreach ($items as $index => $item) {
                if (($item['good_id'] ?? null) == $goodId && ($item['variation_id'] ?? null) == $variationId) {
                    $existingItemIndex = $index;
                    break;
                }
            }

            if ($existingItemIndex !== null) {
                // Увеличиваем количество существующего товара
                $items[$existingItemIndex]['quantity'] = ($items[$existingItemIndex]['quantity'] ?? 1) + $quantity;
                $items[$existingItemIndex]['total'] = $items[$existingItemIndex]['quantity'] * $price;
            } else {
                // Добавляем новый товар
                $newItem = [
                    'good_id' => $goodId,
                    'good_name' => $good->name,
                    'good_slug' => $good->slug ?? null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $price * $quantity,
                ];

                if ($variation) {
                    $newItem['variation_id'] = $variation->id;
                    $newItem['variation_name'] = $variationName;
                    $newItem['variation_sku'] = $variation->sku;
                }

                $items[] = $newItem;
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
                'data' => $this->formatOrderForResponse($order),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ или товар не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления товара: '.$e->getMessage(),
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
                    'message' => 'Нельзя изменять оплаченный заказ',
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

            if (! $itemFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в заказе',
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
                'data' => $this->formatOrderForResponse($order),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара: '.$e->getMessage(),
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
            'pay_agree' => (bool) ($order->pay_agree ?? false),
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
            'birthday_discount_amount' => (float) ($order->birthday_discount_amount ?? 0),
            'total_discount_amount' => (float) ($order->total_discount_amount ?? 0),
            'delivery_cost' => (float) ($order->delivery_cost ?? 0),
            'total_quantity' => $order->total_quantity ?? 0,
            'payment_method' => $order->payment_method,
            'payment_method_id' => $order->payment_method_id,
            'shipping_method' => $order->shipping_method,
            'shipping_address' => $order->shipping_address,
            'cdek_order_uuid' => $order->cdek_order_uuid,
            'delivery_tracking_data' => is_string($order->delivery_status) ? json_decode($order->delivery_status, true) : $order->delivery_status,
            'notes' => $order->notes,
            'comment' => $order->comment,
            'cancellation_request' => (bool) ($order->cancellation_request ?? false),
            'promo_code' => $order->promo_code,
            'promo_code_id' => $order->promo_code_id,
            'use_bonus_points' => $order->use_bonus_points ?? false,
            'bonus_points_to_use' => $order->bonus_points_to_use ?? 0,
            'order_bonus_points' => $order->order_bonus_points ?? 0,
            'user_bonus_points' => $order->user_id ? (\App\Models\UserBonus::where('user_id', $order->user_id)->value('points') ?? 0) : 0,
            'items_count' => count($items),
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
            'items' => array_map(function($item) use ($order) {
                // Пытаемся рассчитать финальную цену через сервис, если это возможно
                try {
                    $good = ShopGood::find($item['good_id']);
                    $variation = isset($item['variation_id']) ? ShopGoodVariation::find($item['variation_id']) : null;
                    $user = $order->user;
                    
                    if ($good) {
                        $calc = $this->calculationService->calculateFinalUnitPrice($good, $variation, $user);
                        $item['calculated_price'] = $calc['final_price'];
                        $item['is_calculated'] = true;
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки расчета для старых заказов
                }
                return $item;
            }, $order->getItemsWithDetails()),
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
            'message' => 'Экспорт заказов будет реализован позже',
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
            if (! $order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;
            $result = $cdekService->getBarcode(
                $order->cdek_order_uuid,
                $request->get('copy_count', 1),
                $request->get('format', 'A4'),
                $request->get('lang', 'RUS')
            );

            if ($result['success']) {
                // Возвращаем URL прокси вместо прямого URL СДЭК
                $proxyUrl = url('/api/admin/shop/orders/'.$id.'/cdek/barcode/download');

                return response()->json([
                    'success' => true,
                    'url' => $proxyUrl,
                    'message' => $result['message'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка получения штрихкода СДЭК: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения штрихкода: '.$e->getMessage(),
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

            if (! $order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;
            $result = $cdekService->getBarcode(
                $order->cdek_order_uuid,
                $request->get('copy_count', 1),
                $request->get('format', 'A4'),
                $request->get('lang', 'RUS')
            );

            if (! $result['success'] || ! isset($result['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Ошибка получения штрихкода',
                ], 500);
            }

            // Получаем токен СДЭК
            $token = $cdekService->getAccessToken();
            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ], 500);
            }

            // Скачиваем PDF через прокси
            $response = Http::withOptions([
                'verify' => config('cdek.ssl_verify', false),
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($result['url']);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="cdek-barcode-'.$order->order_number.'.pdf"');
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка скачивания штрихкода из СДЭК',
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания штрихкода СДЭК: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания штрихкода: '.$e->getMessage(),
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
            if (! $order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;
            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if ($result['success']) {
                // Возвращаем URL прокси вместо прямого URL СДЭК
                $proxyUrl = url('/api/admin/shop/orders/'.$id.'/cdek/waybill/download');

                return response()->json([
                    'success' => true,
                    'url' => $proxyUrl,
                    'message' => $result['message'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка получения накладной СДЭК: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения накладной: '.$e->getMessage(),
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

            if (! $order->cdek_order_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;

            // Получаем токен СДЭК заранее
            $token = $cdekService->getAccessToken();
            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ], 500);
            }

            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if (! $result['success'] || ! isset($result['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Ошибка получения накладной',
                ], 500);
            }

            // Скачиваем PDF через прокси
            $response = Http::withOptions([
                'verify' => config('cdek.ssl_verify', false),
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($result['url']);

            if ($response->successful()) {
                // Проверяем, что ответ действительно PDF
                $contentType = $response->header('Content-Type');
                if (strpos($contentType, 'application/pdf') !== false || strpos($contentType, 'application/octet-stream') !== false) {
                    return response($response->body(), 200)
                        ->header('Content-Type', 'application/pdf')
                        ->header('Content-Disposition', 'inline; filename="cdek-waybill-'.$order->order_number.'.pdf"');
                } else {
                    // Если ответ не PDF, возвращаем ошибку
                    $responseBody = $response->body();
                    $errorData = json_decode($responseBody, true);
                    $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Ошибка скачивания накладной из СДЭК';

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
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
                            'message' => 'Накладная еще не сгенерирована. Статус заказа: '.$orderStatusName.'. Накладная будет доступна после обработки заказа в СДЭК.',
                        ], 404);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Накладная еще не сгенерирована для этого заказа. Попробуйте позже или проверьте статус заказа в личном кабинете СДЭК.',
                    ], 404);
                }

                // Пытаемся распарсить как JSON для получения сообщения об ошибке
                $errorData = json_decode($responseBody, true);
                $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Ошибка скачивания накладной из СДЭК (HTTP '.$statusCode.')';

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания накладной СДЭК: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания накладной: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить статистику заказов
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            // Получаем все статусы заказов
            $statuses = ShopOrderStatus::where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            // Подсчитываем заказы по статусам
            $byStatus = [];
            $statusCounts = [];

            foreach ($statuses as $status) {
                $count = ShopOrder::where('status_id', $status->id)->count();
                $byStatus[$status->name] = $count;
                $statusCounts[] = [
                    'id' => $status->id,
                    'name' => $status->name,
                    'display_name' => $status->display_name,
                    'color' => $status->color,
                    'count' => $count,
                ];
            }

            // Статистика по датам (если указаны параметры)
            $dateRange = null;
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $dateFrom = $request->get('date_from');
                $dateTo = $request->get('date_to');

                $baseQuery = ShopOrder::whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);

                $totalOrders = $baseQuery->count();
                $totalRevenue = $baseQuery->sum('total_amount');

                // Оплаченные заказы
                $paidOrders = ShopOrder::whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
                    ->where('payed', true)
                    ->count();
                $paidRevenue = ShopOrder::whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
                    ->where('payed', true)
                    ->sum('total_amount');

                // Неоплаченные заказы: payed = 0, false или NULL
                $unpaidOrders = ShopOrder::whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
                    ->where(function ($q) {
                        $q->where('payed', false)
                            ->orWhere('payed', 0)
                            ->orWhereNull('payed');
                    })
                    ->count();
                $unpaidRevenue = ShopOrder::whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
                    ->where(function ($q) {
                        $q->where('payed', false)
                            ->orWhere('payed', 0)
                            ->orWhereNull('payed');
                    })
                    ->sum('total_amount');

                $dateRange = [
                    'totalOrders' => $totalOrders,
                    'totalRevenue' => (float) $totalRevenue,
                    'paidOrders' => $paidOrders,
                    'paidRevenue' => (float) $paidRevenue,
                    'unpaidOrders' => $unpaidOrders,
                    'unpaidRevenue' => (float) $unpaidRevenue,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'byStatus' => $byStatus,
                    'statuses' => $statusCounts,
                    'dateRange' => $dateRange,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения статистики заказов: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить все статусы заказов (включая неактивные) для админки
     */
    public function getAllStatuses(): JsonResponse
    {
        try {
            $statuses = ShopOrderStatus::orderBy('sort_order')
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
                'data' => $statuses,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статусов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый статус заказа
     */
    public function createOrderStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:shop_order_statuses,name',
                'display_name' => 'required|string|max:255',
                'color' => 'nullable|string|max:7',
                'is_active' => 'sometimes|boolean',
                'is_finished' => 'sometimes|boolean',
                'is_cancelled' => 'sometimes|boolean',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // Если is_finished=true, сбрасываем у других
            if ($request->get('is_finished', false)) {
                ShopOrderStatus::where('is_finished', true)->update(['is_finished' => false]);
            }

            // Если is_cancelled=true, сбрасываем у других
            if ($request->get('is_cancelled', false)) {
                ShopOrderStatus::where('is_cancelled', true)->update(['is_cancelled' => false]);
            }

            $maxSortOrder = ShopOrderStatus::max('sort_order') ?? 0;

            $status = ShopOrderStatus::create([
                'name' => $request->get('name'),
                'display_name' => $request->get('display_name'),
                'color' => $request->get('color', '#6B7280'),
                'is_active' => $request->get('is_active', true),
                'is_finished' => $request->get('is_finished', false),
                'is_cancelled' => $request->get('is_cancelled', false),
                'sort_order' => $maxSortOrder + 1,
                'description' => $request->get('description'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Статус успешно создан',
                'data' => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'display_name' => $status->display_name,
                    'color' => $status->color,
                    'is_active' => (bool) $status->is_active,
                    'is_finished' => (bool) $status->is_finished,
                    'is_cancelled' => (bool) $status->is_cancelled,
                    'sort_order' => $status->sort_order,
                    'description' => $status->description,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания статуса: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить статус заказа
     */
    public function updateOrderStatus(Request $request, $id): JsonResponse
    {
        try {
            $status = ShopOrderStatus::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255|unique:shop_order_statuses,name,'.$id,
                'display_name' => 'sometimes|string|max:255',
                'color' => 'nullable|string|max:7',
                'is_active' => 'sometimes|boolean',
                'is_finished' => 'sometimes|boolean',
                'is_cancelled' => 'sometimes|boolean',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // Если is_finished=true, сбрасываем у других
            if ($request->has('is_finished') && $request->get('is_finished')) {
                ShopOrderStatus::where('id', '!=', $id)->where('is_finished', true)->update(['is_finished' => false]);
            }

            // Если is_cancelled=true, сбрасываем у других
            if ($request->has('is_cancelled') && $request->get('is_cancelled')) {
                ShopOrderStatus::where('id', '!=', $id)->where('is_cancelled', true)->update(['is_cancelled' => false]);
            }

            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->get('name');
            }
            if ($request->has('display_name')) {
                $updateData['display_name'] = $request->get('display_name');
            }
            if ($request->has('color')) {
                $updateData['color'] = $request->get('color');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->get('is_active');
            }
            if ($request->has('is_finished')) {
                $updateData['is_finished'] = $request->get('is_finished');
            }
            if ($request->has('is_cancelled')) {
                $updateData['is_cancelled'] = $request->get('is_cancelled');
            }
            if ($request->has('description')) {
                $updateData['description'] = $request->get('description');
            }

            $status->update($updateData);
            $status->refresh();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Статус успешно обновлен',
                'data' => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'display_name' => $status->display_name,
                    'color' => $status->color,
                    'is_active' => (bool) $status->is_active,
                    'is_finished' => (bool) $status->is_finished,
                    'is_cancelled' => (bool) $status->is_cancelled,
                    'sort_order' => $status->sort_order,
                    'description' => $status->description,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить статус заказа
     */
    public function deleteOrderStatus($id): JsonResponse
    {
        try {
            $status = ShopOrderStatus::findOrFail($id);

            // Проверяем, что статус не является завершающим или отменяющим
            if ($status->is_finished) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить статус "Завершен"',
                ], 422);
            }

            if ($status->is_cancelled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить статус "Отменен"',
                ], 422);
            }

            // Проверяем, есть ли заказы с этим статусом
            $ordersCount = ShopOrder::where('status_id', $id)->count();
            if ($ordersCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Нельзя удалить статус, так как с ним связано {$ordersCount} заказ(ов)",
                ], 422);
            }

            $status->delete();

            return response()->json([
                'success' => true,
                'message' => 'Статус успешно удален',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления статуса: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Изменить порядок статусов
     */
    public function reorderStatuses(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'statuses' => 'required|array',
                'statuses.*.id' => 'required|integer|exists:shop_order_statuses,id',
                'statuses.*.sort_order' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            foreach ($request->get('statuses') as $statusData) {
                ShopOrderStatus::where('id', $statusData['id'])
                    ->update(['sort_order' => $statusData['sort_order']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Порядок статусов обновлен',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения порядка: '.$e->getMessage(),
            ], 500);
        }
    }

    // ==================== ORDER LOGS ====================

    /**
     * Получить логи заказа
     */
    public function getOrderLogs($orderId): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($orderId);

            $logs = ShopOrderLog::where('entity_id', $orderId)
                ->where(function ($q) {
                    // Фильтруем только логи заказов (section = orders или null для обратной совместимости)
                    $q->where('section', ShopOrderLog::SECTION_ORDERS)
                        ->orWhereNull('section');
                })
                ->with('actionIcon')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'action_color' => $log->action_color,
                        'action_bg_color' => $log->action_bg_color,
                        'comment' => $log->comment,
                        'user_name' => $log->user_name,
                        'action_icon' => $log->actionIcon ? [
                            'id' => $log->actionIcon->id,
                            'name' => $log->actionIcon->name,
                            'icon' => $log->actionIcon->icon,
                            'color' => $log->actionIcon->color,
                        ] : null,
                        'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'total' => $logs->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения логов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Добавить комментарий/лог к заказу
     */
    public function addOrderLog(Request $request, $orderId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'comment' => 'required|string|max:2000',
                'action_icon_id' => 'nullable|integer|exists:shop_order_log_icons,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = ShopOrder::findOrFail($orderId);

            // Получаем информацию о текущем пользователе (админе)
            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            $log = ShopOrderLog::create([
                'entity_id' => $orderId,
                'action' => 'Комментарий',
                'comment' => $request->get('comment'),
                'action_icon_id' => $request->get('action_icon_id'),
                'user_id' => $user ? $user->id : null,
                'user_name' => $userName,
                'section' => ShopOrderLog::SECTION_ORDERS,
                'info' => "Заказ № {$order->order_number}",
            ]);

            $log->load('actionIcon');

            return response()->json([
                'success' => true,
                'message' => 'Комментарий добавлен',
                'data' => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'action_color' => $log->action_color,
                    'action_bg_color' => $log->action_bg_color,
                    'comment' => $log->comment,
                    'user_name' => $log->user_name,
                    'action_icon' => $log->actionIcon ? [
                        'id' => $log->actionIcon->id,
                        'name' => $log->actionIcon->name,
                        'icon' => $log->actionIcon->icon,
                        'color' => $log->actionIcon->color,
                    ] : null,
                    'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления комментария: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить статистику логов для списка заказов
     */
    public function getOrdersLogsStats(Request $request): JsonResponse
    {
        try {
            $orderIds = $request->get('order_ids', []);

            if (empty($orderIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Получаем базовую статистику
            $stats = ShopOrderLog::whereIn('entity_id', $orderIds)
                ->where(function ($q) {
                    $q->where('section', ShopOrderLog::SECTION_ORDERS)
                        ->orWhereNull('section');
                })
                ->select('entity_id', DB::raw('COUNT(*) as logs_count'), DB::raw('MAX(created_at) as last_log_at'))
                ->groupBy('entity_id')
                ->get()
                ->keyBy('entity_id');

            // Получаем последние записи с иконками для каждого заказа
            $lastLogs = ShopOrderLog::whereIn('entity_id', $orderIds)
                ->where(function ($q) {
                    $q->where('section', ShopOrderLog::SECTION_ORDERS)
                        ->orWhereNull('section');
                })
                ->with('actionIcon')
                ->whereNotNull('action_icon_id')
                ->orderBy('created_at', 'desc')
                ->get()
                ->unique('entity_id')
                ->keyBy('entity_id');

            $result = $stats->map(function ($item) use ($lastLogs) {
                $lastLog = $lastLogs->get($item->entity_id);

                return [
                    'logs_count' => $item->logs_count,
                    'last_log_at' => $item->last_log_at,
                    'last_log_icon' => $lastLog && $lastLog->actionIcon ? [
                        'icon' => $lastLog->actionIcon->icon,
                        'color' => $lastLog->actionIcon->color,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики логов: '.$e->getMessage(),
            ], 500);
        }
    }

    // ==================== LOG ICONS ====================

    /**
     * Получить все иконки действий
     */
    public function getLogIcons(): JsonResponse
    {
        try {
            $icons = ShopOrderLogIcon::orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(function ($icon) {
                    return [
                        'id' => $icon->id,
                        'name' => $icon->name,
                        'icon' => $icon->icon,
                        'color' => $icon->color,
                        'is_active' => (bool) $icon->is_active,
                        'sort_order' => $icon->sort_order,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $icons,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения иконок: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать иконку действия
     */
    public function createLogIcon(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'icon' => 'required|string|max:255',
                'color' => 'required|string|max:7',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $maxSortOrder = ShopOrderLogIcon::max('sort_order') ?? 0;

            $icon = ShopOrderLogIcon::create([
                'name' => $request->get('name'),
                'icon' => $request->get('icon'),
                'color' => $request->get('color'),
                'is_active' => true,
                'sort_order' => $maxSortOrder + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Иконка создана',
                'data' => [
                    'id' => $icon->id,
                    'name' => $icon->name,
                    'icon' => $icon->icon,
                    'color' => $icon->color,
                    'is_active' => (bool) $icon->is_active,
                    'sort_order' => $icon->sort_order,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания иконки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить иконку действия
     */
    public function updateLogIcon(Request $request, $iconId): JsonResponse
    {
        try {
            $icon = ShopOrderLogIcon::findOrFail($iconId);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'icon' => 'sometimes|string|max:255',
                'color' => 'sometimes|string|max:7',
                'is_active' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->get('name');
            }
            if ($request->has('icon')) {
                $updateData['icon'] = $request->get('icon');
            }
            if ($request->has('color')) {
                $updateData['color'] = $request->get('color');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->get('is_active');
            }

            $icon->update($updateData);
            $icon->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Иконка обновлена',
                'data' => [
                    'id' => $icon->id,
                    'name' => $icon->name,
                    'icon' => $icon->icon,
                    'color' => $icon->color,
                    'is_active' => (bool) $icon->is_active,
                    'sort_order' => $icon->sort_order,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления иконки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить иконку действия
     */
    public function deleteLogIcon($iconId): JsonResponse
    {
        try {
            $icon = ShopOrderLogIcon::findOrFail($iconId);

            // Проверяем, используется ли иконка в логах
            $logsCount = ShopOrderLog::where('action_icon_id', $iconId)->count();
            if ($logsCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Нельзя удалить иконку, так как она используется в {$logsCount} записях журнала",
                ], 422);
            }

            $icon->delete();

            return response()->json([
                'success' => true,
                'message' => 'Иконка удалена',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления иконки: '.$e->getMessage(),
            ], 500);
        }
    }

    // ==================== PAY AGREE ====================

    /**
     * Обновить статус разрешения оплаты
     */
    public function updatePayAgree(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'pay_agree' => 'required|boolean',
                'send_email' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = ShopOrder::findOrFail($id);
            $newPayAgree = $request->get('pay_agree');
            $sendEmail = $request->get('send_email', false);

            $order->pay_agree = $newPayAgree;
            $order->save();

            // Логируем изменение
            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            $action = $newPayAgree ? 'Оплата разрешена' : 'Оплата запрещена';
            ShopOrderLog::createLog($order->id, $action, [
                'action_color' => $newPayAgree ? '#7C3AED' : '#6B7280', // purple or gray
                'user_id' => $user ? $user->id : null,
                'user_name' => $userName,
                'section' => ShopOrderLog::SECTION_ORDERS,
                'info' => "Заказ № {$order->order_number}",
            ]);

            // Отправляем письмо, если оплата разрешена и запрошена отправка
            if ($newPayAgree && $sendEmail) {
                try {
                    // Загружаем заказ с необходимыми отношениями
                    $order->load(['status', 'paymentMethod', 'deliveryMethod']);

                    $contacts = \App\Models\Contact::where('is_main', 1)->first();
                    $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();

                    // Убеждаемся, что items преобразованы в массив
                    if (is_string($order->items)) {
                        $order->items = json_decode($order->items, true);
                    }

                    \Illuminate\Support\Facades\Mail::to($order->customer_email)->send(
                        new \App\Mail\OrderPaymentApprovedMail($order, $contacts, $siteInfo)
                    );

                    Log::info('Payment approved email sent to: '.$order->customer_email);
                } catch (\Exception $e) {
                    Log::error('Ошибка отправки письма о разрешении оплаты: '.$e->getMessage(), [
                        'order_id' => $order->id,
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Не прерываем выполнение, только логируем ошибку
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Статус разрешения оплаты обновлен',
                'data' => [
                    'id' => $order->id,
                    'pay_agree' => (bool) $order->pay_agree,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления: '.$e->getMessage(),
            ], 500);
        }
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * Массовое удаление заказов
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'integer|exists:shop_orders,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orderIds = $request->get('order_ids');

            DB::beginTransaction();

            // Удаляем логи заказов
            ShopOrderLog::whereIn('entity_id', $orderIds)
                ->where(function ($q) {
                    $q->where('section', ShopOrderLog::SECTION_ORDERS)
                        ->orWhereNull('section');
                })
                ->delete();

            // Удаляем заказы
            $deletedCount = ShopOrder::whereIn('id', $orderIds)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Удалено заказов: {$deletedCount}",
                'data' => [
                    'deleted_count' => $deletedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового удаления: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое изменение статуса заказов
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'integer|exists:shop_orders,id',
                'status_id' => 'required|integer|exists:shop_order_statuses,id',
                'comment' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orderIds = $request->get('order_ids');
            $statusId = $request->get('status_id');
            $comment = $request->get('comment');
            $newStatus = ShopOrderStatus::findOrFail($statusId);

            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            DB::beginTransaction();

            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($orderIds as $orderId) {
                $order = ShopOrder::with('status')->find($orderId);
                if (! $order) {
                    continue;
                }

                // Проверка на завершенный статус для неоплаченного заказа
                if ($newStatus->is_finished && ! $order->payed) {
                    $skippedCount++;

                    continue;
                }

                $oldStatus = $order->status;

                // Обработка отмены
                if ($newStatus->is_cancelled) {
                    $this->restoreOrderItemsToStock($order);
                    $this->restoreUserBonuses($order);
                }

                $order->status_id = $statusId;
                $order->save();

                // Логируем смену статуса
                $oldStatusName = $oldStatus ? $oldStatus->display_name : 'Не установлен';
                $oldStatusColor = $oldStatus ? $oldStatus->color : '#6B7280';
                $newStatusName = $newStatus->display_name;
                $newStatusColor = $newStatus->color;

                $action = "<span style=\"color:{$oldStatusColor}\">{$oldStatusName}</span> → <span style=\"color:{$newStatusColor}\">{$newStatusName}</span>";

                ShopOrderLog::create([
                    'entity_id' => $order->id,
                    'action' => $action,
                    'comment' => $comment,
                    'user_id' => $user ? $user->id : null,
                    'user_name' => $userName,
                    'section' => ShopOrderLog::SECTION_ORDERS,
                    'info' => "Заказ № {$order->order_number}",
                ]);

                $updatedCount++;
            }

            DB::commit();

            $message = "Обновлено заказов: {$updatedCount}";
            if ($skippedCount > 0) {
                $message .= ". Пропущено (неоплаченные): {$skippedCount}";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового обновления статуса: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое изменение статуса оплаты
     */
    public function bulkUpdatePayed(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'integer|exists:shop_orders,id',
                'payed' => 'required|boolean',
                'comment' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orderIds = $request->get('order_ids');
            $payed = $request->get('payed');
            $comment = $request->get('comment');

            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            DB::beginTransaction();

            $updatedCount = 0;

            foreach ($orderIds as $orderId) {
                $order = ShopOrder::find($orderId);
                if (! $order || $order->payed === $payed) {
                    continue;
                }

                $order->payed = $payed;
                $order->save();

                // Логируем изменение
                ShopOrderLog::logPaymentStatusChange(
                    $order->id,
                    $payed,
                    $user ? $user->id : null,
                    $userName,
                    $comment,
                    ShopOrderLog::SECTION_ORDERS,
                    $order->order_number
                );

                $updatedCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Обновлено заказов: {$updatedCount}",
                'data' => [
                    'updated_count' => $updatedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового обновления статуса оплаты: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое добавление комментария в журнал
     */
    public function bulkAddLog(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'integer|exists:shop_orders,id',
                'comment' => 'required|string|max:2000',
                'action_icon_id' => 'nullable|integer|exists:shop_order_log_icons,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orderIds = $request->get('order_ids');
            $comment = $request->get('comment');
            $actionIconId = $request->get('action_icon_id');

            $user = $request->user();
            $userName = $user ? $user->name : 'Администратор';

            DB::beginTransaction();

            foreach ($orderIds as $orderId) {
                ShopOrderLog::create([
                    'entity_id' => $orderId,
                    'action' => 'Комментарий',
                    'comment' => $comment,
                    'action_icon_id' => $actionIconId,
                    'user_id' => $user ? $user->id : null,
                    'user_name' => $userName,
                    'section' => ShopOrderLog::SECTION_ORDERS,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Комментарий добавлен к '.count($orderIds).' заказам',
                'data' => [
                    'updated_count' => count($orderIds),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового добавления комментария: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Перегенерация платежной ссылки для заказа
     */
    public function regeneratePaymentLink(Request $request, $orderId): JsonResponse
    {
        try {

            $order = ShopOrder::findOrFail($orderId);

            // Проверяем, что заказ не оплачен
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ уже оплачен',
                ], 400);
            }

            // Проверяем, что у заказа есть способ оплаты
            if (! $order->payment_method_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа не указан способ оплаты',
                ], 400);
            }

            $paymentMethod = $order->paymentMethod;
            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты не найден',
                ], 400);
            }

            // Проверяем, что способ оплаты активен
            if (! $paymentMethod->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты неактивен',
                ], 400);
            }

            // Создаем транзакцию для нового платежа
            $transaction = ShopPaymentTransaction::create([
                'order_id' => $order->id,
                'payment_method_id' => $paymentMethod->id,
                'status' => 'pending',
                'amount' => $order->total_amount,
                'request_data' => [
                    'regenerated' => true,
                    'original_order_id' => $order->id,
                ],
            ]);

            // Формируем данные для создания платежа (имитируем createPayment)
            $paymentData = [
                'payment_method_id' => $paymentMethod->id,
                'amount' => $order->total_amount,
                'order_number' => $order->order_number,
                'return_url' => config('app.frontend_url', 'https://skateandsnow.ru').'/order/'.$order->order_number,
            ];

            // Создаем контроллер платежей для вызова методов создания платежа
            $paymentController = new \App\Http\Controllers\Api\Public\ShopPaymentController;

            // Создаем платеж напрямую через API для получения redirect ссылки
            $result = null;

            switch ($paymentMethod->type) {
                case 'yookassa':
                    $result = $this->regenerateYooKassaPayment($paymentMethod, $transaction, $order);
                    break;
                case 'yandex_pay':
                case 'yandex_split':
                    $result = $this->regenerateYandexPayPayment($paymentMethod, $transaction, $order);
                    break;
                case 'tbank_eacq':
                    $result = $this->regenerateTbankEacqPayment($paymentMethod, $transaction, $order);
                    break;
                case 'tbank_dolyame':
                    $result = $this->regenerateTbankDolyamePayment($paymentMethod, $transaction, $order);
                    break;
                default:
                    \Log::warning('Unsupported payment method for regeneration', ['type' => $paymentMethod->type]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Перегенерация ссылок для этого типа оплаты не поддерживается',
                    ], 400);
            }

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать новый платеж - пустой ответ',
                ], 500);
            }

            // Получаем данные из Response объекта
            $content = $result->getContent();
            $resultData = json_decode($content, true);

            if (! $resultData || ! isset($resultData['success']) || ! $resultData['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать новый платеж',
                ], 500);
            }

            // Отправляем email с новой ссылкой клиенту
            $this->sendNewPaymentLinkEmail($order, $resultData['payment_url'] ?? $resultData['data']['payment_url'] ?? null);

            // Добавляем запись в лог заказа
            \App\Models\ShopOrderLog::create([
                'entity_id' => $order->id,
                'section' => 'orders',
                'action' => 'regenerate_payment_link',
                'user_id' => $request->user()->id ?? null,
                'old_value' => $order->payment_url,
                'new_value' => $resultData['payment_url'] ?? $resultData['data']['payment_url'] ?? null,
                'comment' => 'Перегенерация платежной ссылки',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Платежная ссылка успешно перегенерирована',
                'data' => [
                    'payment_url' => $resultData['payment_url'] ?? $resultData['data']['payment_url'] ?? null,
                    'transaction_id' => $resultData['transaction_id'] ?? $resultData['data']['transaction_id'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            $userMessage = 'Ошибка при создании новой платежной ссылки';
            if (str_contains($e->getMessage(), 'connection') || str_contains($e->getMessage(), 'timeout')) {
                $userMessage = 'Ошибка подключения к платежной системе. Попробуйте позже.';
            } elseif (str_contains($e->getMessage(), 'authentication') || str_contains($e->getMessage(), '401')) {
                $userMessage = 'Ошибка авторизации в платежной системе. Проверьте настройки.';
            }

            return response()->json([
                'success' => false,
                'message' => $userMessage,
            ], 500);
        }
    }

    /**
     * Отправка email с текущей платежной ссылкой клиенту
     */
    public function sendPaymentLinkEmail(Request $request, $orderId): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($orderId);

            // Проверяем, что у заказа есть платежная ссылка
            if (! $order->payment_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет платежной ссылки',
                ], 400);
            }

            // Проверяем, что у клиента есть email
            if (! $order->customer_email) {
                return response()->json([
                    'success' => false,
                    'message' => 'У клиента не указан email',
                ], 400);
            }

            // Проверяем, что заказ не оплачен
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ уже оплачен',
                ], 400);
            }

            // Отправляем email с текущей ссылкой
            $this->sendNewPaymentLinkEmail($order, $order->payment_url);

            // Добавляем запись в лог заказа
            \App\Models\ShopOrderLog::create([
                'entity_id' => $order->id,
                'section' => 'orders',
                'action' => 'send_payment_link_email',
                'user_id' => $request->user()->id ?? null,
                'old_value' => null,
                'new_value' => $order->payment_url,
                'comment' => 'Отправка email с платежной ссылкой клиенту',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email с платежной ссылкой отправлен клиенту',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Перегенерация платежа YooKassa с redirect confirmation
     */
    private function regenerateYooKassaPayment($paymentMethod, $transaction, $order)
    {
        try {
            $settings = $paymentMethod->getApiSettings();

            if (empty($settings['shop_id']) || empty($settings['secret_key'])) {
                \Log::error('YooKassa settings missing for regeneration');

                return response()->json([
                    'success' => false,
                    'message' => 'Не настроены параметры Ю-Касса',
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'test'
                ? 'https://api.yookassa.ru/v3/payments'
                : 'https://api.yookassa.ru/v3/payments';

            $paymentData = [
                'amount' => [
                    'value' => number_format($order->total_amount, 2, '.', ''),
                    'currency' => $settings['currency'] ?? 'RUB',
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => config('app.frontend_url', 'https://skateandsnow.ru').'/order/'.$order->order_number,
                ],
                'description' => 'Заказ №'.$order->order_number,
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'order_number' => $order->order_number,
                    'regenerated' => true,
                ],
            ];

            try {
                $response = \Illuminate\Support\Facades\Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Idempotence-Key' => uniqid('regenerate_', true),
                    ])
                    ->timeout(30) // Увеличиваем timeout до 30 секунд
                    ->retry(2, 100) // Повторяем запрос 2 раза с задержкой 100мс
                    ->withOptions(['verify' => false]) // Отключаем SSL верификацию для локальной разработки
                    ->post($apiUrl, $paymentData);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception('Ошибка подключения к YooKassa API: '.$e->getMessage());
            } catch (\Exception $e) {
                throw $e;
            }

            if ($response->successful()) {
                $responseData = $response->json();
                $yookassaPaymentId = $responseData['id'] ?? null;

                // Получаем payment_url
                $paymentUrl = null;
                if (isset($responseData['confirmation']['confirmation_url'])) {
                    $paymentUrl = $responseData['confirmation']['confirmation_url'];
                }

                // Проверяем, что получили payment_url
                if (! $paymentUrl) {
                    // Обновляем транзакцию как failed
                    $transaction->update([
                        'status' => 'failed',
                        'error_message' => 'YooKassa API не вернул ссылку на оплату',
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Платежная система не вернула ссылку на оплату. Попробуйте позже или обратитесь в поддержку.',
                    ], 500);
                }

                // Обновляем транзакцию
                $transaction->update([
                    'transaction_id' => $yookassaPaymentId,
                    'response_data' => $responseData,
                    'status' => 'pending',
                ]);

                // Обновляем заказ
                $order->update([
                    'yookassa_payment_id' => $yookassaPaymentId,
                    'payment_url' => $paymentUrl,
                ]);

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentUrl,
                    'transaction_id' => $transaction->id,
                    'yookassa_payment_id' => $yookassaPaymentId,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания платежа в YooKassa',
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа YooKassa: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Перегенерация платежа Yandex Pay с redirect confirmation
     */
    private function regenerateYandexPayPayment($paymentMethod, $transaction, $order)
    {
        try {
            $settings = $paymentMethod->getApiSettings();

            if (! $this->validateYandexPaySettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки Yandex Pay',
                ], 400);
            }

            $apiUrl = ($settings['mode'] === 'test' || $settings['mode'] === 'sandbox')
                ? 'https://sandbox.pay.yandex.ru/api/merchant/v1/orders'
                : 'https://pay.yandex.ru/api/merchant/v1/orders';

            $orderData = [
                'orderId' => 'REGENERATE-'.$order->order_number.'-'.time(),
                'currencyCode' => $settings['currency'] ?? 'RUB',
                'amount' => [
                    'value' => number_format($order->total_amount, 2, '.', ''),
                    'currency' => $settings['currency'] ?? 'RUB',
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'ORDER-'.$order->id,
                            'description' => 'Заказ №'.$order->order_number,
                            'quantity' => ['count' => '1.0', 'available' => '1.0'],
                            'amount' => [
                                'value' => number_format($order->total_amount, 2, '.', ''),
                                'currency' => $settings['currency'] ?? 'RUB',
                            ],
                            'total' => number_format($order->total_amount, 2, '.', ''),
                        ],
                    ],
                    'total' => ['amount' => number_format($order->total_amount, 2, '.', '')],
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => config('app.frontend_url', 'https://skateandsnow.ru').'/order/'.$order->order_number,
                ],
                'metadata' => json_encode([
                    'order_id' => $order->id,
                    'transaction_id' => $transaction->id,
                    'regenerated' => true,
                ]),
            ];

            // Для Yandex Pay используем правильные заголовки как в оригинальном методе
            $apiKey = ($settings['mode'] === 'test' || $settings['mode'] === 'sandbox') ? $settings['merchant_id'] : $settings['secret_key'];

            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Api-Key '.$apiKey,
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => uniqid('regenerate_', true),
                    'X-Request-Timeout' => '30000',
                    'X-Request-Attempt' => '0',
                ])
                    ->timeout(30)
                    ->withOptions(['verify' => false]) // Отключаем SSL верификацию для локальной разработки
                    ->post($apiUrl, $orderData);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception('Ошибка подключения к Yandex Pay API: '.$e->getMessage());
            } catch (\Exception $e) {
                throw $e;
            }

            if ($response->successful()) {
                $responseData = $response->json();
                // Yandex Pay возвращает данные в структуре {code, status, data: {...}}
                $yandexOrderId = $responseData['data']['orderId'] ?? $responseData['orderId'] ?? null;
                $paymentUrl = $responseData['data']['paymentUrl'] ?? $responseData['paymentUrl'] ?? null;

                // Проверяем, что получили payment_url
                if (! $paymentUrl) {
                    // Обновляем транзакцию как failed
                    $transaction->update([
                        'status' => 'failed',
                        'error_message' => 'Yandex Pay API не вернул ссылку на оплату',
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Платежная система не вернула ссылку на оплату. Попробуйте позже или обратитесь в поддержку.',
                    ], 500);
                }

                // Обновляем транзакцию
                $transaction->update([
                    'transaction_id' => $yandexOrderId,
                    'response_data' => $responseData,
                    'status' => 'pending',
                ]);

                // Обновляем заказ
                $order->update([
                    'yandex_pay_order_id' => $yandexOrderId,
                    'payment_url' => $paymentUrl,
                ]);

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentUrl,
                    'transaction_id' => $transaction->id,
                    'yandex_order_id' => $yandexOrderId,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания платежа в Yandex Pay',
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа Yandex Pay',
            ], 500);
        }
    }

    /**
     * Перегенерация платежной ссылки для Т‑Банк (e‑acq)
     */
    private function regenerateTbankEacqPayment($paymentMethod, $transaction, $order)
    {
        try {
            $settings = $paymentMethod->getApiSettings();
            if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки Т‑Банк',
                ], 400);
            }
            $service = new TbankPaymentService($settings);
            $orderStub = new \stdClass;
            $orderStub->id = $order->id;
            $orderStub->order_number = $order->order_number;
            $orderStub->total_amount = (float) $order->total_amount;
            $orderStub->customer_email = $order->customer_email;
            $orderStub->customer_phone = $order->customer_phone;
            $orderStub->user_id = $order->user_id;
            $orderStub->items = $order->items;
            $orderStub->delivery_cost = $order->delivery_cost ?? 0;
            $result = $service->initiatePayment($orderStub);
            if (! empty($result['success']) && ! empty($result['payment_url'])) {
                $transaction->update([
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'response_data' => $result['response_data'] ?? null,
                    'status' => 'pending',
                ]);
                $order->update([
                    'payment_url' => $result['payment_url'],
                ]);

                return response()->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'],
                    'transaction_id' => $transaction->id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'T‑Bank: не удалось создать платеж',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа Т‑Банк',
            ], 500);
        }
    }

    /**
     * Перегенерация платежной ссылки для Т‑Банк Долями
     */
    private function regenerateTbankDolyamePayment($paymentMethod, $transaction, $order)
    {
        try {
            $settings = $paymentMethod->getApiSettings();
            if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки Т‑Банк Долями',
                ], 400);
            }
            $service = new TbankPaymentService(array_merge($settings, ['pay_type' => 'DOLYAMI']));
            $orderStub = new \stdClass;
            $orderStub->id = $order->id;
            $orderStub->order_number = $order->order_number;
            $orderStub->total_amount = (float) $order->total_amount;
            $orderStub->customer_email = $order->customer_email;
            $orderStub->customer_phone = $order->customer_phone;
            $orderStub->user_id = $order->user_id;
            $orderStub->items = $order->items;
            $orderStub->delivery_cost = $order->delivery_cost ?? 0;
            $result = $service->initiatePayment($orderStub);
            if (! empty($result['success']) && ! empty($result['payment_url'])) {
                $transaction->update([
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'response_data' => $result['response_data'] ?? null,
                    'status' => 'pending',
                ]);
                $order->update([
                    'payment_url' => $result['payment_url'],
                ]);

                return response()->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'],
                    'transaction_id' => $transaction->id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'T‑Bank Долями: не удалось создать платеж',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа Т‑Банк Долями',
            ], 500);
        }
    }

    /**
     * Проверка настроек Yandex Pay
     */
    private function validateYandexPaySettings($settings)
    {
        if (empty($settings['mode'])) {
            return false;
        }

        // В test режиме нужен merchant_id, в live режиме нужен secret_key
        if ($settings['mode'] === 'test' || $settings['mode'] === 'sandbox') {
            return ! empty($settings['merchant_id']);
        } elseif ($settings['mode'] === 'live') {
            return ! empty($settings['secret_key']);
        }

        return false;
    }

    /**
     * Отправка email с новой платежной ссылкой клиенту
     */
    private function sendNewPaymentLinkEmail($order, $newPaymentUrl)
    {
        if (! $newPaymentUrl || ! $order->customer_email) {
            return;
        }

        try {
            // Получаем контакты и настройки сайта
            $contacts = \App\Models\Contact::where('is_main', 1)->first();
            $siteInfo = \App\Models\Setting::where('key', 'site_info')->first();
            $siteInfo = $siteInfo ? json_decode($siteInfo->value, true) : [];

            \Illuminate\Support\Facades\Mail::send('emails.new-payment-link', [
                'order' => $order,
                'payment_url' => $newPaymentUrl,
                'contacts' => $contacts,
                'siteInfo' => $siteInfo,
            ], function ($message) use ($order, $siteInfo) {
                $siteName = $siteInfo['site_name'] ?? 'Skate & Snow';
                $message->to($order->customer_email, $order->customer_name)
                    ->subject("Новая ссылка для оплаты заказа №{$order->order_number} - {$siteName}");
            });

        } catch (\Exception $e) {
            // Silent fail for email sending
        }
    }

    /**
     * Форматировать параметры вариации
     */
    private function formatVariationProperties($variation): string
    {
        if (! $variation) {
            return '';
        }

        try {

            // Новая схема: формируем строку из атрибутов вариации
            $rows = DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                ->where('vav.variation_id', $variation->id)
                ->select('a.name as attribute_name', 'av.value as value_value')
                ->orderBy('a.name')
                ->get();

            if ($rows->count() > 0) {
                return $rows->map(function ($row) {
                    $propName = $row->attribute_name ?? '';
                    $propValue = $row->value_value ?? '';

                    return $propName.': '.$propValue;
                })->join(', ');
            }

            // Если нет атрибутов, возвращаем название вариации или пустую строку
            return $variation->name ?? '';

        } catch (\Exception $e) {
            // В случае ошибки возвращаем название вариации или пустую строку
            return $variation->name ?? '';
        }
    }
}
