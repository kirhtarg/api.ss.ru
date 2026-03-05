<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopOrderStatus;
use App\Models\ShopPaymentMethod;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
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

                // Если payment_url отсутствует, но есть yookassa_payment_id, пытаемся получить его из API
                if (! $order->payment_url && $order->yookassa_payment_id) {
                    $paymentUrl = $this->getYooKassaPaymentUrl($order->yookassa_payment_id);

                    if ($paymentUrl) {
                        $order->payment_url = $paymentUrl;
                        // Сохраняем в БД для будущих запросов
                        $order->update(['payment_url' => $paymentUrl]);
                    }
                }

                return $order;
            });

            return response()->json([
                'success' => true,
                'data' => $orders,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки заказов пользователя', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказов: '.$e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status', 'deliveryMethod'])
                ->first();

            if (! $order) {
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
                        'phone' => $order->customer_phone ?? '',
                    ],
                    'items' => $items,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки заказа пользователя', [
                'order_id' => $id,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки заказа: '.$e->getMessage(),
            ], 500);
        }
    }

    public function cancel($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status'])
                ->first();

            if (! $order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Проверяем, что заказ не оплачен (для неоплаченных заказов можно отменять)
            if ($order->payed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя отменить оплаченный заказ',
                ], 400);
            }

            // Проверяем, что заказ не отменен и не завершен
            if ($order->status && ($order->status->is_cancelled || $order->status->is_finished)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ нельзя отменить в текущем статусе',
                ], 400);
            }

            // Находим статус отмены
            $cancelStatus = ShopOrderStatus::where('is_cancelled', true)->first();
            if (! $cancelStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'Статус отмены не найден в системе',
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

            // Логируем отмену заказа
            ShopOrderLog::createLog($order->id, 'Отмена заказа', [
                'action_color' => '#DC2626', // red-600
                'user_id' => $user->id,
                'user_name' => $user->name ?? 'Покупатель',
                'section' => ShopOrderLog::SECTION_USER,
                'info' => "Заказ № {$order->order_number}",
            ]);

            // Отправляем уведомления об отмене заказа
            try {
                $notificationService = app(\App\Services\NotificationService::class);
                $notificationService->notifyOrderCancelled($order);
            } catch (\Exception $e) {
                Log::error('Order cancellation notification error: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Заказ успешно отменен',
                'data' => [
                    'id' => $order->id,
                    'status_id' => $order->status_id,
                    'status' => $order->status->name,
                    'status_display' => $order->status->display_name,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка отмены заказа: '.$e->getMessage(), [
                'order_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка отмены заказа: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Возврат товаров заказа на склад при отмене
     */
    private function restoreOrderItemsToStock(ShopOrder $order): void
    {
        try {
            // Безопасная обработка items - учитываем, что в модели есть cast 'array'
            $items = $order->items;

            // Если items null или пусто, выходим
            if (empty($items)) {
                Log::info('Заказ не содержит товаров для возврата на склад', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            // Если items строка (на случай, если cast не сработал), декодируем
            if (is_string($items)) {
                $items = json_decode($items, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Ошибка декодирования JSON items при возврате товаров на склад', [
                        'order_id' => $order->id,
                        'json_error' => json_last_error_msg(),
                    ]);

                    return;
                }
            }

            // Проверяем, что items является массивом
            if (! is_array($items)) {
                Log::error('Items не является массивом при возврате товаров на склад', [
                    'order_id' => $order->id,
                    'items_type' => gettype($items),
                ]);

                return;
            }

            foreach ($items as $item) {
                // Проверяем, что item является массивом
                if (! is_array($item)) {
                    continue;
                }

                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (! $goodId || $quantity <= 0) {
                    continue;
                }

                // Если есть вариация, обновляем stock_quantity в shop_good_variations
                if ($variationId) {
                    try {
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
                    } catch (\Exception $e) {
                        Log::error('Ошибка при возврате вариации на склад: '.$e->getMessage(), [
                            'order_id' => $order->id,
                            'variation_id' => $variationId,
                            'good_id' => $goodId,
                        ]);
                    }
                } else {
                    try {
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
                    } catch (\Exception $e) {
                        Log::error('Ошибка при возврате товара на склад: '.$e->getMessage(), [
                            'order_id' => $order->id,
                            'good_id' => $goodId,
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате товаров на склад: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
            ]);
            // Не пробрасываем исключение, чтобы не прерывать процесс отмены заказа
        }
    }

    /**
     * Создать заявку на отмену заказа
     */
    public function requestCancellation($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Преобразуем id в int для запроса
            $orderId = (int) $id;

            $order = ShopOrder::where('id', $orderId)
                ->where('user_id', $user->id)
                ->with(['status', 'deliveryMethod'])
                ->first();

            if (! $order) {
                return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
            }

            // Проверяем, что заявка еще не создана
            if ($order->cancellation_request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заявка на отмену заказа уже создана',
                ], 400);
            }

            // Устанавливаем флаг заявки на отмену
            $order->cancellation_request = true;
            $order->save();

            // Логируем заявку на отмену заказа
            ShopOrderLog::createLog($order->id, 'Заявка на отмену заказа', [
                'action_color' => '#DC2626', // red-600
                'user_id' => $user->id,
                'user_name' => $user->name ?? 'Покупатель',
                'section' => ShopOrderLog::SECTION_USER,
                'info' => "Заказ № {$order->order_number}",
            ]);

            // Отправляем уведомления о заявке на отмену (только для оплаченных заказов)
            if ($order->payed) {
                try {
                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->notifyCancellationRequest($order);
                } catch (\Exception $e) {
                    Log::error('Cancellation request notification error: '.$e->getMessage());
                }
            }

            Log::info('Заявка на отмену заказа создана', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'order_number' => $order->order_number,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Заявка на отмену заказа успешно создана',
                'data' => [
                    'id' => $order->id,
                    'cancellation_request' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка создания заявки на отмену заказа: '.$e->getMessage(), [
                'order_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания заявки на отмену заказа: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Возврат использованных бонусов пользователю при отмене заказа
     */
    private function restoreUserBonuses(ShopOrder $order): void
    {
        try {
            // Безопасная проверка условий для возврата бонусов
            if (! $order->user_id) {
                Log::info('Нет user_id для возврата бонусов', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            // Проверяем, использовались ли бонусы в заказе
            $useBonusPoints = $order->use_bonus_points ?? false;
            $bonusPointsToUse = $order->bonus_points_to_use ?? 0;

            // Преобразуем в числовые значения для проверки
            if (is_bool($useBonusPoints)) {
                $useBonusPoints = $useBonusPoints ? 1 : 0;
            }
            $bonusPointsToUse = (int) $bonusPointsToUse;

            if (! $useBonusPoints || $bonusPointsToUse <= 0) {
                Log::info('Бонусы не использовались в заказе или количество равно 0', [
                    'order_id' => $order->id,
                    'use_bonus_points' => $useBonusPoints,
                    'bonus_points_to_use' => $bonusPointsToUse,
                ]);

                return;
            }

            $userBonus = UserBonus::getOrCreateForUser($order->user_id);
            $bonusPoints = $bonusPointsToUse;

            // Возвращаем бонусы пользователю
            $userBonus->points += $bonusPoints;
            $userBonus->save();

            // Создаем транзакцию о возврате
            try {
                UserBonusTransaction::create([
                    'user_id' => $order->user_id,
                    'type' => 'refund',
                    'points' => $bonusPoints,
                    'description' => "Возврат бонусов за отмененный заказ #{$order->order_number}",
                    'order_id' => $order->id,
                    'metadata' => [
                        'order_number' => $order->order_number ?? null,
                        'action' => 'cancel_order_refund',
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка создания транзакции возврата бонусов: '.$e->getMessage(), [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'bonus_points' => $bonusPoints,
                ]);
                // Продолжаем выполнение, так как бонусы уже возвращены
            }

            Log::info('Бонусы возвращены пользователю при отмене заказа', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'bonus_points_returned' => $bonusPoints,
                'new_balance' => $userBonus->points,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при возврате бонусов пользователю: '.$e->getMessage(), [
                'order_id' => $order->id,
                'user_id' => $order->user_id ?? null,
                'error' => $e->getTraceAsString(),
            ]);
            // Не пробрасываем исключение, чтобы не прерывать процесс отмены заказа
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
                'data' => $statuses,
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки статусов заказов: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статусов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить payment_url из API YooKassa по payment ID
     */
    private function getYooKassaPaymentUrl(string $paymentId): ?string
    {
        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yookassa')->first();
            if (! $paymentMethod) {
                Log::warning('Метод оплаты YooKassa не найден');

                return null;
            }

            $settings = $paymentMethod->getApiSettings();
            if (empty($settings['shop_id']) || empty($settings['secret_key'])) {
                Log::warning('Настройки YooKassa не заполнены', [
                    'has_shop_id' => ! empty($settings['shop_id']),
                    'has_secret_key' => ! empty($settings['secret_key']),
                ]);

                return null;
            }

            $apiUrl = 'https://api.yookassa.ru/v3/payments/'.$paymentId;

            $response = Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                ->withOptions(['verify' => false])
                ->get($apiUrl);

            if ($response->successful()) {
                $data = $response->json();

                // Проверяем разные возможные места, где может быть payment_url
                if (isset($data['confirmation']['confirmation_url'])) {
                    return $data['confirmation']['confirmation_url'];
                } elseif (isset($data['confirmation_url'])) {
                    return $data['confirmation_url'];
                } elseif (isset($data['payment_url'])) {
                    return $data['payment_url'];
                } elseif (isset($data['data']['confirmation_url'])) {
                    return $data['data']['confirmation_url'];
                }

                // Если есть confirmation_token, формируем URL для redirect
                if (isset($data['confirmation']['confirmation_token'])) {
                    $confirmationType = $data['confirmation']['type'] ?? 'redirect';

                    // Для всех типов формируем URL
                    if (in_array($confirmationType, ['redirect', 'embedded', 'qr'])) {
                        return 'https://yoomoney.ru/checkout/payments/v2/contract?orderId='.$paymentId;
                    }
                }
            } else {
                Log::warning('Ошибка запроса к YooKassa API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Ошибка получения payment_url из YooKassa: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
