<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\PriceHelper;
use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopOrderLogIcon;
use App\Models\ShopOrderStatus;
use App\Models\ShopCarrierDeliverySettings;
use App\Models\ShopCdekSettings;
use App\Models\ShopDellinSettings;
use App\Models\ShopPaymentTransaction;
use App\Models\ShopRussianPostSettings;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use App\Services\CdekService;
use App\Services\DeliveryPackageService;
use App\Services\TbankPaymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use App\Services\OrderCalculationService;

class ShopOrdersController extends Controller
{
    private const DELLIN_RUSSIA_COUNTRY_UID = '0x8f51001438c4d49511dbd774581edb7a';
    private const DELLIN_PERSON_FORM_UID = '0xAB91FEEA04F6D4AD48DF42161B6C2E7A';

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
            $query = ShopOrder::with(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager']);

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
                } elseif (!is_array($statuses)) {
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
                    'manager_id' => $order->manager_id,
                    'manager_name' => $order->manager ? $order->manager->name : null,
                    'manager' => $order->manager ? [
                        'id' => $order->manager->id,
                        'name' => $order->manager->name,
                    ] : null,
                    'order_bonus_points' => (int) ($order->order_bonus_points ?? 0),
                    'user_bonus_points' => $order->user_id ? (\App\Models\UserBonus::where('user_id', $order->user_id)->value('points') ?? 0) : 0,
                    'use_bonus_points' => (bool) ($order->use_bonus_points ?? false),
                    'bonus_points_to_use' => (int) ($order->bonus_points_to_use ?? 0),
                    'certificate_code' => $order->certificate_code,
                    'has_certificate' => (bool) ($order->has_certificate ?? false),
                    'promo_code' => $order->promo_code,
                    'total_amount' => (float) $order->total_amount,
                    'subtotal' => (float) $order->subtotal,
                    'discount_amount' => (float) $order->discount_amount,
                    'sale_discount_amount' => (float) ($order->sale_discount_amount ?? 0),
                    'registered_user_discount_amount' => (float) ($order->registered_user_discount_amount ?? 0),
                    'promo_code_discount_amount' => (float) ($order->promo_code_discount_amount ?? 0),
                    'birthday_discount_amount' => (float) ($order->birthday_discount_amount ?? 0),
                    'total_discount_amount' => (float) ($order->total_discount_amount ?? 0),
                    'overtax_amount' => (float) ($order->overtax_amount ?? 0),
                    'overtax_text' => $order->overtax_text ?? '',
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
                    'dellin_order_id' => $order->dellin_order_id,
                    'russianpost_order_id' => $order->russianpost_order_id,
                    'russianpost_barcode' => $order->russianpost_barcode,
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
                'message' => 'Ошибка загрузки заказов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить заказ по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $order = ShopOrder::with(['status', 'user', 'deliveryStatus', 'paymentMethod', 'deliveryMethod', 'manager'])
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
                'message' => 'Ошибка загрузки заказа: ' . $e->getMessage(),
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
                'order_number' => 'ORD-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
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
                'sale_discount_amount' => 'sometimes|numeric',
                'registered_user_discount_amount' => 'sometimes|numeric',
                'promo_code_discount_amount' => 'sometimes|numeric',
                'birthday_discount_amount' => 'sometimes|numeric',
                'bonus_points_to_use' => 'sometimes|integer',
                'overtax_amount' => 'sometimes|numeric|min:0',
                'overtax_text' => 'sometimes|string|max:255',
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
            if ($request->has('delivery_cost')) {
                $order->delivery_cost = PriceHelper::roundPrice((float) $request->get('delivery_cost'));
            }

            // Новые поля скидок
            if ($request->has('sale_discount_amount')) {
                $order->sale_discount_amount = PriceHelper::roundDiscount((float) $request->get('sale_discount_amount'));
            }
            if ($request->has('registered_user_discount_amount')) {
                $order->registered_user_discount_amount = PriceHelper::roundDiscount((float) $request->get('registered_user_discount_amount'));
            }
            if ($request->has('promo_code_discount_amount')) {
                $order->promo_code_discount_amount = PriceHelper::roundDiscount((float) $request->get('promo_code_discount_amount'));
            }
            if ($request->has('birthday_discount_amount')) {
                $order->birthday_discount_amount = PriceHelper::roundDiscount((float) $request->get('birthday_discount_amount'));
            }
            if ($request->has('bonus_points_to_use')) {
                $order->bonus_points_to_use = (int) $request->get('bonus_points_to_use');
                $order->use_bonus_points = $order->bonus_points_to_use > 0;
            }
            if ($request->has('overtax_amount')) {
                $order->overtax_amount = PriceHelper::roundPrice((float) $request->get('overtax_amount'));
            }
            if ($request->has('overtax_text')) {
                $order->overtax_text = $request->get('overtax_text');
            }

            // Пересчитываем общую скидку и итоговую сумму
            $totalDiscount = ($order->sale_discount_amount ?? 0) +
                ($order->registered_user_discount_amount ?? 0) +
                ($order->promo_code_discount_amount ?? 0) +
                ($order->birthday_discount_amount ?? 0) +
                ($order->bonus_points_to_use ?? 0);

            $order->total_discount_amount = PriceHelper::roundDiscount($totalDiscount);
            // subtotal уже содержит акционную цену, поэтому из него вычитаем только неакционные скидки
            // delivery_cost не включаем в total_amount — он хранится отдельно (payment = total_amount + delivery_cost)
            $order->total_amount = PriceHelper::roundPrice(($order->subtotal ?? 0)
                - ($order->registered_user_discount_amount ?? 0)
                - ($order->promo_code_discount_amount ?? 0)
                - ($order->birthday_discount_amount ?? 0)
                - ($order->bonus_points_to_use ?? 0)
                + ($order->overtax_amount ?? 0));

            if ($request->has('metadata') && is_array($request->get('metadata'))) {
                $metadata = $order->metadata ?? [];
                $newMetadata = $request->get('metadata');
                $order->metadata = array_merge($metadata, $newMetadata);
            }
            if ($request->filled('notes')) {
                $order->notes = $request->get('notes');
            }

            $order->save();
            $order->load(['status', 'manager']);

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
                'message' => 'Ошибка обновления заказа: ' . $e->getMessage(),
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
                'message' => 'Ошибка удаления заказа: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить статистику по менеджерам
     */
    public function getManagerStatistics(Request $request): JsonResponse
    {
        try {
            $availableMonthsStart = Carbon::now()->startOfMonth()->subMonths(11);
            $availableMonthsEnd = Carbon::now()->endOfMonth();
            $dateFrom = $request->filled('date_from')
                ? Carbon::parse($request->get('date_from'))->startOfDay()
                : Carbon::now()->startOfMonth()->startOfDay();
            $dateTo = $request->filled('date_to')
                ? Carbon::parse($request->get('date_to'))->endOfDay()
                : Carbon::now()->endOfMonth()->endOfDay();
            $search = trim((string) $request->get('search', ''));

            $query = ShopOrder::query()
                ->whereNotNull('manager_id')
                ->with(['manager', 'status'])
                ->whereBetween('created_at', [$dateFrom, $dateTo]);

            if ($request->filled('manager_id')) {
                $query->where('manager_id', $request->manager_id);
            }
            if ($search !== '') {
                $query->whereHas('manager', function ($managerQuery) use ($search) {
                    $managerQuery->where('name', 'like', '%' . $search . '%');
                });
            }

            $orders = $query->get();

            $stats = $orders->groupBy('manager_id')->map(function ($managerOrders) {
                $manager = $managerOrders->first()->manager;
                if (!$manager) {
                    return null;
                }

                $finishedOrders = $managerOrders->filter(fn($order) => (bool) ($order->status?->is_finished));
                $cancelledOrders = $managerOrders->filter(fn($order) => (bool) ($order->status?->is_cancelled));
                $unfinishedOrders = $managerOrders->filter(function ($order) {
                    return !(bool) ($order->status?->is_finished) && !(bool) ($order->status?->is_cancelled);
                });

                return [
                    'manager_id' => $manager->id,
                    'manager_name' => $manager->name,
                    'total_orders' => $managerOrders->count(),
                    'total_amount' => (float) $managerOrders->sum('total_amount'),
                    'finished_orders' => $finishedOrders->count(),
                    'finished_amount' => (float) $finishedOrders->sum('total_amount'),
                    'unfinished_orders' => $unfinishedOrders->count(),
                    'unfinished_amount' => (float) $unfinishedOrders->sum('total_amount'),
                    'cancelled_orders' => $cancelledOrders->count(),
                    'cancelled_amount' => (float) $cancelledOrders->sum('total_amount'),
                ];
            })->filter()->sortBy('manager_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

            $availableMonths = ShopOrder::query()
                ->whereNotNull('manager_id')
                ->whereBetween('created_at', [$availableMonthsStart, $availableMonthsEnd])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_value")
                ->groupBy('month_value')
                ->orderByDesc('month_value')
                ->pluck('month_value')
                ->map(function ($monthValue) {
                    $monthDate = Carbon::createFromFormat('Y-m', $monthValue)->startOfMonth();

                    return [
                        'value' => $monthValue,
                        'label' => $monthDate->translatedFormat('F Y'),
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                    'search' => $search,
                    'available_months' => $availableMonths,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: ' . $e->getMessage(),
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
                        'is_taken_to_work' => (bool) $status->is_taken_to_work,
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
                'message' => 'Ошибка загрузки статусов: ' . $e->getMessage(),
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
                'take_to_work' => 'sometimes|boolean',
                'comment' => 'nullable|string|max:2000',
                'restore_items' => 'sometimes|array',
                'restore_items.*.good_id' => 'required_with:restore_items|integer',
                'restore_items.*.variation_id' => 'nullable|integer',
                'restore_items.*.quantity' => 'required_with:restore_items|integer|min:0',
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
            if ($newStatus->is_finished && !$order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя установить завершенный статус для неоплаченного заказа',
                ], 422);
            }

            // Если новый статус - отмена заказа, возвращаем товары на склад и бонусы пользователю
            if ($newStatus->is_cancelled) {
                $this->restoreOrderItemsToStock(
                    $order,
                    $request->has('restore_items') ? $request->input('restore_items', []) : null
                );
                $this->restoreUserBonuses($order);
                // Снимаем отметку о заявке на отмену при установке статуса отмены
                if ($order->cancellation_request) {
                    $order->cancellation_request = false;
                }
            }

            // Если старый статус был отмененным и выбирается другой статус (восстановление), вычитаем товары и бонусы
            if ($oldStatus && $oldStatus->is_cancelled && !$newStatus->is_cancelled && $request->get('is_restore', false)) {
                $this->deductOrderItemsFromStock($order);
                $this->deductUserBonuses($order);
            }

            // Привязка менеджера при установке статуса "Взял в работу" или при явном флаге
            if (($newStatus->is_taken_to_work || $request->get('take_to_work')) && !$order->manager_id) {
                $order->manager_id = $request->user() ? $request->user()->id : null;
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
                    'manager_id' => $order->manager_id,
                    'manager_name' => $order->manager?->name,
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
                'message' => 'Ошибка обновления статуса: ' . $e->getMessage(),
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
                    'order_id' => $order->id,
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
            Log::error('Ошибка при вычитании товаров со склада: ' . $e->getMessage(), [
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
            Log::error('Ошибка при возврате бонусов пользователю: ' . $e->getMessage(), [
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
            Log::error('Ошибка при списании бонусов у пользователя: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Возврат товаров заказа на склад при отмене
     * Обновляет stock_quantity в таблицах shop_goods или shop_good_variations
     */
    private function restoreOrderItemsToStock(ShopOrder $order, ?array $restoreItems = null): void
    {
        try {
            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;

            if (!is_array($items) || empty($items)) {
                Log::info('Заказ не содержит товаров для возврата на склад', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            $restoreQuantities = null;
            if (is_array($restoreItems)) {
                $restoreQuantities = [];

                foreach ($restoreItems as $restoreItem) {
                    $goodId = $restoreItem['good_id'] ?? null;
                    if (!$goodId) {
                        continue;
                    }

                    $variationId = $restoreItem['variation_id'] ?? null;
                    $key = $this->buildOrderItemStockKey($goodId, $variationId);
                    $restoreQuantities[$key] = max(0, (int) ($restoreItem['quantity'] ?? 0));
                }
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (!$goodId) {
                    continue;
                }

                if ($restoreQuantities !== null) {
                    $key = $this->buildOrderItemStockKey($goodId, $variationId);
                    if (!array_key_exists($key, $restoreQuantities)) {
                        continue;
                    }

                    $quantity = $restoreQuantities[$key];
                }

                if ($quantity <= 0) {
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
            Log::error('Ошибка при возврате товаров на склад: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    private function buildOrderItemStockKey($goodId, $variationId = null): string
    {
        return (int) $goodId . ':' . ($variationId ? (int) $variationId : 'main');
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
                'bonus_action' => 'nullable|string|in:accrual,revocation,return,spending',
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
                $bonusAction = $request->get('bonus_action', $newPayedStatus ? 'accrual' : 'revocation');

                if ($newPayedStatus) {
                    // Меняем статус на ОПЛАЧЕНО
                    if ($bonusAction === 'spending') {
                        // Списание бонусов (например, повторное после отмены)
                        $userBonus->spendPoints(
                            $bonusPoints,
                            "Списание бонусов при подтверждении оплаты заказа #{$order->order_number}",
                            $order->id
                        );
                    } else {
                        // Начисление бонусов
                        $userBonus->addPoints(
                            $bonusPoints,
                            "Начисление бонусов за оплату заказа #{$order->order_number}",
                            $order->id
                        );
                    }
                } else {
                    // Меняем статус на НЕ ОПЛАЧЕНО
                    if ($bonusAction === 'return') {
                        // ВОЗВРАТ списанных бонусов (прибавляем к балансу)
                        $userBonus->addPoints(
                            $bonusPoints,
                            "Возврат списанных бонусов при отмене оплаты заказа #{$order->order_number}",
                            $order->id
                        );
                    } else {
                        // ОТЗЫВ начисленных бонусов (списываем с баланса)
                        $pointsToRevoke = min($userBonus->points, $bonusPoints);

                        if ($pointsToRevoke > 0) {
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
                                    'points' => -$pointsToRevoke,
                                    'description' => "Возврат бонусов за отмену оплаты заказа #{$order->order_number}" . ($pointsToRevoke < $bonusPoints ? " (списано частично: {$pointsToRevoke} из {$bonusPoints})" : ""),
                                    'order_id' => $order->id,
                                    'metadata' => [
                                        'original_transaction_id' => $earnTransaction->id,
                                        'requested_points' => $bonusPoints,
                                        'actual_points' => $pointsToRevoke
                                    ],
                                ]);

                                // Уменьшаем бонусы
                                $userBonus->points -= $pointsToRevoke;
                                $userBonus->total_spent += $pointsToRevoke;
                                $userBonus->save();
                            } else {
                                // Если транзакции нет, просто списываем через spendPoints
                                try {
                                    $userBonus->spendPoints(
                                        $pointsToRevoke,
                                        "Списание бонусов за отмену оплаты заказа #{$order->order_number}" . ($pointsToRevoke < $bonusPoints ? " (списано частично: {$pointsToRevoke} из {$bonusPoints})" : ""),
                                        $order->id
                                    );
                                } catch (\Exception $e) {
                                    return response()->json([
                                        'success' => false,
                                        'message' => 'Ошибка списания бонусов: ' . $e->getMessage(),
                                    ], 422);
                                }
                            }
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
                'message' => 'Ошибка обновления статуса оплаты: ' . $e->getMessage(),
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
                'message' => 'Ошибка обновления статуса активности: ' . $e->getMessage(),
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
            if (!empty($order->comment) && $request->filled('comment')) {
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
                'message' => 'Ошибка обновления комментария: ' . $e->getMessage(),
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
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            // Получаем статус из СДЭК
            $cdekService = new CdekService;
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
            // CDEK API v2 возвращает статусы в порядке убывания (сначала самый новый)
            if (isset($cdekData['entity']['statuses']) && is_array($cdekData['entity']['statuses']) && count($cdekData['entity']['statuses']) > 0) {
                // Берем ПЕРВЫЙ статус из entity.statuses — он самый актуальный (CDEK возвращает desc)
                $lastStatus = reset($cdekData['entity']['statuses']);
                $deliveryStatus = [
                    'code' => $lastStatus['code'] ?? null,
                    'name' => $lastStatus['name'] ?? null,
                    'city' => $lastStatus['city'] ?? null,
                ];
            } elseif (isset($cdekData['statuses']) && is_array($cdekData['statuses']) && count($cdekData['statuses']) > 0) {
                // Берем первый статус из корня (тоже самый актуальный)
                $lastStatus = reset($cdekData['statuses']);
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

            // Сохраняем статус в заказ
            $order->delivery_status = json_encode($deliveryStatus, JSON_UNESCAPED_UNICODE);
            $order->save();

            // Обновляем заказ из БД для получения актуальных данных
            $order->refresh();

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
            Log::error('Ошибка обновления статуса доставки: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса доставки: ' . $e->getMessage(),
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
                    'message' => 'Заказ не может быть завершен, так как он не оплачен',
                ], 422);
            }

            // Находим статус с is_finished=1
            $finishedStatus = ShopOrderStatus::where('is_finished', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();

            // Если не найден по is_finished, пытаемся найти по имени 'finished'
            if (!$finishedStatus) {
                $finishedStatus = ShopOrderStatus::where('name', 'finished')
                    ->where('is_active', true)
                    ->first();
            }

            // Если все еще не найден, пытаемся найти по display_name 'Завершен'
            if (!$finishedStatus) {
                $finishedStatus = ShopOrderStatus::where('display_name', 'Завершен')
                    ->where('is_active', true)
                    ->first();
            }

            if (!$finishedStatus) {
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
                'message' => 'Ошибка завершения заказа: ' . $e->getMessage(),
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
            $basePrice = PriceHelper::roundPrice((float) ($good->price ?? 0));
            $salePrice = ($good->sale_price ?? 0) > 0
                ? PriceHelper::roundPrice((float) $good->sale_price)
                : 0;
            $price = $salePrice > 0 ? $salePrice : $basePrice;

            if ($variationId) {
                $variation = \App\Models\ShopGoodVariation::where('id', $variationId)->where('good_id', $goodId)->first();
                if ($variation) {
                    $basePrice = PriceHelper::roundPrice((float) ($variation->price > 0 ? $variation->price : $basePrice));
                    $salePrice = $variation->sale_price > 0
                        ? PriceHelper::roundPrice((float) $variation->sale_price)
                        : 0;
                    $price = $salePrice > 0 ? $salePrice : $basePrice;
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
                $items[$existingItemIndex]['price'] = $price;
                $items[$existingItemIndex]['final_price'] = $price;
                $items[$existingItemIndex]['base_price'] = $basePrice;
                $items[$existingItemIndex]['sale_price'] = $salePrice;
                $items[$existingItemIndex]['total'] = PriceHelper::roundPrice($items[$existingItemIndex]['quantity'] * $price);
            } else {
                // Добавляем новый товар
                $newItem = [
                    'good_id' => $goodId,
                    'good_name' => $good->name,
                    'good_slug' => $good->slug ?? null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'base_price' => $basePrice,
                    'sale_price' => $salePrice,
                    'final_price' => $price,
                    'total' => PriceHelper::roundPrice($price * $quantity),
                ];

                if ($variation) {
                    $newItem['variation_id'] = $variation->id;
                    $newItem['variation_name'] = $variationName;
                    $newItem['variation_sku'] = $variation->sku;
                }

                $items[] = $newItem;
            }

            // Пересчитываем суммы заказа
            $order->items = $items;
            $this->recalculateOrderAmounts($order);
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
                'message' => 'Ошибка добавления товара: ' . $e->getMessage(),
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

            if (!$itemFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в заказе',
                ], 404);
            }

            // Переиндексируем массив
            $items = array_values($items);

            $order->items = $items;
            $this->recalculateOrderAmounts($order);
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
                'message' => 'Ошибка удаления товара: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить товар в заказе
     */
    public function updateItem(Request $request, $orderId, $itemId): JsonResponse
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

            $validator = Validator::make($request->all(), [
                'price' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $newPrice = PriceHelper::roundPrice((float) $request->get('price'));

            // Получаем текущие товары заказа
            $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
            $items = $items ?: [];

            // Находим и обновляем товар
            $itemFound = false;
            foreach ($items as $index => $item) {
                // Пытаемся найти по good_id + variation_id (если variation_id есть) или просто по good_id
                // ВOrders.vue мы передаем itemId, который часто равен good_id
                if (($item['good_id'] ?? null) == $itemId || ($item['id'] ?? null) == $itemId) {
                    $items[$index]['price'] = $newPrice;
                    $items[$index]['final_price'] = $newPrice;
                    $items[$index]['total'] = PriceHelper::roundPrice($newPrice * ($items[$index]['quantity'] ?? 1));
                    $itemFound = true;
                    break;
                }
            }

            if (!$itemFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в заказе',
                ], 404);
            }

            $order->items = $items;
            $this->recalculateOrderAmounts($order);
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Цена товара обновлена',
                'data' => $this->formatOrderForResponse($order->load(['status', 'user', 'paymentMethod', 'deliveryMethod'])),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления товара: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Форматировать заказ для ответа
     */
    /**
     * Пересчет сумм заказа и скидок при изменении состава товаров
     */
    protected function recalculateOrderAmounts(ShopOrder $order)
    {
        $items = is_array($order->items) ? $order->items : json_decode($order->items, true);
        $items = $items ?: [];

        $subtotal = 0;
        $baseSubtotal = 0;
        $totalQuantity = 0;
        $discountableSubtotal = 0;
        $registeredDiscountPercent = $order->user_id
            ? (float) (\App\Models\Setting::where('key', 'discount_reg')->value('value') ?? 0)
            : 0;
        $discountToDTextValue = \App\Models\Setting::where('key', 'discount_to_d_text')->value('value');
        $registeredDiscountForSaleAllowed = ! in_array($discountToDTextValue, [null, '', '0', 0, false], true);

        foreach ($items as &$item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $price = PriceHelper::roundPrice((float) ($item['price'] ?? $item['final_price'] ?? 0));
            $basePrice = PriceHelper::roundPrice((float) ($item['base_price'] ?? $price));
            $itemTotal = PriceHelper::roundPrice($price * $quantity);
            $itemBaseTotal = $basePrice * $quantity;

            $item['quantity'] = $quantity;
            $item['price'] = $price;
            $item['final_price'] = $price;
            $item['base_price'] = $basePrice;
            $item['total'] = $itemTotal;
            
            $subtotal += $itemTotal;
            $baseSubtotal += $itemBaseTotal;
            $totalQuantity += $quantity;
            
            $isSale = (bool) ($item['is_sale_price'] ?? (($item['sale_price'] ?? 0) > 0 && $price < $basePrice));
            $isDemping = (bool) ($item['show_demping'] ?? false);
            $tags = collect($item['tags'] ?? []);
            if ($tags->isEmpty() && ! empty($item['good_id'])) {
                $good = ShopGood::with('tags')->find($item['good_id']);
                $tags = $good?->tags ?? collect();
            }
            $hasTagDiscount = $tags->contains(fn ($tag) => (float) data_get($tag, 'extra_discount_percent', 0) > 0);
            $hasNoBonusTag = $tags->contains(fn ($tag) => (bool) data_get($tag, 'disables_bonuses', false));
            $hasNoRegisteredDiscountTag = $tags->contains(fn ($tag) => (bool) data_get($tag, 'disables_registered_discount', false));
            $hasDiscountPrice = $isSale || $isDemping || $hasTagDiscount;

            if (! $isDemping
                && ! $hasNoBonusTag
                && ! $hasNoRegisteredDiscountTag
                && ($registeredDiscountForSaleAllowed || ! $hasDiscountPrice)
            ) {
                $discountableSubtotal += $itemTotal;
            }
        }
        unset($item);

        $order->items = $items;
        $order->subtotal = PriceHelper::roundPrice($subtotal);
        $order->total_quantity = $totalQuantity;
        $order->sale_discount_amount = PriceHelper::roundDiscount(max(0, $baseSubtotal - $subtotal));

        // 1. Скидка зарегистрированного пользователя / День рождения
        if (($order->registered_user_discount_amount ?? 0) > 0) {
            $order->registered_user_discount_amount = PriceHelper::roundDiscount(
                $discountableSubtotal * ($registeredDiscountPercent / 100)
            );
        }
        
        if (($order->birthday_discount_amount ?? 0) > 0) {
            $order->birthday_discount_amount = PriceHelper::roundDiscount($discountableSubtotal * 0.10);
        }

        $subtotalAfterUserDiscount = $subtotal - ($order->registered_user_discount_amount ?? 0) - ($order->birthday_discount_amount ?? 0);

        // 2. Промокод
        $promoCodeDiscountAmount = 0;
        if ($order->promo_code_id) {
            $promoCode = \App\Models\Promocode::find($order->promo_code_id);
            if ($promoCode) {
                if (empty($items)) {
                    $promoCodeDiscountAmount = 0;
                } else {
                    $discountResult = $promoCode->calculateDiscount($subtotalAfterUserDiscount, $items, $order->user_id);
                    if (isset($discountResult['discount']) && $discountResult['discount'] > 0) {
                        $promoCodeDiscountAmount = $discountResult['discount'];
                    }
                }
            }
        }
        $order->promo_code_discount_amount = PriceHelper::roundDiscount((float) $promoCodeDiscountAmount);

        // 3. Бонусы
        $bonusPointsDiscountAmount = 0;
        if (($order->bonus_points_to_use ?? 0) > 0) {
            $originalBonusPointsToUse = (int) $order->bonus_points_to_use;
            $pointsToUse = $originalBonusPointsToUse;
            $baseAmountForBonuses = $subtotalAfterUserDiscount - $promoCodeDiscountAmount;
            
            $maxBonusPercent = (float) (\App\Models\Setting::where('key', 'tag_max_bonus_tax')->value('value') ?? 50) / 100;
            $maxBonusUsage = $baseAmountForBonuses * $maxBonusPercent;
            $bonusPointsDiscountAmount = min($pointsToUse, $baseAmountForBonuses, $maxBonusUsage);
            $newBonusPointsToUse = (int) round(max(0, $bonusPointsDiscountAmount));

            if ($newBonusPointsToUse < $originalBonusPointsToUse && $order->user_id) {
                // Возвращаем разницу пользователю
                $difference = $originalBonusPointsToUse - $newBonusPointsToUse;
                $userBonus = \App\Models\UserBonus::getOrCreateForUser($order->user_id);
                $userBonus->addPoints(
                    $difference,
                    "Возврат бонусов из-за пересчета суммы заказа #{$order->order_number}",
                    $order->id,
                    null,
                    ['source' => 'recalculation']
                );
            }

            $order->bonus_points_to_use = $newBonusPointsToUse;
            if ($order->bonus_points_to_use <= 0) {
                $order->use_bonus_points = false;
            }
        }

        // 4. Итоги
        $totalDiscount = ($order->registered_user_discount_amount ?? 0) +
            ($order->birthday_discount_amount ?? 0) +
            ($order->promo_code_discount_amount ?? 0) +
            ($order->bonus_points_to_use ?? 0);

        $order->total_discount_amount = PriceHelper::roundDiscount($totalDiscount + ($order->sale_discount_amount ?? 0));

        $order->total_amount = PriceHelper::roundPrice($subtotal - $totalDiscount + ($order->overtax_amount ?? 0));
    }

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
            'manager_id' => $order->manager_id,
            'manager' => $order->manager ? [
                'id' => $order->manager->id,
                'name' => $order->manager->name,
                'email' => $order->manager->email,
            ] : null,
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
            'overtax_amount' => (float) ($order->overtax_amount ?? 0),
            'overtax_text' => $order->overtax_text ?? '',
            'delivery_cost' => (float) ($order->delivery_cost ?? 0),
            'total_quantity' => $order->total_quantity ?? 0,
            'payment_method' => $order->payment_method,
            'payment_method_id' => $order->payment_method_id,
            'shipping_method' => $order->shipping_method,
            'shipping_address' => $order->shipping_address,
            'cdek_order_uuid' => $order->cdek_order_uuid,
            'dellin_order_id' => $order->dellin_order_id,
            'russianpost_order_id' => $order->russianpost_order_id,
            'russianpost_barcode' => $order->russianpost_barcode,
            'delivery_tracking_data' => is_string($order->delivery_status) ? json_decode($order->delivery_status, true) : $order->delivery_status,
            'notes' => $order->notes,
            'comment' => $order->comment,
            'cancellation_request' => (bool) ($order->cancellation_request ?? false),
            'promo_code' => $order->promo_code,
            'certificate_code' => $order->certificate_code,
            'has_certificate' => (bool) ($order->has_certificate ?? false),
            'promo_code_id' => $order->promo_code_id,
            'use_bonus_points' => $order->use_bonus_points ?? false,
            'bonus_points_to_use' => $order->bonus_points_to_use ?? 0,
            'order_bonus_points' => $order->order_bonus_points ?? 0,
            'user_bonus_points' => $order->user_id ? (\App\Models\UserBonus::where('user_id', $order->user_id)->value('points') ?? 0) : 0,
            'items_count' => count($items),
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
            'items' => array_map(function ($item) use ($order) {
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
            if (!$order->cdek_order_uuid) {
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
                $proxyUrl = url('/api/admin/shop/orders/' . $id . '/cdek/barcode/download');

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
            Log::error('Ошибка получения штрихкода СДЭК: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения штрихкода: ' . $e->getMessage(),
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

            if (!$result['success'] || !isset($result['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Ошибка получения штрихкода',
                ], 500);
            }

            // Получаем токен СДЭК
            $token = $cdekService->getAccessToken();
            if (!$token) {
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
                        'Authorization' => 'Bearer ' . $token,
                    ])->get($result['url']);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="cdek-barcode-' . $order->order_number . '.pdf"');
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка скачивания штрихкода из СДЭК',
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания штрихкода СДЭК: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания штрихкода: ' . $e->getMessage(),
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
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;
            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if ($result['success']) {
                // Возвращаем URL прокси вместо прямого URL СДЭК
                $proxyUrl = url('/api/admin/shop/orders/' . $id . '/cdek/waybill/download');

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
            Log::error('Ошибка получения накладной СДЭК: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения накладной: ' . $e->getMessage(),
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
                    'message' => 'У заказа нет UUID заказа СДЭК',
                ], 400);
            }

            $cdekService = new CdekService;

            // Получаем токен СДЭК заранее
            $token = $cdekService->getAccessToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ], 500);
            }

            $result = $cdekService->getWaybill($order->cdek_order_uuid);

            if (!$result['success'] || !isset($result['url'])) {
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
                        'Authorization' => 'Bearer ' . $token,
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
                            'message' => 'Накладная еще не сгенерирована. Статус заказа: ' . $orderStatusName . '. Накладная будет доступна после обработки заказа в СДЭК.',
                        ], 404);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Накладная еще не сгенерирована для этого заказа. Попробуйте позже или проверьте статус заказа в личном кабинете СДЭК.',
                    ], 404);
                }

                // Пытаемся распарсить как JSON для получения сообщения об ошибке
                $errorData = json_decode($responseBody, true);
                $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Ошибка скачивания накладной из СДЭК (HTTP ' . $statusCode . ')';

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка скачивания накладной СДЭК: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания накладной: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function changeDelivery(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shipping_method_id' => 'nullable|integer|exists:shop_delivery_methods,id',
            'shipping_method' => 'required|string|max:255',
            'shipping_address' => 'nullable|string|max:2000',
            'delivery_cost' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order = ShopOrder::findOrFail($id);
            $oldMethod = $order->shipping_method;
            $oldAddress = $order->shipping_address;
            $oldCost = (float) ($order->delivery_cost ?? 0);
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            foreach ([
                'yandex_request_id',
                'yandex_request_payload',
                'yandex_request_response',
                'yandex_status_response',
                'yandex_sharing_url',
                'yandex_courier_order_id',
            ] as $key) {
                unset($metadata[$key]);
            }
            $metadata = array_merge($metadata, $request->get('metadata', []));

            $order->update([
                'shipping_method_id' => $request->get('shipping_method_id'),
                'shipping_method' => $request->get('shipping_method'),
                'shipping_address' => $request->get('shipping_address'),
                'delivery_cost' => $request->has('delivery_cost') ? (float) $request->get('delivery_cost') : $oldCost,
                'metadata' => $metadata,
                'dellin_order_id' => null,
                'russianpost_order_id' => null,
                'russianpost_barcode' => null,
                'delivery_status' => null,
            ]);

            ShopOrderLog::createLog($order->id, 'Доставка изменена', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#0EA5E9',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => implode("\n", array_filter([
                    "Было: {$oldMethod}".($oldAddress ? " / {$oldAddress}" : ''),
                    'Стало: '.$request->get('shipping_method').($request->get('shipping_address') ? ' / '.$request->get('shipping_address') : ''),
                    $request->has('delivery_cost') ? 'Стоимость: '.number_format((float) $request->get('delivery_cost'), 0, ',', ' ').' ₽' : null,
                ])),
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Доставка заказа обновлена',
                'data' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка смены доставки заказа: '.$e->getMessage(), ['order_id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка смены доставки: '.$e->getMessage(),
            ], 500);
        }
    }

    public function createDellinOrder(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            $settings = ShopDellinSettings::getActive();

            if (! $settings || ! $settings->appkey) {
                return response()->json(['success' => false, 'message' => 'Активные настройки Деловых линий не заполнены'], 400);
            }

            if (! $settings->counteragent_uid) {
                return response()->json([
                    'success' => false,
                    'message' => 'В настройках Деловых линий не выбран контрагент. Проверьте ключ, выберите контрагента и сохраните настройки.',
                ], 422);
            }

            if ($order->dellin_order_id) {
                return response()->json(['success' => true, 'message' => 'Заявка Деловых линий уже создана', 'data' => ['dellin_order_id' => $order->dellin_order_id]]);
            }

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $deliveryType = $metadata['dellin_delivery_type'] ?? null;
            $terminalId = $metadata['dellin_terminal_id'] ?? null;
            $deliveryAddress = $metadata['dellin_delivery_address'] ?? $order->shipping_address;

            // Старые заказы создавались до сохранения типа доставки ДЛ в metadata.
            // Для них безопасно восстанавливаем доставку до адреса, только если адрес уже есть в заказе.
            if (! $deliveryType && filled($deliveryAddress)) {
                $deliveryType = 'address';
            }

            if (! $deliveryType || ($deliveryType === 'terminal' && ! $terminalId) || ($deliveryType === 'address' && ! $deliveryAddress)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно данных для создания заявки Деловых линий: проверьте тип доставки, терминал или адрес получателя.',
                ], 422);
            }

            $payload = $this->buildDellinOrderPayload($order, $settings, $deliveryType, $terminalId, $deliveryAddress);
            $response = Http::withOptions([
                'verify' => $this->getDellinVerifyOption(),
                'timeout' => 30,
            ])->post('https://api.dellin.ru/v2/request.json', $payload);
            $data = $response->json() ?: [];

            if (! $response->successful() || isset($data['errors'])) {
                return response()->json([
                    'success' => false,
                    'message' => $this->extractExternalDeliveryError($data, 'Деловые линии не приняли заявку'),
                    'data' => ['request_payload' => $payload, 'server_response' => $data],
                ], 422);
            }

            $externalId = $data['data']['requestID'] ?? $data['data']['orderID'] ?? $data['requestID'] ?? $data['orderID'] ?? null;
            $barcode = $data['data']['barcode'] ?? $data['barcode'] ?? null;
            if (! $externalId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Деловые линии приняли запрос, но не вернули номер заявки',
                    'data' => ['request_payload' => $payload, 'server_response' => $data],
                ], 422);
            }

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            if ($barcode) {
                $metadata['dellin_barcode'] = (string) $barcode;
            }
            $metadata['dellin_request_payload'] = $payload;

            $order->update([
                'dellin_order_id' => (string) $externalId,
                'metadata' => $metadata,
                'delivery_status' => json_encode(['code' => 'CREATED', 'name' => 'Заявка создана в Деловых линиях'], JSON_UNESCAPED_UNICODE),
            ]);

            ShopOrderLog::createLog($order->id, 'Заявка Деловых линий создана', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#16A34A',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => trim('ID заявки: '.$externalId.($barcode ? "\nШтрихкод: {$barcode}" : '')),
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Заявка Деловых линий создана',
                'data' => [
                    'dellin_order_id' => $externalId,
                    'barcode' => $barcode,
                    'delivery_status' => ['code' => 'CREATED', 'name' => 'Заявка создана в Деловых линиях'],
                    'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                    'server_response' => $data,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка создания заявки Деловых линий: '.$e->getMessage(), ['order_id' => $id]);

            return response()->json(['success' => false, 'message' => 'Ошибка создания заявки Деловых линий: '.$e->getMessage()], 500);
        }
    }

    public function updateDellinStatus(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            if (! $order->dellin_order_id) {
                return response()->json(['success' => false, 'message' => 'У заказа нет ID заявки Деловых линий'], 400);
            }

            $settings = ShopDellinSettings::getActive();
            if (! $settings || ! $settings->appkey) {
                return response()->json(['success' => false, 'message' => 'Активные настройки Деловых линий не заполнены'], 400);
            }

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $payload = [
                'appkey' => $settings->appkey,
                'docIds' => [(string) $order->dellin_order_id],
            ];

            if ($settings->auth_type !== 'appkey') {
                $sessionId = $this->getDellinSessionId($settings);
                if ($sessionId) {
                    $payload['sessionID'] = $sessionId;
                }
            }

            $response = Http::withOptions([
                'verify' => $this->getDellinVerifyOption(),
                'timeout' => 30,
            ])->post('https://api.dellin.ru/v3/orders/statuses_history.json', $payload);
            $data = $response->json() ?: [];

            if (! $response->successful() || isset($data['errors'])) {
                return response()->json([
                    'success' => false,
                    'message' => $this->extractExternalDeliveryError($data, 'Деловые линии не вернули статус заявки'),
                    'data' => ['request_payload' => $payload, 'server_response' => $data],
                ], 422);
            }

            $history = $data['data']['statusHistory'][$order->dellin_order_id]
                ?? $data['data']['statusHistory'][(string) $order->dellin_order_id]
                ?? [];
            $last = $this->extractDellinLastStatusItem($history);

            if (empty($last) || ! ($last['state'] ?? $last['State'] ?? null)) {
                $ordersData = $this->fetchDellinOrderJournal($settings, $order);
                $last = $ordersData['order'] ?? $last;
                if (! empty($ordersData['payload'])) {
                    $data['orders_journal_request'] = $ordersData['payload'];
                }
                if (! empty($ordersData['response'])) {
                    $data['orders_journal_response'] = $ordersData['response'];
                }
            }

            $status = [
                'code' => $last['state'] ?? $last['State'] ?? 'UNKNOWN',
                'name' => $last['stateName'] ?? $last['StateName'] ?? 'Статус Деловых линий не найден',
                'date' => $last['stateDate'] ?? $last['StateDate'] ?? $last['orderedAt'] ?? null,
                'detailed_status' => $last['detailedStatus'] ?? $last['DetailedStatus'] ?? null,
                'detailed_status_name' => $last['detailedStatusRus'] ?? $last['DetailedStatusRus'] ?? null,
                'progress_percent' => $last['progressPercent'] ?? null,
                'external_id' => $order->dellin_order_id,
            ];
            $order->update(['delivery_status' => json_encode($status, JSON_UNESCAPED_UNICODE)]);

            ShopOrderLog::createLog($order->id, 'Статус Деловых линий обновлен', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#2563EB',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => trim(($status['name'] ?? '').($status['detailed_status_name'] ? ' / '.$status['detailed_status_name'] : '')),
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json(['success' => true, 'message' => 'Статус доставки обновлен', 'data' => ['delivery_status' => $status, 'server_response' => $data]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Ошибка проверки статуса Деловых линий: '.$e->getMessage()], 500);
        }
    }

    public function createRussianPostOrder(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            $settings = ShopRussianPostSettings::getActive();

            if (! $settings || ! $settings->api_token || ! $settings->login || ! $settings->password || ! $settings->sender_postal_code) {
                return response()->json(['success' => false, 'message' => 'Активные настройки Почты России заполнены не полностью'], 400);
            }

            if ($order->russianpost_order_id || $order->russianpost_barcode) {
                return response()->json([
                    'success' => true,
                    'message' => 'Отправление Почты России уже создано',
                    'data' => ['russianpost_order_id' => $order->russianpost_order_id, 'barcode' => $order->russianpost_barcode],
                ]);
            }

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $postalCode = $this->resolveRussianPostPostalCode($order, $request);
            if ($postalCode === '') {
                return response()->json(['success' => false, 'message' => 'Для создания отправления Почты России не указан индекс получателя/ОПС'], 422);
            }

            if (($metadata['russianpost_postal_code'] ?? null) !== $postalCode) {
                $metadata['russianpost_postal_code'] = $postalCode;
                $order->metadata = $metadata;
                $order->save();
            }

            $packages = app(DeliveryPackageService::class)->fromOrder($order, $settings);
            $packages = $this->withRussianPostPackageValues($order, $settings, $packages);
            $payload = array_map(
                fn (array $package) => $this->buildRussianPostOrderPayload($order, $settings, $postalCode, $package),
                $packages
            );
            $response = Http::withOptions([
                'verify' => filter_var(config('services.russianpost.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
                'timeout' => 30,
            ])->withHeaders($this->russianPostHeaders($settings))->put('https://otpravka-api.pochta.ru/1.0/user/backlog', $payload);
            $data = $response->json() ?: [];

            if (! $response->successful() || $this->russianPostResponseHasErrors($data)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->extractExternalDeliveryError($data, $response->body() ?: 'Почта России не приняла отправление'),
                    'data' => ['request_payload' => $payload, 'server_response' => $data],
                ], 422);
            }

            [$externalId, $barcode] = $this->extractRussianPostCreationIdentifiers($data);

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $metadata['russianpost_shipments'] = $data;
            $order->metadata = $metadata;

            if (! $externalId && ! $barcode) {
                Log::warning('Почта России приняла отправление, но не вернула ID/ШПИ', [
                    'order_id' => $order->id,
                    'response' => $data,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Почта России приняла запрос, но не вернула ID отправления/ШПИ. Проверьте отправление в личном кабинете Почты России.',
                    'data' => ['request_payload' => $payload, 'server_response' => $data],
                ], 422);
            }

            $externalId = $externalId ?: $barcode;

            $order->update([
                'russianpost_order_id' => $externalId ? (string) $externalId : null,
                'russianpost_barcode' => $barcode ? (string) $barcode : null,
                'delivery_status' => json_encode(['code' => 'CREATED', 'name' => 'Отправление создано в Почте России'], JSON_UNESCAPED_UNICODE),
            ]);

            ShopOrderLog::createLog($order->id, 'Отправление Почты России создано', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#16A34A',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => implode("\n", array_filter(['ID: '.$externalId, 'ШПИ: '.$barcode])),
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Отправление Почты России создано',
                'data' => [
                    'russianpost_order_id' => $externalId,
                    'barcode' => $barcode,
                    'delivery_status' => ['code' => 'CREATED', 'name' => 'Отправление создано в Почте России'],
                    'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                    'server_response' => $data,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка создания отправления Почты России: '.$e->getMessage(), ['order_id' => $id]);

            return response()->json(['success' => false, 'message' => 'Ошибка создания отправления Почты России: '.$e->getMessage()], 500);
        }
    }

    public function updateRussianPostStatus(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            if (! $order->russianpost_barcode && ! $order->russianpost_order_id) {
                return response()->json(['success' => false, 'message' => 'У заказа нет ID отправления Почты России'], 400);
            }

            $status = $order->russianpost_barcode
                ? $this->fetchRussianPostTrackingStatus($order)
                : [
                    'code' => 'CREATED',
                    'name' => 'Отправление создано, ШПИ пока не получен',
                    'external_id' => $order->russianpost_order_id,
                    'barcode' => null,
                    'tracking_available' => false,
                ];

            $order->update(['delivery_status' => json_encode($status, JSON_UNESCAPED_UNICODE)]);

            return response()->json([
                'success' => true,
                'message' => 'Статус доставки обновлен',
                'data' => [
                    'delivery_status' => $status,
                    'russianpost_order_id' => $order->russianpost_order_id,
                    'barcode' => $order->russianpost_barcode,
                    'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Ошибка проверки статуса Почты России: '.$e->getMessage()], 500);
        }
    }

    public function createYandexDeliveryOrder(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            $settings = ShopCarrierDeliverySettings::getActive('yandex');

            if (! $settings || ! $settings->api_token) {
                return response()->json(['success' => false, 'message' => 'Активные настройки Яндекс Доставки заполнены не полностью'], 400);
            }

            if (! $this->isYandexExpressMode($settings) && ! $settings->warehouse_id) {
                return response()->json(['success' => false, 'message' => 'Для режима Яндекс Доставка по России укажите ID склада / platform_station_id'], 400);
            }

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            if (! empty($metadata['yandex_request_id'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'Заявка Яндекс Доставки уже создана',
                    'data' => ['yandex_request_id' => $metadata['yandex_request_id']],
                ]);
            }

            if ($this->isYandexExpressMode($settings)) {
                return $this->createYandexExpressDeliveryOrder($order, $settings);
            }

            $payload = $this->buildYandexDeliveryOrderPayload($order, $settings);
            $response = $this->sendYandexDeliveryRequest($settings, 'post', '/request/create', $payload);

            $requestId = $response['request_id'] ?? $response['id'] ?? $response['request']['id'] ?? null;
            if (! $requestId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Яндекс Доставка приняла ответ без request_id. Проверьте ответ API.',
                    'data' => ['request_payload' => $payload, 'server_response' => $response],
                ], 422);
            }

            $metadata['yandex_request_id'] = (string) $requestId;
            $metadata['yandex_request_payload'] = $payload;
            $metadata['yandex_request_response'] = $response;

            $status = [
                'code' => 'CREATED',
                'name' => 'Заявка создана в Яндекс Доставке',
                'external_id' => (string) $requestId,
            ];

            $order->update([
                'metadata' => $metadata,
                'delivery_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);

            ShopOrderLog::createLog($order->id, 'Заявка Яндекс Доставки создана', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#16A34A',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => 'ID заявки: '.$requestId,
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Заявка Яндекс Доставки создана',
                'data' => [
                    'yandex_request_id' => $requestId,
                    'delivery_status' => $status,
                    'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                    'server_response' => $response,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка создания заявки Яндекс Доставки: '.$e->getMessage(), ['order_id' => $id]);

            return response()->json(['success' => false, 'message' => 'Ошибка создания заявки Яндекс Доставки: '.$e->getMessage()], 500);
        }
    }

    public function updateYandexDeliveryStatus(Request $request, $id): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($id);
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $requestId = trim((string) ($metadata['yandex_request_id'] ?? ''));

            if ($requestId === '') {
                return response()->json(['success' => false, 'message' => 'У заказа нет ID заявки Яндекс Доставки'], 400);
            }

            $settings = ShopCarrierDeliverySettings::getActive('yandex');
            if (! $settings || ! $settings->api_token) {
                return response()->json(['success' => false, 'message' => 'Активные настройки Яндекс Доставки не заполнены'], 400);
            }

            $response = $this->isYandexExpressMode($settings)
                ? $this->sendYandexExpressDeliveryRequest($settings, 'post', '/claims/info', [], ['claim_id' => $requestId])
                : $this->sendYandexDeliveryRequest($settings, 'get', '/request/info', [
                    'request_id' => $requestId,
                    'slim' => true,
                ]);

            $state = $response['state'] ?? $response['request']['state'] ?? [];
            $status = [
                'code' => $state['status'] ?? $response['status'] ?? 'UNKNOWN',
                'name' => $state['description'] ?? $state['status'] ?? $this->getYandexDeliveryStatusName((string) ($response['status'] ?? '')),
                'date' => $state['timestamp_utc'] ?? null,
                'reason' => $state['reason'] ?? null,
                'external_id' => $requestId,
                'sharing_url' => $response['sharing_url'] ?? null,
                'courier_order_id' => $response['courier_order_id'] ?? null,
            ];

            $metadata['yandex_status_response'] = $response;
            if (! empty($response['sharing_url'])) {
                $metadata['yandex_sharing_url'] = $response['sharing_url'];
            }
            if (! empty($response['courier_order_id'])) {
                $metadata['yandex_courier_order_id'] = $response['courier_order_id'];
            }

            $order->update([
                'metadata' => $metadata,
                'delivery_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);

            ShopOrderLog::createLog($order->id, 'Статус Яндекс Доставки обновлен', [
                'action_color' => '#FFFFFF',
                'action_bg_color' => '#2563EB',
                'section' => ShopOrderLog::SECTION_DELIVERY,
                'comment' => trim(($status['name'] ?? '').($status['reason'] ? ' / '.$status['reason'] : '')),
                'info' => "Заказ № {$order->order_number}",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Статус Яндекс Доставки обновлен',
                'data' => [
                    'delivery_status' => $status,
                    'yandex_request_id' => $requestId,
                    'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                    'server_response' => $response,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Ошибка проверки статуса Яндекс Доставки: '.$e->getMessage()], 500);
        }
    }

    private function buildYandexDeliveryOrderPayload(ShopOrder $order, ShopCarrierDeliverySettings $settings): array
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $deliveryType = ($metadata['yandex_delivery_type'] ?? '') === 'pickup_point' ? 'pickup_point' : 'address';
        $pickupPointId = trim((string) ($metadata['yandex_pickup_point_id'] ?? ''));
        $address = trim((string) ($metadata['yandex_delivery_address'] ?? $order->shipping_address ?? ''));

        if ($deliveryType === 'pickup_point' && $pickupPointId === '') {
            throw new \RuntimeException('Для создания заявки Яндекс Доставки до ПВЗ не выбран пункт выдачи');
        }

        if ($deliveryType === 'address' && $address === '') {
            throw new \RuntimeException('Для создания заявки Яндекс Доставки до адреса не указан адрес получателя');
        }

        $cargo = $this->buildYandexDeliveryCargo($order, $settings);
        $recipient = $this->splitYandexDeliveryCustomerName((string) $order->customer_name);
        $destination = $deliveryType === 'pickup_point'
            ? [
                'type' => 'platform_station',
                'platform_station' => ['platform_id' => $pickupPointId],
                'custom_location' => null,
                'interval_utc' => null,
            ]
            : [
                'type' => 'custom_location',
                'platform_station' => null,
                'custom_location' => [
                    'details' => [
                        'country' => 'Россия',
                        'locality' => $metadata['yandex_delivery_city'] ?? null,
                        'full_address' => $address,
                    ],
                ],
                'interval_utc' => null,
            ];

        return [
            'info' => array_filter([
                'operator_request_id' => (string) $order->order_number,
                'merchant_id' => trim((string) $settings->client_id) ?: null,
                'comment' => trim((string) ($order->comment ?? '')) ?: null,
            ], fn ($value) => $value !== null && $value !== ''),
            'source' => [
                'platform_station' => [
                    'platform_id' => (string) $settings->warehouse_id,
                ],
            ],
            'destination' => $destination,
            'items' => $cargo['items'],
            'places' => $cargo['places'],
            'billing_info' => [
                'payment_method' => $order->payed ? 'already_paid' : 'already_paid',
                'delivery_cost' => 0,
                'variable_delivery_cost_for_recipient' => [
                    [
                        'min_cost_of_accepted_items' => 1,
                        'delivery_cost' => 0,
                    ],
                ],
            ],
            'recipient_info' => array_filter([
                'first_name' => $recipient['first_name'],
                'last_name' => $recipient['last_name'],
                'patronymic' => $recipient['patronymic'],
                'phone' => $this->normalizeYandexDeliveryPhone((string) $order->customer_phone),
                'email' => $order->customer_email,
            ], fn ($value) => $value !== null && $value !== ''),
            'last_mile_policy' => $deliveryType === 'pickup_point' ? 'self_pickup' : 'time_interval',
            'particular_items_refuse' => false,
            'forbid_unboxing' => false,
        ];
    }

    private function createYandexExpressDeliveryOrder(ShopOrder $order, ShopCarrierDeliverySettings $settings): JsonResponse
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $payload = $this->buildYandexExpressDeliveryOrderPayload($order, $settings);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $response = $this->sendYandexExpressDeliveryRequest($settings, 'post', '/claims/create', $payload, ['request_id' => $requestId]);
        $claimId = $response['id'] ?? null;

        if (! $claimId) {
            return response()->json([
                'success' => false,
                'message' => 'Яндекс Экспресс принял ответ без ID заявки. Проверьте ответ API.',
                'data' => ['request_payload' => $payload, 'server_response' => $response],
            ], 422);
        }

        if (($response['status'] ?? '') === 'ready_for_approval') {
            $acceptResponse = $this->sendYandexExpressDeliveryRequest($settings, 'post', '/claims/accept', [
                'version' => (int) ($response['version'] ?? 1),
            ], ['claim_id' => $claimId]);
            $response['accept_response'] = $acceptResponse;
        }

        $metadata['yandex_request_id'] = (string) $claimId;
        $metadata['yandex_request_payload'] = $payload;
        $metadata['yandex_request_response'] = $response;
        $metadata['yandex_express_request_id'] = $requestId;

        $statusCode = $response['accept_response']['status'] ?? $response['status'] ?? 'CREATED';
        $status = [
            'code' => $statusCode,
            'name' => $this->getYandexDeliveryStatusName((string) $statusCode),
            'external_id' => (string) $claimId,
        ];

        $order->update([
            'metadata' => $metadata,
            'delivery_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
        ]);

        ShopOrderLog::createLog($order->id, 'Заявка Яндекс Экспресс создана', [
            'action_color' => '#FFFFFF',
            'action_bg_color' => '#16A34A',
            'section' => ShopOrderLog::SECTION_DELIVERY,
            'comment' => 'ID заявки: '.$claimId,
            'info' => "Заказ № {$order->order_number}",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Заявка Яндекс Экспресс создана',
            'data' => [
                'yandex_request_id' => $claimId,
                'delivery_status' => $status,
                'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                'server_response' => $response,
            ],
        ]);
    }

    private function buildYandexExpressDeliveryOrderPayload(ShopOrder $order, ShopCarrierDeliverySettings $settings): array
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $settingsData = is_array($settings->settings) ? $settings->settings : [];
        $address = trim((string) ($metadata['yandex_delivery_address'] ?? $order->shipping_address ?? ''));
        if ($address === '') {
            throw new \RuntimeException('Для создания заявки Яндекс Экспресс не указан адрес получателя');
        }

        $sourceAddress = trim(implode(', ', array_filter([
            $settings->sender_city,
            $settings->sender_street,
            $settings->sender_house ? 'д. '.$settings->sender_house : null,
        ])));
        if ($sourceAddress === '') {
            throw new \RuntimeException('В настройках Яндекс Доставки укажите адрес отправителя');
        }

        $cargo = $this->buildYandexExpressDeliveryCargo($order, $settings);
        $offerPayload = $metadata['yandex_delivery_tariff']['raw']['payload']
            ?? $metadata['yandex_delivery_metadata']['tariff']['raw']['payload']
            ?? null;

        return array_filter([
            'items' => $cargo['items'],
            'route_points' => [
                $this->buildYandexExpressClaimPoint(1, 'source', 1, $sourceAddress, (string) $settings->sender_city, 'Склад', (string) ($settingsData['sender_phone'] ?? $order->customer_phone)),
                $this->buildYandexExpressClaimPoint(2, 'destination', 2, $address, (string) ($metadata['yandex_delivery_city'] ?? ''), (string) $order->customer_name, (string) $order->customer_phone, (string) $order->customer_email),
            ],
            'emergency_contact' => [
                'name' => 'Склад',
                'phone' => $this->normalizeYandexDeliveryPhone((string) ($settingsData['sender_phone'] ?? $order->customer_phone)),
            ],
            'client_requirements' => [
                'taxi_class' => $settingsData['express_taxi_class'] ?? 'express',
                'pro_courier' => (bool) ($settingsData['express_pro_courier'] ?? false),
            ],
            'skip_door_to_door' => false,
            'skip_client_notify' => false,
            'skip_emergency_notify' => false,
            'skip_act' => false,
            'optional_return' => false,
            'comment' => trim((string) ($order->comment ?? '')) ?: null,
            'auto_accept' => true,
            'offer_payload' => $offerPayload,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function buildYandexExpressDeliveryCargo(ShopOrder $order, ShopCarrierDeliverySettings $settings): array
    {
        $defaultWeight = max(0.01, (float) ($settings->default_weight ?? 0.5));
        $defaultLength = max(1, (float) ($settings->default_length ?? 10));
        $defaultWidth = max(1, (float) ($settings->default_width ?? 10));
        $defaultHeight = max(1, (float) ($settings->default_height ?? 10));
        $items = is_array($order->items) ? $order->items : (json_decode((string) $order->items, true) ?: []);
        $result = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $price = max(0, (float) ($item['price'] ?? $item['final_price'] ?? 0));
            $weight = $this->positiveDeliveryNumber($item['weight'] ?? null) ?? $defaultWeight;
            $length = $this->positiveDeliveryNumber($item['length'] ?? ($item['depth'] ?? null)) ?? $defaultLength;
            $width = $this->positiveDeliveryNumber($item['width'] ?? null) ?? $defaultWidth;
            $height = $this->positiveDeliveryNumber($item['height'] ?? null) ?? $defaultHeight;
            $result[] = [
                'extra_id' => (string) ($item['sku'] ?? $item['article'] ?? $item['good_id'] ?? $index),
                'pickup_point' => 1,
                'dropoff_point' => 2,
                'title' => mb_substr((string) ($item['name'] ?? $item['good_name'] ?? 'Товар'), 0, 255),
                'size' => [
                    'length' => round(max(1, $length) / 100, 3),
                    'width' => round(max(1, $width) / 100, 3),
                    'height' => round(max(1, $height) / 100, 3),
                ],
                'weight' => max(0.01, $weight),
                'cost_value' => number_format($price, 2, '.', ''),
                'cost_currency' => 'RUB',
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'age_restricted' => false,
            ];
        }

        return ['items' => $result ?: [[
            'pickup_point' => 1,
            'dropoff_point' => 2,
            'title' => 'Заказ '.$order->order_number,
            'size' => [
                'length' => round($defaultLength / 100, 3),
                'width' => round($defaultWidth / 100, 3),
                'height' => round($defaultHeight / 100, 3),
            ],
            'weight' => $defaultWeight,
            'cost_value' => number_format((float) $order->total_amount, 2, '.', ''),
            'cost_currency' => 'RUB',
            'quantity' => 1,
            'age_restricted' => false,
        ]]];
    }

    private function buildYandexExpressClaimPoint(int $pointId, string $type, int $visitOrder, string $address, string $city, string $contactName, string $phone, string $email = ''): array
    {
        return array_filter([
            'point_id' => $pointId,
            'visit_order' => $visitOrder,
            'contact' => array_filter([
                'name' => $contactName ?: 'Контакт',
                'phone' => $this->normalizeYandexDeliveryPhone($phone),
                'email' => $email ?: null,
            ]),
            'address' => array_filter([
                'fullname' => $address,
                'country' => 'Россия',
                'city' => $this->normalizeYandexDeliverySettlementName($city),
            ]),
            'type' => $type,
            'skip_confirmation' => false,
        ]);
    }

    private function buildYandexDeliveryCargo(ShopOrder $order, ShopCarrierDeliverySettings $settings): array
    {
        $items = is_array($order->items) ? $order->items : (json_decode((string) $order->items, true) ?: []);
        $packages = app(DeliveryPackageService::class)->fromOrder($order, $settings);
        $resourceItems = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = max(0, (float) ($item['price'] ?? $item['final_price'] ?? 0));
            $package = collect($packages)->first(fn ($candidate) => collect($candidate['items'] ?? [])->contains(
                fn ($packageItem) => (int) ($packageItem['item_index'] ?? -1) === $index
            )) ?? $packages[0];
            $barcode = 'SS-'.$order->id.'-'.$package['number'];

            $resourceItems[] = [
                'count' => $quantity,
                'name' => mb_substr((string) ($item['name'] ?? $item['good_name'] ?? 'Товар'), 0, 255),
                'article' => mb_substr((string) ($item['sku'] ?? $item['article'] ?? $item['good_id'] ?? $index), 0, 100),
                'billing_details' => [
                    'unit_price' => (int) round($unitPrice * 100),
                    'assessed_unit_price' => (int) round($unitPrice * 100),
                ],
                'physical_dims' => [
                    'dx' => (int) ceil($package['length']),
                    'dy' => (int) ceil($package['height']),
                    'dz' => (int) ceil($package['width']),
                ],
                'place_barcode' => $barcode,
                'fitting' => false,
            ];
        }

        if (! $resourceItems) {
            $package = $packages[0];
            $resourceItems[] = [
                'count' => 1,
                'name' => 'Заказ '.$order->order_number,
                'article' => (string) $order->order_number,
                'billing_details' => [
                    'unit_price' => (int) round((float) $order->total_amount * 100),
                    'assessed_unit_price' => (int) round((float) $order->total_amount * 100),
                ],
                'physical_dims' => [
                    'dx' => (int) ceil($package['length']),
                    'dy' => (int) ceil($package['height']),
                    'dz' => (int) ceil($package['width']),
                ],
                'place_barcode' => 'SS-'.$order->id.'-'.$package['number'],
                'fitting' => false,
            ];
        }

        return [
            'items' => $resourceItems,
            'places' => array_map(fn (array $package) => [
                'physical_dims' => [
                    'weight_gross' => max(1, (int) round($package['weight'] * 1000)),
                    'dx' => (int) ceil($package['length']),
                    'dy' => (int) ceil($package['height']),
                    'dz' => (int) ceil($package['width']),
                ],
                'barcode' => 'SS-'.$order->id.'-'.$package['number'],
            ], $packages),
        ];
    }

    private function sendYandexDeliveryRequest(ShopCarrierDeliverySettings $settings, string $method, string $path, array $payload = []): array
    {
        $baseUrl = $this->normalizeYandexDeliveryBaseUrl((string) $settings->api_url);
        $url = $baseUrl.$path;
        $query = $this->yandexDeliveryMerchantQuery($settings);
        $requestUrl = strtolower($method) === 'get' ? $url : $this->appendQueryToUrl($url, $query);
        $request = Http::withToken((string) $settings->api_token)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $response = strtolower($method) === 'get'
            ? $request->get($url, array_merge($query, $payload))
            : $request->post($requestUrl, $payload);

        if (! $response->successful()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? $response->json('error_description')
                ?? $response->body()
                ?: 'API Яндекс Доставки вернул ошибку';
            Log::warning('Yandex Delivery order API error', [
                'url' => $requestUrl,
                'status' => $response->status(),
                'query' => $query,
                'payload' => $payload,
                'response' => $response->json() ?: $response->body(),
            ]);
            throw new \RuntimeException(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE), $response->status());
        }

        return $response->json() ?: [];
    }

    private function yandexDeliveryMerchantQuery(ShopCarrierDeliverySettings $settings): array
    {
        $settingsData = is_array($settings->settings) ? $settings->settings : [];
        $merchantId = trim((string) ($settings->client_id ?: ($settingsData['merchant_id'] ?? '')));

        return $merchantId !== '' ? ['merchant_id' => $merchantId] : [];
    }

    private function appendQueryToUrl(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    private function sendYandexExpressDeliveryRequest(ShopCarrierDeliverySettings $settings, string $method, string $path, array $payload = [], array $query = []): array
    {
        $baseUrl = $this->normalizeYandexExpressDeliveryBaseUrl((string) $settings->api_url);
        $url = $baseUrl.$path;
        $request = Http::withToken((string) $settings->api_token)
            ->withHeaders(['Accept-Language' => 'ru'])
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $response = strtolower($method) === 'get'
            ? $request->get($url, $query ?: $payload)
            : $request->post($url.($query ? '?'.http_build_query($query) : ''), $payload);

        if (! $response->successful()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? $response->json('error_description')
                ?? $response->body()
                ?: 'API Яндекс Экспресс вернул ошибку';
            Log::warning('Yandex Express Delivery order API error', [
                'url' => $url,
                'status' => $response->status(),
                'payload' => $payload,
                'query' => $query,
                'response' => $response->json() ?: $response->body(),
            ]);
            throw new \RuntimeException(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE), $response->status());
        }

        return $response->json() ?: [];
    }

    private function normalizeYandexDeliveryBaseUrl(string $apiUrl): string
    {
        $apiUrl = trim($apiUrl) ?: 'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform';
        $apiUrl = rtrim($apiUrl, '/');

        foreach ([
            '/merchant/info',
            '/pricing-calculator',
            '/location/detect',
            '/pickup-points/list',
            '/offers/create',
            '/offers/confirm',
            '/request/create',
            '/request/info',
        ] as $methodPath) {
            if (str_ends_with($apiUrl, $methodPath)) {
                return substr($apiUrl, 0, -strlen($methodPath));
            }
        }

        return $apiUrl;
    }

    private function normalizeYandexExpressDeliveryBaseUrl(string $apiUrl): string
    {
        $default = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2';
        $apiUrl = trim($apiUrl);
        if ($apiUrl === '' || str_contains($apiUrl, '/api/b2b/platform')) {
            return $default;
        }
        $apiUrl = rtrim($apiUrl, '/');

        foreach ([
            '/offers/calculate',
            '/claims/create',
            '/claims/accept',
            '/claims/info',
            '/claims/cancel',
        ] as $methodPath) {
            if (str_ends_with($apiUrl, $methodPath)) {
                return substr($apiUrl, 0, -strlen($methodPath));
            }
        }

        return $apiUrl;
    }

    private function isYandexExpressMode(ShopCarrierDeliverySettings $settings): bool
    {
        $settingsData = is_array($settings->settings) ? $settings->settings : [];

        return ($settingsData['api_mode'] ?? 'other_day') === 'express';
    }

    private function normalizeYandexDeliverySettlementName(string $city): string
    {
        $city = trim(preg_replace('/\s+/u', ' ', $city));
        $city = preg_replace('/^(г|город|д|деревня|пос|поселок|посёлок|пгт|с|село)\.?\s+/iu', '', $city);

        return trim($city);
    }

    private function getYandexDeliveryStatusName(string $status): string
    {
        return match ($status) {
            'new' => 'Заявка создана',
            'estimating' => 'Оценка заявки',
            'estimating_failed' => 'Оценка не прошла',
            'ready_for_approval' => 'Ожидает подтверждения',
            'accepted' => 'Заявка подтверждена',
            'performer_lookup' => 'Поиск исполнителя',
            'performer_found' => 'Исполнитель найден',
            'performer_not_found' => 'Исполнитель не найден',
            'pickup_arrived' => 'Курьер прибыл на забор',
            'pickuped' => 'Заказ забран',
            'delivery_arrived' => 'Курьер прибыл к получателю',
            'delivered', 'delivered_finish' => 'Доставлено',
            'returning' => 'Возврат',
            'returned', 'returned_finish' => 'Возвращено',
            'failed' => 'Ошибка доставки',
            'cancelled' => 'Отменено',
            'cancelled_with_payment' => 'Отменено с оплатой',
            default => $status ?: 'Статус Яндекс Доставки обновлен',
        };
    }

    private function normalizeYandexDeliveryPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        return $digits ? '+'.$digits : $phone;
    }

    private function splitYandexDeliveryCustomerName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'last_name' => $parts[0] ?? '',
            'first_name' => $parts[1] ?? ($parts[0] ?? 'Покупатель'),
            'patronymic' => $parts[2] ?? '',
        ];
    }

    private function fetchRussianPostTrackingStatus(ShopOrder $order): array
    {
        $settings = ShopRussianPostSettings::getActive();
        if (! $settings || ! $settings->login || ! $settings->password) {
            throw new \RuntimeException('Активные настройки Почты России заполнены не полностью');
        }

        [$login, $password] = $this->russianPostTrackingCredentials($settings);
        $barcode = preg_replace('/\s+/', '', (string) $order->russianpost_barcode);
        $payload = $this->buildRussianPostTrackingSoapPayload($barcode, $login, $password);

        $response = Http::withOptions([
            'verify' => filter_var(config('services.russianpost.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
            'timeout' => 30,
        ])->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'Accept' => 'text/xml, application/xml',
            'SOAPAction' => 'getOperationHistory',
        ])->withBody($payload, 'text/xml; charset=utf-8')->post('https://tracking.russianpost.ru/fc');

        if (! $response->successful()) {
            throw new \RuntimeException($this->extractRussianPostTrackingError($response->body()) ?: 'Почта России не вернула историю отправления');
        }

        $trackingError = $this->extractRussianPostTrackingError($response->body());
        if ($trackingError) {
            throw new \RuntimeException($trackingError);
        }

        $history = $this->parseRussianPostTrackingHistory($response->body());
        if (empty($history)) {
            return [
                'code' => 'NO_HISTORY',
                'name' => 'История отправления пока не найдена',
                'external_id' => $order->russianpost_order_id,
                'barcode' => $barcode,
                'tracking_available' => true,
                'history' => [],
            ];
        }

        $last = end($history) ?: [];
        $operation = trim(implode(' / ', array_filter([
            $last['operation_type'] ?? null,
            $last['operation_attribute'] ?? null,
        ])));

        return [
            'code' => $last['operation_type'] ?? 'TRACKING',
            'name' => $operation ?: 'Статус Почты России обновлен',
            'date' => $last['date'] ?? null,
            'city' => $last['city'] ?? null,
            'office_index' => $last['office_index'] ?? null,
            'external_id' => $order->russianpost_order_id,
            'barcode' => $barcode,
            'tracking_available' => true,
            'history' => $history,
        ];
    }

    private function russianPostTrackingCredentials(ShopRussianPostSettings $settings): array
    {
        $login = (string) $settings->login;
        $password = trim((string) $settings->password);
        $value = str_starts_with(mb_strtolower($password), 'basic ')
            ? trim(mb_substr($password, 6))
            : $password;

        $decoded = base64_decode($value, true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$decodedLogin, $decodedPassword] = explode(':', $decoded, 2);
            return [$decodedLogin ?: $login, $decodedPassword];
        }

        return [$login, $password];
    }

    private function buildRussianPostTrackingSoapPayload(string $barcode, string $login, string $password): string
    {
        $barcode = htmlspecialchars($barcode, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $login = htmlspecialchars($login, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $password = htmlspecialchars($password, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:oper="http://russianpost.org/operationhistory" xmlns:data="http://russianpost.org/operationhistory/data">
  <soapenv:Header/>
  <soapenv:Body>
    <oper:getOperationHistory>
      <data:OperationHistoryRequest>
        <data:Barcode>{$barcode}</data:Barcode>
        <data:MessageType>0</data:MessageType>
        <data:Language>RUS</data:Language>
      </data:OperationHistoryRequest>
      <data:AuthorizationHeader soapenv:mustUnderstand="1">
        <data:login>{$login}</data:login>
        <data:password>{$password}</data:password>
      </data:AuthorizationHeader>
    </oper:getOperationHistory>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function parseRussianPostTrackingHistory(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $root) {
            return [];
        }

        $records = $root->xpath('//*[local-name()="historyRecord"]') ?: [];
        $history = [];

        foreach ($records as $record) {
            $history[] = [
                'date' => $this->xmlFindText($record, 'OperationParameters', 'OperationDate'),
                'operation_type' => $this->xmlFindText($record, 'OperationParameters', 'OperType', 'Name'),
                'operation_attribute' => $this->xmlFindText($record, 'OperationParameters', 'OperAttr', 'Name'),
                'city' => $this->xmlFindText($record, 'AddressParameters', 'OperationAddress', 'Description'),
                'office_index' => $this->xmlFindText($record, 'AddressParameters', 'OperationAddress', 'Index'),
            ];
        }

        usort($history, function ($a, $b) {
            return strtotime((string) ($a['date'] ?? '')) <=> strtotime((string) ($b['date'] ?? ''));
        });

        return $history;
    }

    private function extractRussianPostTrackingError(string $xml): ?string
    {
        if ($xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $root) {
            return null;
        }

        return $this->xmlFindText($root, 'faultstring')
            ?: $this->xmlFindText($root, 'Message');
    }

    private function xmlFindText(\SimpleXMLElement $node, string ...$path): ?string
    {
        $current = $node;
        foreach ($path as $name) {
            $found = $current->xpath('./*[local-name()="'.$name.'"]');
            if (! $found || ! isset($found[0])) {
                return null;
            }
            $current = $found[0];
        }

        $value = trim((string) $current);
        return $value !== '' ? $value : null;
    }

    private function buildDellinOrderPayload(ShopOrder $order, ShopDellinSettings $settings, string $deliveryType, ?string $terminalId, ?string $deliveryAddress): array
    {
        $cargo = $this->calculateOrderDeliveryCargo($order, $settings);
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $senderTerminal = $metadata['dellin_sender_terminal_id'] ?? null;
        $senderPhone = $this->formatDellinPhoneNumber($settings->sender_phone);
        $senderReceiptPhone = $this->formatDellinReceiptPhone($settings->sender_phone);
        $receiverPhone = $this->formatDellinPhoneNumber($order->customer_phone);
        $receiverReceiptPhone = $this->formatDellinReceiptPhone($order->customer_phone);
        $senderEmail = trim((string) $settings->sender_email);
        $receiverEmail = trim((string) $order->customer_email);
        $senderAddress = trim(implode(', ', array_filter([
            $settings->sender_city,
            $settings->sender_street,
            $settings->sender_house ? 'д. '.$settings->sender_house : null,
            $settings->sender_flat ? 'оф. '.$settings->sender_flat : null,
        ])));

        $payload = [
            'appkey' => $settings->appkey,
            'inOrder' => true,
            'delivery' => [
                'deliveryType' => ['type' => 'auto'],
                'derival' => [
                    'produceDate' => now()->addDay()->format('Y-m-d'),
                    'variant' => $senderTerminal ? 'terminal' : 'address',
                    'payer' => 'sender',
                    'time' => [
                        'worktimeStart' => '09:00',
                        'worktimeEnd' => '18:00',
                    ],
                ],
                'arrival' => [
                    'variant' => $deliveryType === 'terminal' ? 'terminal' : 'address',
                    'payer' => 'sender',
                ],
            ],
            'cargo' => array_merge($cargo, [
                'freightName' => 'Спортивные товары',
            ]),
            'members' => [
                'requester' => [
                    'role' => 'sender',
                    'email' => $senderEmail ?: $receiverEmail,
                ],
                'sender' => [
                    'counteragent' => [
                        'customForm' => $this->buildDellinCustomForm($settings->sender_company ?: $settings->sender_name, true),
                        'name' => $settings->sender_company ?: $settings->sender_name,
                        'inn' => $settings->sender_inn,
                        'juridicalAddress' => $senderAddress !== '' ? ['search' => $senderAddress] : null,
                        'save' => false,
                    ],
                    'contactPersons' => [
                        [
                            'name' => $settings->sender_name ?: $settings->sender_company,
                            'save' => false,
                        ],
                    ],
                    'phoneNumbers' => [
                        [
                            'number' => $senderPhone,
                            'save' => false,
                        ],
                    ],
                    'email' => $senderEmail,
                    'dataForReceipt' => [
                        'send' => (bool) ($senderReceiptPhone || $senderEmail),
                        'phone' => $senderReceiptPhone ?: null,
                        'email' => $senderEmail ?: null,
                    ],
                ],
                'receiver' => [
                    'counteragent' => [
                        'form' => self::DELLIN_PERSON_FORM_UID,
                        'isAnonym' => true,
                        'phone' => $receiverPhone,
                        'name' => $order->customer_name,
                        'save' => false,
                    ],
                    'contactPersons' => [
                        [
                            'name' => $order->customer_name ?: 'Получатель',
                            'save' => false,
                        ],
                    ],
                    'phoneNumbers' => [
                        [
                            'number' => $receiverPhone,
                            'save' => false,
                        ],
                    ],
                    'email' => $receiverEmail,
                    'dataForReceipt' => [
                        'send' => (bool) ($receiverReceiptPhone || $receiverEmail),
                        'phone' => $receiverReceiptPhone ?: null,
                        'email' => $receiverEmail ?: null,
                    ],
                ],
            ],
            'payment' => [
                'type' => 'noncash',
                'primaryPayer' => 'sender',
            ],
        ];

        if ($settings->auth_type !== 'appkey' || $settings->counteragent_uid) {
            $sessionId = $this->getDellinSessionId($settings);
            if ($settings->counteragent_uid && ! $sessionId) {
                throw new \RuntimeException('Для выбранного контрагента ДЛ нужна авторизация через PAT или логин и пароль. Проверьте настройки доставки.');
            }

            if ($sessionId) {
                $this->activateDellinCounteragent($settings, $sessionId);
                $payload['sessionID'] = $sessionId;
            }
        }

        if ($senderTerminal) {
            $payload['delivery']['derival']['terminalID'] = (string) $senderTerminal;
        } else {
            $payload['delivery']['derival']['address'] = [
                'search' => $senderAddress,
            ];
        }

        if ($deliveryType === 'terminal') {
            $payload['delivery']['arrival']['terminalID'] = (string) $terminalId;
        } else {
            $payload['delivery']['arrival']['address'] = ['search' => $deliveryAddress];
            $payload['delivery']['arrival']['time'] = [
                'worktimeStart' => '09:00',
                'worktimeEnd' => '18:00',
            ];
        }

        return $this->filterDellinPayload($payload);
    }

    private function buildDellinCustomForm(?string $name, bool $juridical): array
    {
        $normalized = mb_strtoupper(trim((string) $name));
        $formName = 'ФЛ';

        if ($juridical) {
            $formName = str_starts_with($normalized, 'ИП ') || $normalized === 'ИП' ? 'ИП' : 'ООО';
        }

        return [
            'formName' => $formName,
            'countryUID' => self::DELLIN_RUSSIA_COUNTRY_UID,
            'juridical' => $juridical,
        ];
    }

    private function formatDellinPhoneNumber(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) === 10) {
            return '7'.$digits;
        }

        if (strlen($digits) === 11 && $digits[0] === '8') {
            return '7'.substr($digits, 1);
        }

        return $digits;
    }

    private function formatDellinReceiptPhone(?string $phone): string
    {
        $digits = $this->formatDellinPhoneNumber($phone);

        if (preg_match('/^79\d{9}$/', $digits)) {
            return '+'.$digits;
        }

        return '';
    }

    private function filterDellinPayload($value)
    {
        if (is_array($value)) {
            $filtered = [];
            foreach ($value as $key => $item) {
                $cleanItem = $this->filterDellinPayload($item);
                if ($cleanItem !== null && $cleanItem !== '' && $cleanItem !== []) {
                    $filtered[$key] = $cleanItem;
                }
            }

            return $filtered;
        }

        return $value;
    }

    private function buildRussianPostOrderPayload(ShopOrder $order, ShopRussianPostSettings $settings, string $postalCode, ?array $package = null): array
    {
        $cargo = $this->calculateRussianPostOrderCargo($order, $settings);
        $package ??= $cargo['packages'][0] ?? $cargo;
        [$surname, $givenName, $middleName] = $this->splitCustomerName($order->customer_name);
        $indexFrom = preg_replace('/\D+/', '', (string) $settings->sender_postal_code);
        $postOfficeCode = $this->resolveRussianPostSenderPostOfficeCode($order, $settings);
        $mailDirect = $this->resolveRussianPostMailDirect($order);
        $address = $this->resolveRussianPostRecipientAddress($order);
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $isCourierDelivery = ($metadata['russianpost_delivery_type'] ?? '') === 'address';

        return array_filter([
            'address-type-to' => 'DEFAULT',
            'given-name' => $givenName,
            'surname' => $surname,
            'middle-name' => $middleName,
            'index-from' => $indexFrom,
            'index-to' => $postalCode,
            'postoffice-code' => $postOfficeCode,
            'mail-direct' => $mailDirect,
            'region-to' => $address['region'] ?? null,
            'place-to' => $address['place'] ?? null,
            'street-to' => $address['street'] ?? null,
            'house-to' => $address['house'] ?? null,
            'slash-to' => $address['slash'] ?? null,
            'room-to' => $address['room'] ?? null,
            'mail-category' => ! empty($package['cash_on_delivery_amount'])
                ? 'WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY'
                : (! empty($package['declared_value']) ? 'WITH_DECLARED_VALUE' : 'ORDINARY'),
            'mail-type' => $isCourierDelivery ? 'ONLINE_COURIER' : 'ONLINE_PARCEL',
            'mass' => (int) max(1, round($package['weight'] * 1000)),
            'order-num' => $order->order_number.'-'.($package['number'] ?? 1),
            'tel-address' => preg_replace('/\D+/', '', (string) $order->customer_phone),
            'dimension' => [
                'length' => (int) max(1, ceil($package['length'])),
                'width' => (int) max(1, ceil($package['width'])),
                'height' => (int) max(1, ceil($package['height'])),
            ],
            'fragile' => false,
            'courier' => $isCourierDelivery,
            'completeness-checking' => false,
            'sms-notice-recipient' => 0,
            // Объявленная ценность передаётся в рублях, наложенный платёж — в копейках.
            'declared-value' => ! empty($package['declared_value'])
                ? (int) max(1, round((float) $package['declared_value']))
                : null,
            'payment' => ! empty($package['cash_on_delivery_amount'])
                ? (int) max(1, round((float) $package['cash_on_delivery_amount'] * 100))
                : null,
            'payment-method' => ! empty($package['cash_on_delivery_amount']) ? 'CASHLESS' : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function withRussianPostPackageValues(ShopOrder $order, ShopRussianPostSettings $settings, array $packages): array
    {
        $items = is_array($order->items) ? $order->items : [];
        $unitValues = [];
        foreach ($items as $itemIndex => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = is_numeric($item['total'] ?? null)
                ? (float) $item['total']
                : (float) ($item['price'] ?? $item['final_price'] ?? 0) * $quantity;
            $unitValues[$itemIndex] = max(0, $lineTotal / $quantity);
        }

        $totalDeclaredValue = 0.0;
        foreach ($packages as &$package) {
            $declaredValue = 0.0;
            foreach ((array) ($package['items'] ?? []) as $packageItem) {
                $itemIndex = $packageItem['item_index'] ?? null;
                if ($itemIndex !== null && array_key_exists($itemIndex, $unitValues)) {
                    $declaredValue += $unitValues[$itemIndex] * max(1, (int) ($packageItem['quantity'] ?? 1));
                }
            }
            $package['declared_value'] = round($declaredValue, 2);
            $totalDeclaredValue += $package['declared_value'];
        }
        unset($package);

        if (! $this->isRussianPostCashOnDelivery($order, $settings) || $totalDeclaredValue <= 0) {
            return $packages;
        }

        $remainingPayment = round(max(0, (float) $order->total_amount), 2);
        $lastIndex = array_key_last($packages);
        foreach ($packages as $index => &$package) {
            $payment = $index === $lastIndex
                ? $remainingPayment
                : round($remainingPayment * ((float) $package['declared_value'] / $totalDeclaredValue), 2);
            $package['cash_on_delivery_amount'] = $payment;
            $remainingPayment = round($remainingPayment - $payment, 2);
        }
        unset($package);

        return $packages;
    }

    private function isRussianPostCashOnDelivery(ShopOrder $order, ShopRussianPostSettings $settings): bool
    {
        if (! $settings->cash_on_delivery_enabled || $order->payed) {
            return false;
        }

        $paymentMethod = $order->paymentMethod;
        $type = mb_strtolower((string) ($paymentMethod?->type ?? ''));
        $name = mb_strtolower((string) ($paymentMethod?->name ?? $order->payment_method ?? ''));

        return $type === 'cash'
            || str_contains($name, 'при получении')
            || str_contains($name, 'налож');
    }

    private function russianPostResponseHasErrors($data): bool
    {
        return is_array($data) && ! empty($data['errors']);
    }

    private function resolveRussianPostSenderPostOfficeCode(ShopOrder $order, ShopRussianPostSettings $settings): string
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $candidates = [
            $metadata['russianpost_sender_postoffice_code'] ?? null,
            $metadata['russianpost_postoffice_code'] ?? null,
            $metadata['russianpost_tariff']['postoffice-code'] ?? null,
            $metadata['russianpost_tariff']['postoffice_code'] ?? null,
            $settings->sender_postoffice_code ?? null,
            $settings->sender_postal_code,
        ];

        foreach ($candidates as $candidate) {
            $code = $this->extractRussianPostPostalCode($candidate);
            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    private function resolveRussianPostMailDirect(ShopOrder $order): int
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $value = $metadata['russianpost_mail_direct'] ?? $metadata['russianpost_tariff']['mail-direct'] ?? null;

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        // 643 = Российская Федерация. Заказы магазина сейчас создаются как внутрироссийские отправления.
        return 643;
    }

    private function resolveRussianPostRecipientAddress(ShopOrder $order): array
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $office = is_array($metadata['russianpost_office'] ?? null) ? $metadata['russianpost_office'] : [];
        $rawOffice = is_array($office['raw'] ?? null) ? $office['raw'] : [];
        $addressText = trim((string) ($metadata['russianpost_address'] ?? $order->shipping_address ?? ''));

        $address = [
            'region' => $this->firstNonEmptyScalar(
                $metadata['russianpost_region'] ?? null,
                $metadata['region'] ?? null,
                $rawOffice['region'] ?? null,
                $rawOffice['region-to'] ?? null
            ),
            'place' => $this->firstNonEmptyScalar(
                $metadata['russianpost_place'] ?? null,
                $metadata['russianpost_city'] ?? null,
                $metadata['city'] ?? null,
                $rawOffice['settlement'] ?? null,
                $rawOffice['place-to'] ?? null,
                $rawOffice['place'] ?? null
            ),
            'street' => $this->firstNonEmptyScalar($metadata['street'] ?? null, $metadata['russianpost_street'] ?? null),
            'house' => $this->firstNonEmptyScalar($metadata['house'] ?? null, $metadata['russianpost_house'] ?? null),
            'slash' => $this->firstNonEmptyScalar($metadata['slash'] ?? null, $metadata['russianpost_slash'] ?? null),
            'room' => $this->firstNonEmptyScalar($metadata['room'] ?? null, $metadata['apartment'] ?? null, $metadata['russianpost_room'] ?? null),
        ];

        $parsed = $this->parseRussianPostAddressText($addressText);

        foreach ($parsed as $key => $value) {
            if (($address[$key] ?? '') === '' && $value !== '') {
                $address[$key] = $value;
            }
        }

        return $address;
    }

    private function parseRussianPostAddressText(string $address): array
    {
        $address = trim(preg_replace('/^Почта\s+России:\s*/iu', '', $address));
        $address = trim(preg_replace('/^\d{6}\s*,?\s*/', '', $address));

        if ($address === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($part) => $part !== ''));
        $result = [
            'region' => '',
            'place' => '',
            'street' => '',
            'house' => '',
            'slash' => '',
            'room' => '',
        ];

        foreach ($parts as $part) {
            if ($result['region'] === '' && preg_match('/\b(обл\.?|область|край|респ\.?|республика|ао|автономный округ)\b/iu', $part)) {
                $result['region'] = $this->normalizeRussianPostAddressPart($part, 'region');
                continue;
            }

            if ($result['place'] === '' && preg_match('/\b(г\.?|город|пос\.?|поселок|с\.|село|д\.|деревня|пгт)\b/iu', $part)) {
                $result['place'] = $this->normalizeRussianPostAddressPart($part, 'place');
                continue;
            }

            if ($result['street'] === '' && preg_match('/\b(ул\.?|улица|пр-кт|проспект|пер\.?|переулок|ш\.?|шоссе|наб\.?|набережная)\b/iu', $part)) {
                $result['street'] = $this->normalizeRussianPostAddressPart($part, 'street');
                continue;
            }

            if ($result['house'] === '' && preg_match('/\b(?:д\.?|дом)\s*([0-9а-яa-z\/-]+)/iu', $part, $matches)) {
                $result['house'] = $matches[1];
                continue;
            }

            if ($result['room'] === '' && preg_match('/\b(?:кв\.?|квартира|оф\.?|офис)\s*([0-9а-яa-z\/-]+)/iu', $part, $matches)) {
                $result['room'] = $matches[1];
            }
        }

        if ($result['place'] === '') {
            foreach ($parts as $part) {
                if (! preg_match('/\b(ул\.?|улица|д\.?|дом|кв\.?|квартира|оф\.?|офис)\b/iu', $part)) {
                    $result['place'] = $this->normalizeRussianPostAddressPart($part, 'place');
                    break;
                }
            }
        }

        if ($result['region'] === '' && $result['place'] !== '') {
            $result['region'] = $result['place'];
        }

        return $result;
    }

    private function normalizeRussianPostAddressPart(string $value, string $type): string
    {
        $value = trim($value);

        $patterns = [
            'region' => '/\b(обл\.?|область|край|респ\.?|республика|ао|автономный округ)\b\.?/iu',
            'place' => '/\b(г\.?|город|пос\.?|поселок|с\.|село|д\.|деревня|пгт)\b\.?/iu',
            'street' => '/\b(ул\.?|улица|пр-кт|проспект|пер\.?|переулок|ш\.?|шоссе|наб\.?|набережная)\b\.?/iu',
        ];

        if (isset($patterns[$type])) {
            $value = preg_replace($patterns[$type], '', $value);
        }

        return trim(preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B,.");
    }

    private function firstNonEmptyScalar(...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function resolveRussianPostPostalCode(ShopOrder $order, Request $request): string
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];

        $candidates = [
            $request->input('postal_code'),
            $request->input('russianpost_postal_code'),
            $metadata['russianpost_postal_code'] ?? null,
            $metadata['russianpost_office']['postal_code'] ?? null,
            $metadata['russianpost_office']['id'] ?? null,
            $metadata['russianpost_office']['raw']['postal-code'] ?? null,
            $metadata['russianpost_office'] ?? null,
            $metadata['russianpost_tariff']['postal_code'] ?? null,
            $metadata['russianpost_tariff'] ?? null,
            $metadata['russianpost_address'] ?? null,
            $order->shipping_address,
        ];

        foreach ($candidates as $candidate) {
            $postalCode = $this->extractRussianPostPostalCode($candidate);
            if ($postalCode !== '') {
                return $postalCode;
            }
        }

        return '';
    }

    private function extractRussianPostCreationIdentifiers($data): array
    {
        $barcodeKeys = [
            'barcode',
            'barcode-item',
            'barcodeItem',
            'mail-id',
            'mailId',
            'tracking-number',
            'trackingNumber',
            'tracking',
        ];
        $idKeys = [
            'id',
            'external-id',
            'externalId',
            'order-id',
            'orderId',
            'shipment-id',
            'shipmentId',
            'result-id',
            'resultId',
            'result-ids',
            'resultIds',
        ];

        $barcode = $this->findScalarByKeys($data, $barcodeKeys);
        $externalId = $this->findScalarByKeys($data, $idKeys);

        return [
            $externalId !== null ? (string) $externalId : null,
            $barcode !== null ? (string) $barcode : null,
        ];
    }

    private function findScalarByKeys($data, array $keys)
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (! is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $found = $this->findFirstNonEmptyScalar($data[$key]);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value) || is_object($value)) {
                $found = $this->findScalarByKeys($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function findFirstNonEmptyScalar($value)
    {
        if ($this->isNonEmptyScalar($value)) {
            return $value;
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $found = $this->findFirstNonEmptyScalar($item);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isNonEmptyScalar($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function extractRussianPostPostalCode($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            foreach (['postal_code', 'postal-code', 'postalCode', 'index', 'id', 'code'] as $key) {
                if (array_key_exists($key, $value)) {
                    $postalCode = $this->extractRussianPostPostalCode($value[$key]);
                    if ($postalCode !== '') {
                        return $postalCode;
                    }
                }
            }

            foreach ($value as $item) {
                $postalCode = $this->extractRussianPostPostalCode($item);
                if ($postalCode !== '') {
                    return $postalCode;
                }
            }

            return '';
        }

        if (is_object($value)) {
            return $this->extractRussianPostPostalCode((array) $value);
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $text, $matches)) {
            return $matches[1];
        }

        return '';
    }

    public function getDeliveryPackages(int $id): JsonResponse
    {
        $order = ShopOrder::with('packages')->findOrFail($id);
        $service = app(DeliveryPackageService::class);
        $packages = $service->fromOrder($order);

        return response()->json([
            'success' => true,
            'data' => [
                'packages' => $packages,
                'summary' => $service->summary($packages),
                'is_confirmed' => $order->packages->isNotEmpty()
                    && $order->packages->every(fn ($package) => $package->confirmed_at !== null),
                'source' => $order->packages->first()?->source ?? 'estimated',
                'items' => collect($order->getItemsWithDetails())->values()->map(fn (array $item, int $index) => [
                    'item_index' => $index,
                'name' => $item['good_name'] ?? 'Товар',
                'variation_name' => $item['variation_name'] ?? null,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'weight' => $item['shipping_weight'] ?? $item['weight'] ?? null,
                'length' => $item['shipping_length'] ?? $item['length'] ?? $item['depth'] ?? null,
                'width' => $item['shipping_width'] ?? $item['width'] ?? null,
                'height' => $item['shipping_height'] ?? $item['height'] ?? null,
            ])->all(),
            ],
        ]);
    }

    public function calculateCdekTariffs(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city_code' => 'required|integer|min:1',
        ]);

        $order = ShopOrder::with('packages')->findOrFail($id);
        $settings = ShopCdekSettings::getActive();
        if (! $settings) {
            return response()->json(['success' => false, 'message' => 'Активные настройки СДЭК не найдены'], 404);
        }

        $packages = app(DeliveryPackageService::class)->fromOrder($order, $settings);
        $service = app(CdekService::class);
        $senderCityCode = (int) ($settings->sender_city_code ?: 0);
        if ($senderCityCode <= 0) {
            return response()->json(['success' => false, 'message' => 'В настройках СДЭК не указан код города отправителя'], 422);
        }

        $calculated = $service->calculateDeliveryForPackages(
            $senderCityCode,
            (int) $validated['city_code'],
            $packages
        );
        $configured = is_string($settings->tariffs) ? json_decode($settings->tariffs, true) : $settings->tariffs;
        $configured = collect($configured ?: [])->filter(fn ($tariff) => $tariff['enabled'] ?? true)->keyBy('tariff_code');

        $calculatedTariffs = collect($calculated ?: [])->filter(function ($tariff) use ($configured) {
            return $configured->isEmpty() || $configured->has($tariff['tariff_code'] ?? null);
        })->map(function ($tariff) use ($configured) {
            $custom = $configured->get($tariff['tariff_code'] ?? null, []);
            $cost = (float) ($tariff['delivery_sum'] ?? 0);

            return [
                'code' => $tariff['tariff_code'] ?? null,
                'name' => $custom['site_name'] ?? $tariff['tariff_name'] ?? 'Тариф СДЭК',
                'description' => $custom['tariff_description'] ?? $tariff['tariff_description'] ?? '',
                'cost' => $cost.' ₽',
                'cost_value' => $cost,
                'delivery_mode' => $tariff['delivery_mode'] ?? $custom['delivery_mode'] ?? null,
                'enabled' => true,
                'available' => true,
            ];
        })->keyBy(fn (array $tariff) => (string) $tariff['code']);

        // Не скрываем включенные в настройках тарифы, которые СДЭК не смог
        // рассчитать для текущего маршрута или упаковки. Их нельзя выбрать,
        // но администратор видит, почему настройка тарифа отсутствует в выдаче.
        $configured->each(function (array $tariff, $code) use ($calculatedTariffs) {
            $key = (string) $code;
            if ($calculatedTariffs->has($key)) {
                return;
            }

            $calculatedTariffs->put($key, [
                'code' => $tariff['tariff_code'] ?? $code,
                'name' => $tariff['site_name'] ?? $tariff['tariff_name'] ?? 'Тариф СДЭК',
                'description' => $tariff['tariff_description'] ?? '',
                'cost' => null,
                'cost_value' => null,
                'delivery_mode' => $tariff['delivery_mode'] ?? null,
                'enabled' => true,
                'available' => false,
                'unavailable_reason' => 'СДЭК не рассчитал этот тариф для текущего маршрута, веса или габаритов.',
            ]);
        });

        $tariffs = $calculatedTariffs->values()->all();

        return response()->json([
            'success' => true,
            'data' => $tariffs,
            'meta' => [
                'packages' => $packages,
                'summary' => app(DeliveryPackageService::class)->summary($packages),
                'is_confirmed' => $order->packages->isNotEmpty()
                    && $order->packages->every(fn ($package) => $package->confirmed_at !== null),
            ],
        ]);
    }

    public function updateDeliveryPackages(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'packages' => 'required|array|min:1|max:100',
            'packages.*.weight' => 'required|numeric|min:0.001|max:10000',
            'packages.*.length' => 'required|numeric|min:1|max:10000',
            'packages.*.width' => 'required|numeric|min:1|max:10000',
            'packages.*.height' => 'required|numeric|min:1|max:10000',
            'packages.*.items' => 'nullable|array',
            'confirmed' => 'nullable|boolean',
        ]);

        $order = ShopOrder::findOrFail($id);
        $confirmedAt = ($validated['confirmed'] ?? true) ? now() : null;

        DB::transaction(function () use ($order, $validated, $confirmedAt) {
            $order->packages()->delete();
            foreach (array_values($validated['packages']) as $index => $package) {
                $order->packages()->create([
                    'number' => $index + 1,
                    'weight' => $package['weight'],
                    'length' => $package['length'],
                    'width' => $package['width'],
                    'height' => $package['height'],
                    'source' => 'manual',
                    'confirmed_at' => $confirmedAt,
                    'items' => $package['items'] ?? null,
                ]);
            }
        });

        return $this->getDeliveryPackages($order->id);
    }

    public function resetDeliveryPackages(int $id): JsonResponse
    {
        $order = ShopOrder::findOrFail($id);
        $order->packages()->delete();

        return $this->getDeliveryPackages($order->id);
    }

    private function calculateOrderDeliveryCargo(ShopOrder $order, $settings): array
    {
        $packageService = app(DeliveryPackageService::class);
        $cargo = $packageService->dellinCargo($packageService->fromOrder($order, $settings));
        $cargo['insurance'] = [
            'statedValue' => max(1, (float) ($order->subtotal ?? $order->total_amount ?? 1)),
            'term' => true,
        ];

        return $cargo;
    }

    private function calculateRussianPostOrderCargo(ShopOrder $order, ShopRussianPostSettings $settings): array
    {
        $packageService = app(DeliveryPackageService::class);
        $packages = $packageService->fromOrder($order, $settings);
        $summary = $packageService->summary($packages);

        return [
            'weight' => $summary['total_weight'],
            'length' => $summary['max_length'],
            'width' => $summary['max_width'],
            'height' => $summary['max_height'],
            'packages' => $packages,
        ];
    }

    private function positiveDeliveryNumber($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function splitCustomerName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];

        return [
            $parts[0] ?? 'Получатель',
            $parts[1] ?? ($parts[0] ?? 'Получатель'),
            $parts[2] ?? null,
        ];
    }

    private function russianPostHeaders(ShopRussianPostSettings $settings): array
    {
        $password = (string) $settings->password;
        $userAuth = str_starts_with(mb_strtolower(trim($password)), 'basic ')
            ? trim(mb_substr(trim($password), 6))
            : base64_encode($settings->login.':'.$password);

        return [
            'Authorization' => 'AccessToken '.$settings->api_token,
            'X-User-Authorization' => 'Basic '.$userAuth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json;charset=UTF-8',
        ];
    }

    private function getDellinSessionId(ShopDellinSettings $settings): ?string
    {
        if ($settings->session_id && $settings->session_expires_at && $settings->session_expires_at->isFuture()) {
            return $settings->session_id;
        }

        if ($settings->auth_type === 'pat' && $settings->pat) {
            $response = Http::withOptions(['verify' => $this->getDellinVerifyOption(), 'timeout' => 30])
                ->post('https://api.dellin.ru/v4/auth/login.json', [
                    'appkey' => $settings->appkey,
                    'pat' => $settings->pat,
                ]);
        } elseif ($settings->auth_type === 'login_password' && $settings->login && $settings->password) {
            $response = Http::withOptions(['verify' => $this->getDellinVerifyOption(), 'timeout' => 30])
                ->post('https://api.dellin.ru/v3/auth/login.json', [
                    'appkey' => $settings->appkey,
                    'login' => $settings->login,
                    'password' => $settings->password,
                ]);
        } else {
            return null;
        }

        $data = $response->json() ?: [];
        $sessionId = $data['data']['sessionID'] ?? null;
        if ($response->successful() && $sessionId) {
            $settings->update([
                'session_id' => $sessionId,
                'session_expires_at' => now()->addDays(30),
            ]);
        }

        return $sessionId;
    }

    /**
     * Контрагент в ДЛ выбирается для сессии, а не передается в заявке.
     * Активируем сохраненный UID непосредственно перед запросом к API.
     */
    private function activateDellinCounteragent(ShopDellinSettings $settings, string $sessionId): void
    {
        $counteragentUid = trim((string) $settings->counteragent_uid);
        if ($counteragentUid === '') {
            return;
        }

        $response = Http::withOptions([
            'verify' => $this->getDellinVerifyOption(),
            'timeout' => 30,
        ])->post('https://api.dellin.ru/v2/counteragents.json', [
            'appkey' => $settings->appkey,
            'sessionID' => $sessionId,
            'cauid' => $counteragentUid,
            'fullInfo' => false,
        ]);
        $data = $response->json() ?: [];

        if (! $response->successful() || isset($data['errors'])) {
            throw new \RuntimeException($this->extractExternalDeliveryError(
                $data,
                'Не удалось активировать выбранного контрагента Деловых линий.'
            ));
        }
    }

    private function extractDellinLastStatusItem($history): array
    {
        if (! is_array($history) || empty($history)) {
            return [];
        }

        if (isset($history['state']) || isset($history['State']) || isset($history['stateName']) || isset($history['StateName'])) {
            return $history;
        }

        $items = array_values($history);
        $last = end($items);

        return is_array($last) ? $last : [];
    }

    private function fetchDellinOrderJournal(ShopDellinSettings $settings, ShopOrder $order): array
    {
        $sessionId = $this->getDellinSessionId($settings);
        if (! $sessionId) {
            return [];
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $payload = [
            'appkey' => $settings->appkey,
            'sessionID' => $sessionId,
        ];

        if ($order->dellin_order_id) {
            $payload['docIds'] = [(string) $order->dellin_order_id];
        } elseif (! empty($metadata['dellin_barcode'])) {
            $payload['barcode'] = (string) $metadata['dellin_barcode'];
        } else {
            $payload['orderNumber'] = (string) $order->order_number;
        }

        $response = Http::withOptions([
            'verify' => $this->getDellinVerifyOption(),
            'timeout' => 30,
        ])->post('https://api.dellin.ru/v3/orders.json', $payload);

        $data = $response->json() ?: [];
        if (! $response->successful() || isset($data['errors'])) {
            return [
                'payload' => $payload,
                'response' => $data,
            ];
        }

        $orders = $data['orders'] ?? $data['data']['orders'] ?? [];
        $orderData = is_array($orders) ? ($orders[0] ?? []) : [];

        return [
            'payload' => $payload,
            'response' => $data,
            'order' => is_array($orderData) ? $orderData : [],
        ];
    }

    private function getDellinVerifyOption()
    {
        $caBundle = config('services.dellin.ca_bundle_path');
        if ($caBundle && file_exists($caBundle)) {
            return $caBundle;
        }

        return filter_var(config('services.dellin.verify_ssl', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function extractExternalDeliveryError($data, string $fallback): string
    {
        if (! is_array($data)) {
            return $fallback;
        }

        if (! empty($data['errors']) && is_array($data['errors'])) {
            $messages = [];
            foreach (array_slice($data['errors'], 0, 5) as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $fields = isset($error['fields']) && is_array($error['fields'])
                    ? implode(', ', $error['fields'])
                    : '';
                $detail = $error['detail'] ?? $error['message'] ?? $error['title'] ?? '';
                $messages[] = trim(($fields ? $fields.': ' : '').$detail);
            }

            $messages = array_values(array_filter($messages));
            if ($messages) {
                return implode('; ', $messages);
            }
        }

        return $data['errors'][0]['detail']
            ?? $data['errors'][0]['message']
            ?? $data['error']['message']
            ?? $data['message']
            ?? $data['desc']
            ?? $fallback;
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

                $baseQuery = ShopOrder::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);

                $totalOrders = $baseQuery->count();
                $totalRevenue = $baseQuery->sum('total_amount');

                // Оплаченные заказы
                $paidOrders = ShopOrder::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                    ->where('payed', true)
                    ->count();
                $paidRevenue = ShopOrder::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                    ->where('payed', true)
                    ->sum('total_amount');

                // Неоплаченные заказы: payed = 0, false или NULL
                $unpaidOrders = ShopOrder::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                    ->where(function ($q) {
                        $q->where('payed', false)
                            ->orWhere('payed', 0)
                            ->orWhereNull('payed');
                    })
                    ->count();
                $unpaidRevenue = ShopOrder::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
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
            Log::error('Ошибка получения статистики заказов: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: ' . $e->getMessage(),
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
                        'is_taken_to_work' => (bool) $status->is_taken_to_work,
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
                'message' => 'Ошибка загрузки статусов: ' . $e->getMessage(),
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
                'is_taken_to_work' => 'sometimes|boolean',
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

            // Если is_taken_to_work=true, сбрасываем у других
            if ($request->get('is_taken_to_work', false)) {
                ShopOrderStatus::where('is_taken_to_work', true)->update(['is_taken_to_work' => false]);
            }

            $maxSortOrder = ShopOrderStatus::max('sort_order') ?? 0;

            $status = ShopOrderStatus::create([
                'name' => $request->get('name'),
                'display_name' => $request->get('display_name'),
                'color' => $request->get('color', '#6B7280'),
                'is_active' => $request->get('is_active', true),
                'is_finished' => $request->get('is_finished', false),
                'is_cancelled' => $request->get('is_cancelled', false),
                'is_taken_to_work' => $request->get('is_taken_to_work', false),
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
                    'is_taken_to_work' => (bool) $status->is_taken_to_work,
                    'sort_order' => $status->sort_order,
                    'description' => $status->description,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания статуса: ' . $e->getMessage(),
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
                'name' => 'sometimes|string|max:255|unique:shop_order_statuses,name,' . $id,
                'display_name' => 'sometimes|string|max:255',
                'color' => 'nullable|string|max:7',
                'is_active' => 'sometimes|boolean',
                'is_finished' => 'sometimes|boolean',
                'is_cancelled' => 'sometimes|boolean',
                'is_taken_to_work' => 'sometimes|boolean',
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

            // Если is_taken_to_work=true, сбрасываем у других
            if ($request->has('is_taken_to_work') && $request->get('is_taken_to_work')) {
                ShopOrderStatus::where('id', '!=', $id)->where('is_taken_to_work', true)->update(['is_taken_to_work' => false]);
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
            if ($request->has('is_taken_to_work')) {
                $updateData['is_taken_to_work'] = $request->get('is_taken_to_work');
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
                    'is_taken_to_work' => (bool) $status->is_taken_to_work,
                    'sort_order' => $status->sort_order,
                    'description' => $status->description,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса: ' . $e->getMessage(),
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

            if ($status->is_taken_to_work) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить статус "Взял в работу"',
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
                'message' => 'Ошибка удаления статуса: ' . $e->getMessage(),
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
                'message' => 'Ошибка изменения порядка: ' . $e->getMessage(),
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
                'message' => 'Ошибка получения логов: ' . $e->getMessage(),
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
                'message' => 'Ошибка добавления комментария: ' . $e->getMessage(),
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
                'message' => 'Ошибка получения статистики логов: ' . $e->getMessage(),
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
                'message' => 'Ошибка получения иконок: ' . $e->getMessage(),
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
                'message' => 'Ошибка создания иконки: ' . $e->getMessage(),
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
                'message' => 'Ошибка обновления иконки: ' . $e->getMessage(),
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
                'message' => 'Ошибка удаления иконки: ' . $e->getMessage(),
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

                    Log::info('Payment approved email sent to: ' . $order->customer_email);
                } catch (\Exception $e) {
                    Log::error('Ошибка отправки письма о разрешении оплаты: ' . $e->getMessage(), [
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
                'message' => 'Ошибка обновления: ' . $e->getMessage(),
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
                'message' => 'Ошибка массового удаления: ' . $e->getMessage(),
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
                if (!$order) {
                    continue;
                }

                // Проверка на завершенный статус для неоплаченного заказа
                if ($newStatus->is_finished && !$order->payed) {
                    $skippedCount++;

                    continue;
                }

                $oldStatus = $order->status;

                // Обработка отмены
                if ($newStatus->is_cancelled) {
                    $this->restoreOrderItemsToStock($order);
                    $this->restoreUserBonuses($order);
                }

                if ($newStatus->is_taken_to_work && !$order->manager_id) {
                    $order->manager_id = $user ? $user->id : null;
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
                'message' => 'Ошибка массового обновления статуса: ' . $e->getMessage(),
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
                if (!$order || $order->payed === $payed) {
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
                'message' => 'Ошибка массового обновления статуса оплаты: ' . $e->getMessage(),
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
                'message' => 'Комментарий добавлен к ' . count($orderIds) . ' заказам',
                'data' => [
                    'updated_count' => count($orderIds),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового добавления комментария: ' . $e->getMessage(),
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
            if (!$order->payment_method_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа не указан способ оплаты',
                ], 400);
            }

            $paymentMethod = $order->paymentMethod;
            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты не найден',
                ], 400);
            }

            $oldPaymentUrl = $order->payment_url;

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
                'return_url' => config('app.frontend_url', 'https://skateandsnow.ru') . '/order/' . $order->order_number,
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

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать новый платеж - пустой ответ',
                ], 500);
            }

            // Получаем данные из Response объекта
            $content = $result->getContent();
            $resultData = json_decode($content, true);

            if (!$resultData || !isset($resultData['success']) || !$resultData['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $resultData['message'] ?? 'Не удалось создать новый платеж',
                ], $result->getStatusCode() >= 400 ? $result->getStatusCode() : 500);
            }

            $newPaymentUrl = $resultData['payment_url'] ?? $resultData['data']['payment_url'] ?? null;
            if (!$this->isExternalPaymentUrl($newPaymentUrl)) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'Платежная система вернула некорректную ссылку на оплату',
                    'response_data' => $resultData,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Платежная система вернула некорректную ссылку на оплату',
                ], 500);
            }

            // Отправляем email с новой ссылкой клиенту
            $this->sendNewPaymentLinkEmail($order, $newPaymentUrl);

            // Добавляем запись в лог заказа
            \App\Models\ShopOrderLog::create([
                'entity_id' => $order->id,
                'section' => 'orders',
                'action' => 'regenerate_payment_link',
                'user_id' => $request->user()->id ?? null,
                'old_value' => $oldPaymentUrl,
                'new_value' => $newPaymentUrl,
                'comment' => 'Перегенерация платежной ссылки',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Платежная ссылка успешно перегенерирована',
                'data' => [
                    'payment_url' => $newPaymentUrl,
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новую оплату для заказа с возможностью сменить способ оплаты и сумму.
     */
    public function createPaymentLink(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|integer|exists:shop_payment_methods,id',
            'items' => 'sometimes|array',
            'items.*.quantity' => 'sometimes|numeric|min:0',
            'items.*.price' => 'sometimes|numeric|min:0',
            'items.*.total' => 'sometimes|numeric|min:0',
            'subtotal' => 'sometimes|numeric|min:0',
            'sale_discount_amount' => 'sometimes|numeric|min:0',
            'registered_user_discount_amount' => 'sometimes|numeric|min:0',
            'promo_code_discount_amount' => 'sometimes|numeric|min:0',
            'birthday_discount_amount' => 'sometimes|numeric|min:0',
            'bonus_points_to_use' => 'sometimes|integer|min:0',
            'order_bonus_points' => 'sometimes|integer|min:0',
            'delivery_cost' => 'sometimes|numeric|min:0',
            'overtax_amount' => 'sometimes|numeric|min:0',
            'overtax_text' => 'sometimes|nullable|string|max:255',
            'total_amount' => 'sometimes|numeric|min:0',
            'send_email' => 'sometimes|boolean',
            'comment' => 'sometimes|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order = ShopOrder::with(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])->findOrFail($orderId);

            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ уже оплачен',
                ], 400);
            }

            $paymentMethod = \App\Models\ShopPaymentMethod::findOrFail($request->integer('payment_method_id'));
            $oldPaymentMethod = $order->payment_method;
            $oldPaymentUrl = $order->payment_url;
            $oldTotalAmount = (float) $order->total_amount;

            $this->applyPaymentLinkOrderChanges($order, $request, $paymentMethod);

            $sendEmail = $request->boolean('send_email', true);
            $transaction = null;
            $newPaymentUrl = null;
            $resultData = null;
            $message = 'Способ оплаты заказа обновлен';

            if (in_array($paymentMethod->type, ['cash', 'card', 'transfer', 'test_bank'], true)) {
                $order->update([
                    'payment_url' => null,
                    'yandex_pay_order_id' => null,
                    'yookassa_payment_id' => null,
                ]);

                $message = $paymentMethod->type === 'transfer'
                    ? 'Для заказа выбран счет на оплату'
                    : 'Для заказа выбрана оплата при получении';

                if ($sendEmail) {
                    $emailResult = $this->sendPaymentMethodChangedEmail($order, $paymentMethod, null);
                    if (!$emailResult['success']) {
                        return response()->json([
                            'success' => false,
                            'message' => $emailResult['message'] ?? 'Способ оплаты обновлен, но письмо клиенту не отправлено',
                            'data' => [
                                'payment_url' => null,
                                'transaction_id' => null,
                                'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                            ],
                        ], 500);
                    }
                }
            } else {
                $transaction = ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'status' => 'pending',
                    'amount' => $order->total_amount,
                    'request_data' => [
                        'created_from_admin' => true,
                        'changed_payment_method' => true,
                        'original_order_id' => $order->id,
                        'old_payment_method' => $oldPaymentMethod,
                        'old_payment_url' => $oldPaymentUrl,
                    ],
                ]);

                $result = match ($paymentMethod->type) {
                    'yookassa' => $this->regenerateYooKassaPayment($paymentMethod, $transaction, $order),
                    'yandex_pay', 'yandex_split' => $this->regenerateYandexPayPayment($paymentMethod, $transaction, $order),
                    'tbank_eacq' => $this->regenerateTbankEacqPayment($paymentMethod, $transaction, $order),
                    'tbank_dolyame' => $this->regenerateTbankDolyamePayment($paymentMethod, $transaction, $order),
                    default => null,
                };

                if (!$result) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Создание ссылок для этого типа оплаты не поддерживается',
                    ], 400);
                }

                $resultData = json_decode($result->getContent(), true);
                if (!$resultData || empty($resultData['success'])) {
                    return response()->json([
                        'success' => false,
                        'message' => $resultData['message'] ?? 'Не удалось создать платежную ссылку',
                    ], $result->getStatusCode() >= 400 ? $result->getStatusCode() : 500);
                }

                $newPaymentUrl = $resultData['payment_url'] ?? $resultData['data']['payment_url'] ?? null;
                if (!$this->isExternalPaymentUrl($newPaymentUrl)) {
                    $transaction->update([
                        'status' => 'failed',
                        'error_message' => 'Платежная система вернула некорректную ссылку на оплату',
                        'response_data' => $resultData,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Платежная система вернула некорректную ссылку на оплату',
                    ], 500);
                }

                if ($sendEmail) {
                    $emailResult = $this->sendPaymentMethodChangedEmail($order, $paymentMethod, $newPaymentUrl);
                    if (!$emailResult['success']) {
                        return response()->json([
                            'success' => false,
                            'message' => $emailResult['message'] ?? 'Платеж создан, но письмо клиенту не отправлено',
                            'data' => [
                                'payment_url' => $newPaymentUrl,
                                'transaction_id' => $transaction->id,
                                'order' => $this->formatOrderForResponse($order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager'])),
                            ],
                        ], 500);
                    }
                }

                $message = 'Платежная ссылка успешно создана';
            }

            $comment = trim((string) $request->input('comment', ''));
            ShopOrderLog::create([
                'entity_id' => $order->id,
                'section' => ShopOrderLog::SECTION_ORDERS,
                'action' => 'create_payment_link',
                'user_id' => $request->user()->id ?? null,
                'old_value' => $oldPaymentUrl,
                'new_value' => $newPaymentUrl,
                'comment' => trim('Смена способа оплаты: '.($oldPaymentMethod ?: 'не указан').' -> '.$paymentMethod->name.'. Сумма: '.$oldTotalAmount.' -> '.$order->total_amount.'. '.$comment),
            ]);

            $order = $order->fresh(['status', 'user', 'paymentMethod', 'deliveryMethod', 'manager']);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'payment_url' => $newPaymentUrl,
                    'transaction_id' => $transaction?->id,
                    'order' => $this->formatOrderForResponse($order),
                    'provider_response' => $resultData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Create payment link failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания платежной ссылки: '.$e->getMessage(),
            ], 500);
        }
    }

    private function applyPaymentLinkOrderChanges(ShopOrder $order, Request $request, $paymentMethod): void
    {
        if ($request->has('items')) {
            $items = collect($request->input('items', []))
                ->map(function ($item, $index) {
                    $quantity = max(0, (float) ($item['quantity'] ?? 0));
                    $price = max(0, (float) ($item['price'] ?? $item['final_price'] ?? $item['unit_price'] ?? 0));
                    $total = array_key_exists('total', $item)
                        ? max(0, (float) $item['total'])
                        : $price * $quantity;

                    return array_merge($item, [
                        'id' => $item['id'] ?? ($item['good_id'] ?? $index),
                        'quantity' => $quantity,
                        'price' => $price,
                        'final_price' => $price,
                        'unit_price' => $price,
                        'total' => round($total, 2),
                    ]);
                })
                ->filter(fn ($item) => (float) ($item['quantity'] ?? 0) > 0)
                ->values()
                ->all();

            $order->items = $items;
            $order->total_quantity = collect($items)->sum(fn ($item) => (float) ($item['quantity'] ?? 0));
            $order->subtotal = $request->has('subtotal')
                ? (float) $request->input('subtotal')
                : collect($items)->sum(fn ($item) => (float) ($item['total'] ?? 0));
        } elseif ($request->has('subtotal')) {
            $order->subtotal = (float) $request->input('subtotal');
        }

        foreach ([
            'sale_discount_amount',
            'registered_user_discount_amount',
            'promo_code_discount_amount',
            'birthday_discount_amount',
            'delivery_cost',
            'overtax_amount',
        ] as $field) {
            if ($request->has($field)) {
                $order->{$field} = (float) $request->input($field);
            }
        }

        foreach (['bonus_points_to_use', 'order_bonus_points'] as $field) {
            if ($request->has($field)) {
                $order->{$field} = max(0, (int) $request->input($field));
            }
        }

        if ($request->has('overtax_text')) {
            $order->overtax_text = $request->input('overtax_text');
        }

        $order->use_bonus_points = (int) ($order->bonus_points_to_use ?? 0) > 0;
        $order->total_discount_amount =
            (float) ($order->sale_discount_amount ?? 0) +
            (float) ($order->registered_user_discount_amount ?? 0) +
            (float) ($order->promo_code_discount_amount ?? 0) +
            (float) ($order->birthday_discount_amount ?? 0) +
            (float) ($order->bonus_points_to_use ?? 0);
        $order->discount_amount = $order->total_discount_amount;

        $computedTotal = (float) ($order->subtotal ?? 0)
            - (float) ($order->registered_user_discount_amount ?? 0)
            - (float) ($order->promo_code_discount_amount ?? 0)
            - (float) ($order->birthday_discount_amount ?? 0)
            - (float) ($order->bonus_points_to_use ?? 0)
            + (float) ($order->overtax_amount ?? 0);

        $order->total_amount = $request->has('total_amount')
            ? (float) $request->input('total_amount')
            : max(0, $computedTotal);

        $order->payment_method_id = $paymentMethod->id;
        $order->payment_method = $paymentMethod->name;
        $order->payment_url = null;
        $order->yandex_pay_order_id = null;
        $order->yookassa_payment_id = null;
        $order->pay_agree = true;

        $pendingPaymentStatusId = \App\Models\ShopPaymentStatus::whereIn('name', ['pending', 'awaiting_payment', 'unpaid'])->value('id');
        if ($pendingPaymentStatusId) {
            $order->payment_status_id = $pendingPaymentStatusId;
        }

        $order->save();
    }

    private function sendPaymentMethodChangedEmail(ShopOrder $order, $paymentMethod, ?string $paymentUrl): array
    {
        if (!$order->customer_email) {
            return ['success' => false, 'message' => 'У клиента не указан email'];
        }

        $isOnlinePayment = in_array($paymentMethod->type ?? '', ['yookassa', 'yandex_pay', 'yandex_split', 'tbank_eacq', 'tbank_dolyame'], true);
        if ($isOnlinePayment && !$this->isExternalPaymentUrl($paymentUrl)) {
            return ['success' => false, 'message' => 'Некорректная платежная ссылка'];
        }

        try {
            $contacts = \App\Models\Contact::where('is_main', 1)->first();
            $siteInfo = \App\Models\Setting::where('key', 'site_info')->first();
            $siteInfo = $siteInfo ? json_decode($siteInfo->value, true) : [];

            \Illuminate\Support\Facades\Mail::to($order->customer_email)->send(
                new \App\Mail\PaymentMethodChangedMail(
                    $order->fresh(['paymentMethod']),
                    $paymentMethod,
                    $paymentUrl,
                    $contacts,
                    $siteInfo
                )
            );

            Log::info('Payment method changed email sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email' => $order->customer_email,
                'payment_method_id' => $paymentMethod->id ?? null,
            ]);

            return ['success' => true, 'message' => 'Письмо о смене способа оплаты отправлено'];
        } catch (\Exception $e) {
            Log::error('Payment method changed email failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email' => $order->customer_email,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Ошибка отправки письма о смене способа оплаты: '.$e->getMessage()];
        }
    }

    private function sendOrderInvoiceEmail(ShopOrder $order): void
    {
        if (!$order->customer_email) {
            throw new \Exception('У клиента не указан email');
        }

        $contacts = \App\Models\Contact::where('is_main', 1)->first();
        $siteInfo = \App\Models\Setting::where('key', 'site_info')->first();
        $siteInfo = $siteInfo ? json_decode($siteInfo->value, true) : [];

        \Illuminate\Support\Facades\Mail::to($order->customer_email)->send(
            new \App\Mail\OrderInvoiceMail($order->fresh(), $contacts, $siteInfo)
        );
    }

    /**
     * Отправка email с текущей платежной ссылкой клиенту
     */
    public function sendPaymentLinkEmail(Request $request, $orderId): JsonResponse
    {
        try {
            $order = ShopOrder::findOrFail($orderId);

            // Проверяем, что у заказа есть платежная ссылка
            if (!$order->payment_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'У заказа нет платежной ссылки',
                ], 400);
            }

            // Проверяем, что у клиента есть email
            if (!$order->customer_email) {
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
            $emailResult = $this->sendNewPaymentLinkEmail($order, $order->payment_url);
            if (! $emailResult['success']) {
                \App\Models\ShopOrderLog::create([
                    'entity_id' => $order->id,
                    'section' => 'orders',
                    'action' => 'send_payment_link_email_failed',
                    'user_id' => $request->user()->id ?? null,
                    'old_value' => null,
                    'new_value' => $order->payment_url,
                    'comment' => 'Ошибка отправки email с платежной ссылкой: '.($emailResult['message'] ?? 'Неизвестная ошибка'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $emailResult['message'] ?? 'Ошибка отправки email с платежной ссылкой',
                ], 500);
            }

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
                'message' => 'Ошибка отправки email: ' . $e->getMessage(),
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
                    'return_url' => config('app.frontend_url', 'https://skateandsnow.ru') . '/order/' . $order->order_number,
                ],
                'description' => 'Заказ №' . $order->order_number,
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'regenerated' => true,
                ],
            ];

            $paymentController = new \App\Http\Controllers\Api\Public\ShopPaymentController;
            $paymentData['receipt'] = $paymentController->buildYookassaReceiptFromOrder($order, $settings);

            try {
                $proxy = env('HTTP_CLIENT_PROXY');
                $verify = config('services.tbank.verify_ssl', true);
                $caBundle = config('services.tbank.ca_bundle_path');
                $disableVerify = app()->environment('production') ? false : (($settings['mode'] ?? 'test') !== 'live');
                $options = [
                    'connect_timeout' => 10,
                    'proxy' => $proxy ?: null,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    'verify' => $disableVerify ? false : ($caBundle ?: $verify),
                ];

                $response = \Illuminate\Support\Facades\Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Idempotence-Key' => uniqid('regenerate_', true),
                    ])
                    ->timeout(30)
                    ->retry(2, 1000, null, false)
                    ->withOptions($options)
                    ->post($apiUrl, $paymentData);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception('Ошибка подключения к YooKassa API: ' . $e->getMessage());
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
                if (!$paymentUrl) {
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
                $responseData = $response->json();
                $errorMessage = $responseData['description']
                    ?? $responseData['message']
                    ?? $response->body()
                    ?? 'Ошибка создания платежа в YooKassa';

                $transaction->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'response_data' => $responseData ?: ['raw_body' => $response->body()],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания платежа в YooKassa: ' . $errorMessage,
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа YooKassa: ' . $e->getMessage(),
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

            if (!$this->validateYandexPaySettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки Yandex Pay',
                ], 400);
            }

            $apiUrl = ($settings['mode'] === 'test' || $settings['mode'] === 'sandbox')
                ? 'https://sandbox.pay.yandex.ru/api/merchant/v1/orders'
                : 'https://pay.yandex.ru/api/merchant/v1/orders';

            $amountValue = number_format((float) $order->total_amount, 2, '.', '');
            $returnUrl = config('app.frontend_url', 'https://skateandsnow.ru') . '/checkout?payment=return&payment_type=yandex_pay';

            $orderData = [
                'orderId' => 'REGENERATE-' . $order->order_number . '-' . time(),
                'merchantId' => $settings['merchant_id'] ?? null,
                'currencyCode' => $settings['currency'] ?? 'RUB',
                'amount' => [
                    'value' => $amountValue,
                    'currency' => $settings['currency'] ?? 'RUB',
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'ORDER-' . $order->id,
                            'description' => 'Заказ №' . $order->order_number,
                            'quantity' => ['count' => '1.0', 'available' => '1.0'],
                            'amount' => [
                                'value' => $amountValue,
                                'currency' => $settings['currency'] ?? 'RUB',
                            ],
                            'total' => $amountValue,
                        ],
                    ],
                    'total' => ['amount' => $amountValue],
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $returnUrl,
                ],
                'redirectUrls' => [
                    'onSuccess' => $returnUrl,
                    'onError' => $returnUrl,
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
                    'Authorization' => 'Api-Key ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => uniqid('regenerate_', true),
                    'X-Request-Timeout' => '30000',
                    'X-Request-Attempt' => '0',
                ])
                    ->timeout(30)
                    ->withOptions(['verify' => false]) // Отключаем SSL верификацию для локальной разработки
                    ->post($apiUrl, $orderData);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception('Ошибка подключения к Yandex Pay API: ' . $e->getMessage());
            } catch (\Exception $e) {
                throw $e;
            }

            if ($response->successful()) {
                $responseData = $response->json();
                // Yandex Pay возвращает данные в структуре {code, status, data: {...}}
                $yandexOrderId = $responseData['data']['orderId'] ?? $responseData['orderId'] ?? null;
                $paymentUrl = $responseData['data']['paymentUrl'] ?? $responseData['paymentUrl'] ?? null;

                // Проверяем, что получили payment_url
                if (!$paymentUrl) {
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
                \Illuminate\Support\Facades\Log::error('Yandex Pay regenerate failed', [
                    'order_id' => $order->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания платежа в Yandex Pay: ' . $response->body(),
                ], 500);
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Yandex Pay regenerate exception', [
                'order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при перегенерации платежа Yandex Pay: ' . $e->getMessage(),
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
            $orderStub->gateway_order_id = $order->id . '-R' . $transaction->id;
            $orderStub->order_number = $order->order_number;
            $orderStub->total_amount = (float) $order->total_amount;
            $orderStub->customer_email = $order->customer_email;
            $orderStub->customer_phone = $order->customer_phone;
            $orderStub->user_id = $order->user_id;
            $orderStub->items = $order->items;
            $orderStub->delivery_cost = $order->delivery_cost ?? 0;
            Log::info('[FIX:tbank-regenerate-payment-link] Regenerating T-Bank payment link.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'gateway_order_id' => $orderStub->gateway_order_id,
                'payment_method_type' => $paymentMethod->type,
            ]);

            $result = $service->initiatePayment($orderStub);
            if (!empty($result['success']) && !empty($result['payment_url'])) {
                $transaction->update([
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'request_data' => $result['request_data'] ?? $transaction->request_data,
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

            $transaction->update([
                'status' => 'failed',
                'error_message' => $result['message'] ?? 'T‑Bank: не удалось создать платеж',
                'request_data' => $result['request_data'] ?? $transaction->request_data,
                'response_data' => $result['response_data'] ?? $result,
            ]);
            Log::warning('[FIX:tbank-regenerate-payment-link] T-Bank payment link regeneration failed.', [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id,
                'gateway_order_id' => $orderStub->gateway_order_id,
                'message' => $result['message'] ?? null,
                'response' => $result['response_data'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'T‑Bank: не удалось создать платеж',
            ], 502);
        } catch (\Exception $e) {
            Log::error('[FIX:tbank-regenerate-payment-link] T-Bank payment link regeneration exception.', [
                'order_id' => $order->id ?? null,
                'transaction_id' => $transaction->id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
            $orderStub->gateway_order_id = $order->id . '-R' . $transaction->id;
            $orderStub->order_number = $order->order_number;
            $orderStub->total_amount = (float) $order->total_amount;
            $orderStub->customer_email = $order->customer_email;
            $orderStub->customer_phone = $order->customer_phone;
            $orderStub->user_id = $order->user_id;
            $orderStub->items = $order->items;
            $orderStub->delivery_cost = $order->delivery_cost ?? 0;
            Log::info('[FIX:tbank-regenerate-payment-link] Regenerating T-Bank Dolyami payment link.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'gateway_order_id' => $orderStub->gateway_order_id,
                'payment_method_type' => $paymentMethod->type,
            ]);

            $result = $service->initiatePayment($orderStub);
            if (!empty($result['success']) && !empty($result['payment_url'])) {
                $transaction->update([
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'request_data' => $result['request_data'] ?? $transaction->request_data,
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

            $transaction->update([
                'status' => 'failed',
                'error_message' => $result['message'] ?? 'T‑Bank Долями: не удалось создать платеж',
                'request_data' => $result['request_data'] ?? $transaction->request_data,
                'response_data' => $result['response_data'] ?? $result,
            ]);
            Log::warning('[FIX:tbank-regenerate-payment-link] T-Bank Dolyami payment link regeneration failed.', [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id,
                'gateway_order_id' => $orderStub->gateway_order_id,
                'message' => $result['message'] ?? null,
                'response' => $result['response_data'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'T‑Bank Долями: не удалось создать платеж',
            ], 502);
        } catch (\Exception $e) {
            Log::error('[FIX:tbank-regenerate-payment-link] T-Bank Dolyami payment link regeneration exception.', [
                'order_id' => $order->id ?? null,
                'transaction_id' => $transaction->id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
            return !empty($settings['merchant_id']);
        } elseif ($settings['mode'] === 'live') {
            return !empty($settings['secret_key']);
        }

        return false;
    }

    /**
     * Отправка email с новой платежной ссылкой клиенту
     */
    private function sendNewPaymentLinkEmail($order, $newPaymentUrl): array
    {
        if (!$this->isExternalPaymentUrl($newPaymentUrl) || !$order->customer_email) {
            $message = !$order->customer_email
                ? 'У клиента не указан email'
                : 'Некорректная платежная ссылка';

            \Log::warning('Payment link email skipped', [
                'order_id' => $order->id ?? null,
                'order_number' => $order->order_number ?? null,
                'email' => $order->customer_email ?? null,
                'payment_url' => $newPaymentUrl,
                'message' => $message,
            ]);

            return ['success' => false, 'message' => $message];
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

            \Log::info('Payment link email sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email' => $order->customer_email,
                'payment_url' => $newPaymentUrl,
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            \Log::error('Payment link email send failed: '.$e->getMessage(), [
                'order_id' => $order->id ?? null,
                'order_number' => $order->order_number ?? null,
                'email' => $order->customer_email ?? null,
                'payment_url' => $newPaymentUrl,
            ]);

            return ['success' => false, 'message' => 'Ошибка отправки email: '.$e->getMessage()];
        }
    }

    private function isExternalPaymentUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (str_contains($path, '/admin/shop/orders/') || str_contains($path, '/regenerate-payment-link')) {
            return false;
        }

        return true;
    }

    /**
     * Форматировать параметры вариации
     */
    private function formatVariationProperties($variation): string
    {
        if (!$variation) {
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

                    return $propName . ': ' . $propValue;
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
