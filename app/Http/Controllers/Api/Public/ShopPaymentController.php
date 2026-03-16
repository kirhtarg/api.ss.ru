<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ShopGood;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentStatus; // Добавляем использование нового сервиса
use App\Models\ShopPaymentTransaction;
use App\Services\TbankPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopPaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $paymentMethods = ShopPaymentMethod::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods,
        ]);
    }

    /**
     * Handle payment return from payment gateway.
     *
     * @return \Illuminate\Http\Response
     */
    public function handlePaymentReturn(Request $request)
    {
        // Логика обработки возврата от платежных систем

        // ЮKassa: старый сценарий с order_id в параметрах
        if ($request->has('yookassa_payment_id')) {
            return redirect(config('app.frontend_url').'/payment-status?id='.$request->get('order_id').'&status=yookassa_processing');
        }

        $paymentType = $request->get('payment_type');
        $rawStatus = $request->get('status');
        $status = $rawStatus ? strtolower($rawStatus) : null;
        if ($status === 'ok') {
            $status = 'success';
        } elseif (in_array($status, ['failed', 'fail', 'canceled', 'cancelled'], true)) {
            $status = 'fail';
        }
        $orderNumber = $request->get('order_number');

        // Возврат с Т‑Банка (eacq / Долями) по SuccessURL / FailURL
        if ($paymentType && str_starts_with($paymentType, 'tbank_')) {
            Log::info('T-Bank return callback received', [
                'payment_type' => $paymentType,
                'status' => $status,
                'raw_status' => $rawStatus,
                'order_number' => $orderNumber,
                'query' => $request->query(),
            ]);

            $order = null;
            if ($orderNumber) {
                $order = ShopOrder::where('order_number', $orderNumber)->first();
            }

            if ($order) {
                if ($status === 'success') {
                    $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                    if ($paidStatusId) {
                        $order->update([
                            'payment_status_id' => $paidStatusId,
                            'payed' => true,
                        ]);
                    }
                    try {
                        app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                    } catch (\Exception $e) {
                        \Log::error('T-Bank return: failed to send order_created notification', [
                            'order_id' => $order->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $q = 'id='.$order->id.'&status=tbank_success';
                    if (! empty($rawStatus)) {
                        $q .= '&raw_status='.urlencode((string) $rawStatus);
                    }
                    if (! empty($paymentType)) {
                        $q .= '&payment_type='.urlencode((string) $paymentType);
                    }

                    return redirect(config('app.frontend_url').'/payment-status?'.$q);
                }

                if ($status === 'fail') {
                    $failedStatusId = ShopPaymentStatus::where('name', 'failed')->value('id');
                    if ($failedStatusId) {
                        $order->update([
                            'payment_status_id' => $failedStatusId,
                            'payed' => false,
                        ]);
                    }
                    $q = 'id='.$order->id.'&status=tbank_failed';
                    if (! empty($rawStatus)) {
                        $q .= '&raw_status='.urlencode((string) $rawStatus);
                    }
                    if (! empty($paymentType)) {
                        $q .= '&payment_type='.urlencode((string) $paymentType);
                    }

                    return redirect(config('app.frontend_url').'/payment-status?'.$q);
                }

                $q = 'id='.$order->id.'&status=tbank_processing';
                if (! empty($rawStatus)) {
                    $q .= '&raw_status='.urlencode((string) $rawStatus);
                }
                if (! empty($paymentType)) {
                    $q .= '&payment_type='.urlencode((string) $paymentType);
                }

                return redirect(config('app.frontend_url').'/payment-status?'.$q);
            }

            // Если заказ не найден, пытаемся создать из черновика в кэше (eacq — одноэтапная оплата)
            if ($status === 'success' && $orderNumber) {
                $draft = Cache::get('payment:init:tbank_eacq:order:'.$orderNumber);
                if ($draft && is_array($draft)) {
                    $order = $this->createOrderFromPayload(
                        $draft['order_data'] ?? [],
                        $draft['payment_method_id'] ?? null,
                        $draft['order_number'] ?? $orderNumber,
                        $draft['ip'] ?? null,
                        $draft['user_agent'] ?? null
                    );
                    if ($order) {
                        $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                        if ($paidStatusId) {
                            $order->update([
                                'payment_status_id' => $paidStatusId,
                                'payed' => true,
                            ]);
                        }
                        $txId = $draft['gateway_response']['PaymentId'] ?? null;
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $draft['payment_method_id'] ?? null,
                            'amount' => $order->total_amount,
                            'transaction_id' => $txId,
                            'status' => 'paid',
                            'request_data' => $draft,
                            'response_data' => ['source' => 'return_success'],
                        ]);
                        try {
                            app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                        } catch (\Exception $e) {
                            \Log::error('T-Bank eacq return: failed to send order_created notification', [
                                'order_id' => $order->id ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }
                        Cache::forget('payment:init:tbank_eacq:order:'.$orderNumber);
                        if ($txId) {
                            Cache::forget('payment:init:tbank_eacq:'.$txId);
                        }
                        $q2 = 'id='.$order->id.'&status=tbank_success';
                        if (! empty($rawStatus)) {
                            $q2 .= '&raw_status='.urlencode((string) $rawStatus);
                        }
                        if (! empty($paymentType)) {
                            $q2 .= '&payment_type='.urlencode((string) $paymentType);
                        }

                        return redirect(config('app.frontend_url').'/payment-status?'.$q2);
                    }
                }
            }

            // Если до сюда дошли, попробуем создать заказ из черновика по альтернативным ключам
            if ($status === 'success') {
                $paymentIdParam = $request->get('PaymentId')
                    ?? $request->get('paymentId')
                    ?? $request->get('payment_id')
                    ?? $request->get('PaymentID');
                if (! empty($paymentIdParam)) {
                    $draft = Cache::get('payment:init:tbank_eacq:'.$paymentIdParam);
                    if ($draft && is_array($draft)) {
                        $order = $this->createOrderFromPayload(
                            $draft['order_data'] ?? [],
                            $draft['payment_method_id'] ?? null,
                            $draft['order_number'] ?? ($orderNumber ?: $this->generateOrderNumber()),
                            $draft['ip'] ?? null,
                            $draft['user_agent'] ?? null
                        );
                        if ($order) {
                            $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                            if ($paidStatusId) {
                                $order->update([
                                    'payment_status_id' => $paidStatusId,
                                    'payed' => true,
                                ]);
                            }
                            ShopPaymentTransaction::create([
                                'order_id' => $order->id,
                                'payment_method_id' => $draft['payment_method_id'] ?? null,
                                'amount' => $order->total_amount,
                                'transaction_id' => $paymentIdParam,
                                'status' => 'paid',
                                'request_data' => $draft,
                                'response_data' => ['source' => 'return_success_by_payment_id'],
                            ]);
                            try {
                                app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                            } catch (\Exception $e) {
                                \Log::error('T-Bank eacq return by PaymentId: failed to send order_created notification', [
                                    'order_id' => $order->id ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                            Cache::forget('payment:init:tbank_eacq:'.$paymentIdParam);
                            if (! empty($draft['order_number'])) {
                                Cache::forget('payment:init:tbank_eacq:order:'.$draft['order_number']);
                            }
                            $q3 = 'id='.$order->id.'&status=tbank_success';
                            if (! empty($rawStatus)) {
                                $q3 .= '&raw_status='.urlencode((string) $rawStatus);
                            }
                            if (! empty($paymentType)) {
                                $q3 .= '&payment_type='.urlencode((string) $paymentType);
                            }

                            return redirect(config('app.frontend_url').'/payment-status?'.$q3);
                        }
                    }
                }
            }

            // Если заказ создать не удалось — возвращаем статус, максимально близкий к ответу банка
            $statusParam = 'tbank_processing';
            if ($status === 'success') {
                $statusParam = 'tbank_success';
                Log::warning('T-Bank return: success status but order still not created after all draft attempts', [
                    'payment_type' => $paymentType,
                    'status' => $status,
                    'raw_status' => $rawStatus ?? null,
                    'order_number' => $orderNumber,
                ]);
            } elseif ($status === 'fail') {
                $statusParam = 'tbank_failed';
            }

            $query = 'status='.$statusParam;
            if ($orderNumber) {
                $query .= '&order_number='.urlencode((string) $orderNumber);
            }
            if (! empty($rawStatus)) {
                $query .= '&raw_status='.urlencode((string) $rawStatus);
            }
            if (! empty($paymentType)) {
                $query .= '&payment_type='.urlencode((string) $paymentType);
            }

            return redirect(config('app.frontend_url').'/payment-status?'.$query);
        }

        // Default redirect or error
        return redirect(config('app.frontend_url').'/payment-status?status=error');
    }

    /**
     * Check payment status.
     *
     * @return \Illuminate\Http\Response
     */
    public function checkPaymentStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:shop_orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid input', 'errors' => $validator->errors()], 422);
        }

        $order = ShopOrder::findOrFail($request->order_id);

        return response()->json([
            'success' => true,
            'status' => $order->paymentStatus->name,
            'display_status' => $order->paymentStatus->display_name,
            'is_paid' => $order->paymentStatus->name === 'paid',
            'payment_url' => $order->payment_url, // Возвращаем payment_url, если есть
        ]);
    }

    /**
     * Update order status based on payment.
     * (Deprecated: use webhooks for real-time updates)
     *
     * @return \Illuminate\Http\Response
     */
    public function updateOrderStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:shop_orders,id',
            'status' => 'required|string|in:pending,paid,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid input', 'errors' => $validator->errors()], 422);
        }

        $order = ShopOrder::findOrFail($request->order_id);
        $status = ShopPaymentStatus::where('name', $request->status)->first();

        if ($status) {
            $order->update(['payment_status_id' => $status->id]);

            return response()->json(['success' => true, 'message' => 'Order payment status updated.']);
        }

        return response()->json(['success' => false, 'message' => 'Payment status not found.'], 404);
    }

    /**
     * Handle webhook for Yookassa.
     *
     * @return \Illuminate\Http\Response
     */
    public function yookassaWebhook(Request $request)
    {
        // Логика обработки webhook от ЮKassa
        Log::info('Yookassa Webhook received', $request->all());

        // Проверяем, что запрос действительно от ЮKassa (например, по IP или секрета)
        // Если все ок, обрабатываем
        $event = $request->input('event');
        $object = $request->input('object');

        if ($event === 'payment.succeeded' && $object['status'] === 'succeeded') {
            $paymentId = $object['id'];
            $txIdFromMeta = $object['metadata']['transaction_id'] ?? null;
            $orderIdFromMeta = $object['metadata']['order_id'] ?? null;

            if ($txIdFromMeta) {
                $transaction = ShopPaymentTransaction::where('id', $txIdFromMeta)->first();
                if ($transaction) {
                    $order = null;
                    if ($transaction->order_id) {
                        $order = ShopOrder::find($transaction->order_id);
                    }
                    if (! $order) {
                        $order = $this->createOrderFromTransaction($transaction, 'yookassa');
                        if ($order) {
                            $transaction->update(['order_id' => $order->id]);
                        }
                    }
                    if ($order) {
                        $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                        if ($paidStatusId) {
                            $order->update([
                                'payment_status_id' => $paidStatusId,
                                'yookassa_payment_id' => $paymentId,
                                'payed' => true,
                            ]);
                            Log::info('Yookassa: Order '.$order->id.' created/updated as paid via tx '.$transaction->id);
                        }
                    }
                } else {
                    Log::warning('Yookassa Webhook: transaction not found for id '.$txIdFromMeta);
                }
            } elseif ($orderIdFromMeta) {
                $order = ShopOrder::find($orderIdFromMeta);
                if ($order) {
                    $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                    if ($paidStatusId) {
                        $order->update([
                            'payment_status_id' => $paidStatusId,
                            'yookassa_payment_id' => $paymentId,
                            'payed' => true,
                        ]);
                        Log::info('Yookassa: Order '.$orderIdFromMeta.' payment succeeded.');
                    }
                }
            } else {
                $cacheKey = 'payment:init:yookassa:'.$paymentId;
                $draft = Cache::get($cacheKey);
                if ($draft && is_array($draft)) {
                    $order = $this->createOrderFromPayload(
                        $draft['order_data'] ?? [],
                        $draft['payment_method_id'] ?? null,
                        $draft['order_number'] ?? $this->generateOrderNumber(),
                        $draft['ip'] ?? null,
                        $draft['user_agent'] ?? null
                    );
                    if ($order) {
                        $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                        if ($paidStatusId) {
                            $order->update([
                                'payment_status_id' => $paidStatusId,
                                'yookassa_payment_id' => $paymentId,
                                'payed' => true,
                            ]);
                        }
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $draft['payment_method_id'] ?? null,
                            'amount' => $draft['amount'] ?? 0,
                            'transaction_id' => $paymentId,
                            'status' => 'paid',
                            'request_data' => $draft,
                            'response_data' => $request->all(),
                        ]);
                        Cache::forget($cacheKey);
                        Log::info('Yookassa: Order created from cache and marked paid', ['order_id' => $order->id]);
                    } else {
                        Log::error('Yookassa Webhook: failed to create order from cache');
                    }
                } else {
                    Log::error('Yookassa Webhook: neither transaction nor cached draft found for payment '.$paymentId);
                }
            }
        } elseif ($event === 'payment.canceled' || ($event === 'payment.succeeded' && $object['status'] === 'canceled')) {
            $txIdFromMeta = $object['metadata']['transaction_id'] ?? null;
            $orderId = $object['metadata']['order_id'] ?? null;
            if ($txIdFromMeta) {
                $transaction = ShopPaymentTransaction::where('id', $txIdFromMeta)->first();
                if ($transaction) {
                    $transaction->update(['status' => 'cancelled', 'response_data' => $request->all()]);
                }
            } elseif ($orderId) {
                $order = ShopOrder::find($orderId);
                if ($order) {
                    $cancelledStatusId = ShopPaymentStatus::where('name', 'cancelled')->value('id');
                    if ($cancelledStatusId) {
                        $order->update([
                            'payment_status_id' => $cancelledStatusId,
                            'payed' => false,
                        ]);
                        Log::info('Yookassa: Order '.$orderId.' payment cancelled.');
                    }
                }
            }
        }

        return response()->json([], 200);
    }

    protected function createOrderFromTransaction(ShopPaymentTransaction $transaction, string $gateway = ''): ?ShopOrder
    {
        try {
            $orderData = $transaction->request_data['order_data'] ?? null;
            if (! $orderData || ! is_array($orderData)) {
                Log::warning('createOrderFromTransaction: missing order_data in transaction '.$transaction->id);

                return null;
            }
            $order = ShopOrder::create([
                'order_number' => $transaction->request_data['order_number'] ?? $this->generateOrderNumber(),
                'user_id' => $orderData['customer_id'] ?? null,
                'customer_name' => $orderData['customer_name'] ?? '',
                'customer_email' => $orderData['customer_email'] ?? '',
                'customer_phone' => $orderData['customer_phone'] ?? null,
                'items' => $orderData['items'] ?? [],
                'subtotal' => $orderData['subtotal'] ?? 0,
                'discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'total_amount' => $orderData['total_amount'] ?? 0,
                'total_quantity' => isset($orderData['items']) ? array_sum(array_column($orderData['items'], 'quantity')) : 0,
                'payment_method' => optional($transaction->paymentMethod)->name,
                'payment_method_id' => $transaction->payment_method_id,
                'shipping_method' => $orderData['shipping_method'] ?? null,
                'shipping_method_id' => $orderData['shipping_method_id'] ?? null,
                'shipping_address' => $orderData['shipping_address'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'delivery_cost' => $orderData['delivery_cost'] ?? 0,
                'sale_discount_amount' => $orderData['sale_discount_amount'] ?? 0,
                'registered_user_discount_amount' => $orderData['registered_user_discount_amount'] ?? 0,
                'promo_code_discount_amount' => $orderData['promo_code_discount_amount'] ?? 0,
                'birthday_discount_amount' => $orderData['birthday_discount_amount'] ?? 0,
                'total_discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'promo_code' => $orderData['promo_code'] ?? null,
                'promo_code_id' => $orderData['promo_code_id'] ?? null,
                'use_bonus_points' => $orderData['use_bonus_points'] ?? false,
                'bonus_points_to_use' => $orderData['bonus_points_to_use'] ?? 0,
                'order_bonus_points' => $orderData['order_bonus_points'] ?? 0,
                'ip_address' => $transaction->request_data['ip'] ?? null,
                'user_agent' => $transaction->request_data['user_agent'] ?? null,
                'payment_status_id' => ShopPaymentStatus::where('name', 'paid')->value('id'),
                'payed' => true,
                'status_id' => ShopOrderStatus::where('name', 'confirmed')->value('id') ?? ShopOrderStatus::where('name', 'pending')->value('id'),
            ]);

            return $order;
        } catch (\Exception $e) {
            Log::error('Failed to create order from transaction '.$transaction->id.': '.$e->getMessage());

            return null;
        }
    }

    protected function createOrderFromPayload(array $orderData, ?int $paymentMethodId, string $orderNumber, ?string $ip, ?string $userAgent): ?ShopOrder
    {
        try {
            $order = ShopOrder::create([
                'order_number' => $orderNumber,
                'user_id' => $orderData['customer_id'] ?? null,
                'customer_name' => $orderData['customer_name'] ?? '',
                'customer_email' => $orderData['customer_email'] ?? '',
                'customer_phone' => $orderData['customer_phone'] ?? null,
                'items' => $orderData['items'] ?? [],
                'subtotal' => $orderData['subtotal'] ?? 0,
                'discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'total_amount' => $orderData['total_amount'] ?? 0,
                'total_quantity' => isset($orderData['items']) ? array_sum(array_column($orderData['items'], 'quantity')) : 0,
                'payment_method' => optional(ShopPaymentMethod::find($paymentMethodId))->name,
                'payment_method_id' => $paymentMethodId,
                'shipping_method' => $orderData['shipping_method'] ?? null,
                'shipping_method_id' => $orderData['shipping_method_id'] ?? null,
                'shipping_address' => $orderData['shipping_address'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'delivery_cost' => $orderData['delivery_cost'] ?? 0,
                'sale_discount_amount' => $orderData['sale_discount_amount'] ?? 0,
                'registered_user_discount_amount' => $orderData['registered_user_discount_amount'] ?? 0,
                'promo_code_discount_amount' => $orderData['promo_code_discount_amount'] ?? 0,
                'birthday_discount_amount' => $orderData['birthday_discount_amount'] ?? 0,
                'total_discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'promo_code' => $orderData['promo_code'] ?? null,
                'promo_code_id' => $orderData['promo_code_id'] ?? null,
                'use_bonus_points' => $orderData['use_bonus_points'] ?? false,
                'bonus_points_to_use' => $orderData['bonus_points_to_use'] ?? 0,
                'order_bonus_points' => $orderData['order_bonus_points'] ?? 0,
                'overtax_amount' => $orderData['overtax_amount'] ?? 0,
                'overtax_text' => $orderData['overtax_text'] ?? null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                'payed' => false,
                'status_id' => ShopOrderStatus::where('name', 'pending')->value('id') ?? ShopOrderStatus::where('name', 'confirmed')->value('id'),
            ]);

            // Списываем бонусы через UserBonus (единое хранилище) при использовании бонусов
            if ($order && ($orderData['use_bonus_points'] ?? false) && ($orderData['bonus_points_to_use'] ?? 0) > 0) {
                $customerId = $orderData['customer_id'] ?? null;
                if ($customerId) {
                    try {
                        $bonusPointsToUse = (int) $orderData['bonus_points_to_use'];
                        $userBonus = \App\Models\UserBonus::getOrCreateForUser($customerId);
                        if ($userBonus->points >= $bonusPointsToUse) {
                            $userBonus->spendPoints(
                                $bonusPointsToUse,
                                "Списание бонусов за заказ #{$order->order_number}",
                                $order->id,
                                ['source' => 'payment_flow']
                            );
                        } else {
                            Log::warning('Недостаточно бонусов (payment flow)', [
                                'user_id' => $customerId,
                                'requested' => $bonusPointsToUse,
                                'available' => $userBonus->points,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Ошибка списания бонусов (payment flow): '.$e->getMessage());
                    }
                }
            }

            return $order;
        } catch (\Exception $e) {
            Log::error('Failed to create order from payload: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Handle payment creation for various methods.
     *
     * @return \Illuminate\Http\Response
     */
    public function createPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:shop_payment_methods,id',
            'order_data' => 'required|array', // Validate that order_data is present and is an array
            'order_data.customer_name' => 'required|string',
            'order_data.customer_email' => 'required|email',
            'order_data.customer_phone' => 'nullable|string',
            'order_data.total_amount' => 'required|numeric|min:0',
            'order_data.items' => 'required|array|min:1',
            'order_data.items.*.good_id' => 'required|exists:shop_goods,id',
            'order_data.items.*.quantity' => 'required|integer|min:1',
            // Добавьте больше специфических валидаций для полей order_data при необходимости
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid input', 'errors' => $validator->errors()], 422);
        }

        // Извлекаем order_data из запроса
        $orderData = $request->input('order_data');
        $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);

        // SERVER-SIDE RECALCULATION (AUTHORITATIVE)
        if (isset($orderData['items']) && is_array($orderData['items'])) {
            $recalculatedSubtotal = 0;
            $recalculatedBaseSubtotal = 0;

            foreach ($orderData['items'] as &$item) {
                $sellingPrice = 0;
                $basePrice = 0;
                $good = ShopGood::find($item['good_id'] ?? null);
                if (! $good) {
                    continue;
                }

                if (isset($item['variation_id']) && $item['variation_id']) {
                    $variation = \App\Models\ShopGoodVariation::find($item['variation_id']);
                    if ($variation) {
                        $basePrice = $variation->price > 0 ? $variation->price : $good->price;
                        // Price priority: demping > sale > base
                        if ($variation->show_demping && $variation->demping_price > 0) {
                            $sellingPrice = $variation->demping_price;
                        } elseif ($variation->sale_price > 0) {
                            $sellingPrice = $variation->sale_price;
                        } else {
                            $sellingPrice = $basePrice;
                        }
                    }
                } else {
                    $basePrice = $good->price;
                    // Price priority for base good: demping > sale > base
                    if ($good->show_demping && $good->demping_price > 0) {
                        $sellingPrice = $good->demping_price;
                    } elseif ($good->sale_price > 0) {
                        $sellingPrice = $good->sale_price;
                    } else {
                        $sellingPrice = $basePrice;
                    }
                }

                $item['price'] = $basePrice;
                $item['sale_price'] = $sellingPrice;
                $item['total'] = $sellingPrice * ($item['quantity'] ?? 1);
                $recalculatedSubtotal += $item['total'];
                $recalculatedBaseSubtotal += $basePrice * ($item['quantity'] ?? 1);
            }
            unset($item);

            // Overwrite subtotal with server-calculated value (selling price subtotal)
            $orderData['subtotal'] = $recalculatedSubtotal;
            $orderData['sale_discount_amount'] = max(0, $recalculatedBaseSubtotal - $recalculatedSubtotal);

            // --- Full discount and total recalculation ---
            $user = isset($orderData['customer_id']) ? \App\Models\User::find($orderData['customer_id']) : null;

            // Initialize discount components
            $registeredUserDiscountAmount = 0;
            $promoCodeDiscountAmount = 0;
            $bonusPointsDiscountAmount = 0;
            $isFreeDelivery = false;

            // 1. Registered User Discount
            // Use client-sent value — User model has no `discount` field, client computes it correctly from site settings
            $registeredUserDiscountAmount = (float) ($orderData['registered_user_discount_amount'] ?? 0);

            $subtotalAfterUserDiscount = $recalculatedSubtotal - $registeredUserDiscountAmount;

            // 2. Promo Code Discount
            $promoCode = null;
            $promoCodeString = $orderData['promo_code'] ?? null;
            if ($promoCodeString) {
                $promoCode = \App\Models\Promocode::where('code', $promoCodeString)->first();
            }

            if ($promoCode) {
                $cartItemsForPromo = $orderData['items'];
                $userId = $user ? $user->id : null;
                $discountResult = $promoCode->calculateDiscount($subtotalAfterUserDiscount, $cartItemsForPromo, $userId);

                if ($promoCode->type === 'free_delivery') {
                    $applicability = $promoCode->isApplicableToOrder($cartItemsForPromo, $subtotalAfterUserDiscount, $userId);
                    if ($applicability['is_applicable']) {
                        $isFreeDelivery = true;
                    }
                } elseif (isset($discountResult['discount']) && $discountResult['discount'] > 0) {
                    $promoCodeDiscountAmount = $discountResult['discount'];
                }

                $orderData['promo_code_id'] = $promoCode->id;
            }

            // 3. Bonus Points Discount
            if (($orderData['use_bonus_points'] ?? false) && ($orderData['bonus_points_to_use'] ?? 0) > 0 && $user) {
                $pointsToUse = (int) $orderData['bonus_points_to_use'];
                $baseAmountForBonuses = $subtotalAfterUserDiscount - $promoCodeDiscountAmount;
                $userBonus = \App\Models\UserBonus::getOrCreateForUser($user->id);

                if ($userBonus->points >= $pointsToUse) {
                    // This rule should be in a setting. For now, assume bonuses can cover up to 50% of the amount.
                    $maxBonusUsage = $baseAmountForBonuses * 0.5;
                    $bonusPointsDiscountAmount = min($pointsToUse, $baseAmountForBonuses, $maxBonusUsage);
                }
            }

            // 4. Final Calculation
            $birthdayDiscountAmount = (float) ($orderData['birthday_discount_amount'] ?? 0);
            $saleDiscountAmount = (float) ($orderData['sale_discount_amount'] ?? 0);
            $deliveryCost = $isFreeDelivery ? 0 : ($orderData['delivery_cost'] ?? 0);
            
            // Total discount for database (sum of all extra discounts on top of sale prices)
            $totalDiscount = $registeredUserDiscountAmount + $promoCodeDiscountAmount + $bonusPointsDiscountAmount + $birthdayDiscountAmount;
            
            // finalTotalAmount = subtotal (already includes sale discounts) - extra discounts (no delivery — stored separately in delivery_cost)
            // overtax_amount combines site overtax + payment method surcharge (same as CartController)
            $overtaxAmount = (float) ($orderData['overtax_amount'] ?? 0) + (float) ($orderData['payment_surcharge_amount'] ?? 0);
            $finalTotalAmount = $recalculatedSubtotal - ($registeredUserDiscountAmount + $promoCodeDiscountAmount + $bonusPointsDiscountAmount + $birthdayDiscountAmount) + $overtaxAmount;

            // Overwrite client-sent amounts with authoritative server calculation
            $orderData['total_amount'] = round($finalTotalAmount, 2);
            $orderData['total_discount_amount'] = round($totalDiscount + $saleDiscountAmount, 2); // Including sale for admin breakdown
            $orderData['registered_user_discount_amount'] = round($registeredUserDiscountAmount, 2);
            $orderData['promo_code_discount_amount'] = round($promoCodeDiscountAmount, 2);
            $orderData['birthday_discount_amount'] = round($birthdayDiscountAmount, 2);
            $orderData['sale_discount_amount'] = round($saleDiscountAmount, 2);
            // In the database, bonus_points_to_use is an integer representing the number of points.
            // The monetary value is what we calculated as bonusPointsDiscountAmount.
            // Let's assume 1 point = 1 RUB for simplicity.
            $orderData['bonus_points_to_use'] = (int) round($bonusPointsDiscountAmount);
            $orderData['delivery_cost'] = round($deliveryCost, 2);
            $orderData['overtax_amount'] = round($overtaxAmount, 2);
            // overtax_text: use client value; if absent but payment surcharge present, use default
            if (empty($orderData['overtax_text']) && (float) ($orderData['payment_surcharge_amount'] ?? 0) > 0) {
                $orderData['overtax_text'] = 'Наценка за способ оплаты';
            }
        }

        if ($paymentMethod->type === 'transfer') {
            $order = ShopOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $orderData['customer_id'] ?? null,
                'customer_name' => $orderData['customer_name'],
                'customer_email' => $orderData['customer_email'],
                'customer_phone' => $orderData['customer_phone'] ?? null,
                'items' => $orderData['items'],
                'subtotal' => $orderData['subtotal'],
                'discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'total_amount' => $orderData['total_amount'],
                'total_quantity' => array_sum(array_column($orderData['items'], 'quantity')),
                'payment_method' => $paymentMethod->name,
                'payment_method_id' => $paymentMethod->id,
                'shipping_method' => $orderData['shipping_method'] ?? null,
                'shipping_method_id' => $orderData['shipping_method_id'] ?? null,
                'shipping_address' => $orderData['shipping_address'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'delivery_cost' => $orderData['delivery_cost'] ?? 0,
                'sale_discount_amount' => $orderData['sale_discount_amount'] ?? 0,
                'registered_user_discount_amount' => $orderData['registered_user_discount_amount'] ?? 0,
                'promo_code_discount_amount' => $orderData['promo_code_discount_amount'] ?? 0,
                'birthday_discount_amount' => $orderData['birthday_discount_amount'] ?? 0,
                'total_discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'promo_code' => $orderData['promo_code'] ?? null,
                'promo_code_id' => $orderData['promo_code_id'] ?? null,
                'use_bonus_points' => $orderData['use_bonus_points'] ?? false,
                'bonus_points_to_use' => $orderData['bonus_points_to_use'] ?? 0,
                'order_bonus_points' => $orderData['order_bonus_points'] ?? 0,
                'overtax_amount' => $orderData['overtax_amount'] ?? 0,
                'overtax_text' => $orderData['overtax_text'] ?? null,
                'cancellation_request' => $orderData['cancellation_request'] ?? false,
                'comment' => $orderData['comment'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                'status_id' => ShopOrderStatus::where('name', 'pending')->value('id'),
            ]);

            if (! $order || ! $order->id) {
                return response()->json(['success' => false, 'message' => 'Failed to create order'], 500);
            }

            return $this->handleBankTransfer($order, $paymentMethod);
        }

        if ($paymentMethod->type === 'cash') {
            return response()->json(['success' => true, 'message' => 'Оплата наличными не требует внешнего платежа.'], 200);
        }

        $orderNumber = $this->generateOrderNumber();
        $order = $this->createOrderFromPayload(
            $orderData,
            $paymentMethod->id,
            $orderNumber,
            $request->ip(),
            $request->userAgent()
        );
        if (! $order || ! $order->id) {
            return response()->json(['success' => false, 'message' => 'Failed to create order'], 500);
        }

        switch ($paymentMethod->type) {
            case 'tbank_dolyame':
                return $this->handleTbankDolyamePayment($order, $paymentMethod);
            case 'tbank_eacq':
                return $this->handleTbankEacqPayment($order, $paymentMethod);
            case 'yookassa':
                return $this->handleYookassaPayment($order, $paymentMethod);
            case 'yandex_pay':
            case 'yandex_split':
                return $this->handleYandexPayPayment($order, $paymentMethod);
            default:
                return response()->json(['success' => false, 'message' => 'Unsupported payment method'], 400);
        }
    }

    protected function handleYookassaPayment(ShopOrder $order, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings ?? []);
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame'];
        $shopId = $settings['shop_id'] ?? null;
        $secretKey = $settings['secret_key'] ?? null;
        if (empty($shopId) || empty($secretKey)) {
            return response()->json(['success' => false, 'message' => 'ЮКасса: отсутствует shop_id/secret_key'], 400);
        }
        $returnUrl = request()->input('return_url') ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=yookassa');
        $amountValue = number_format((float) $order->total_amount + (float) ($order->delivery_cost ?? 0), 2, '.', '');
        $payload = [
            'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
            'capture' => true,
            'description' => 'Оплата заказа №'.$order->order_number,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'metadata' => ['order_id' => $order->id],
        ];
        try {
            $proxy = env('HTTP_CLIENT_PROXY');
            $verify = config('services.tbank.verify_ssl', true);
            $caBundle = config('services.tbank.ca_bundle_path');
            $options = [
                'connect_timeout' => 10,
                'proxy' => $proxy ?: null,
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ];
            $options['verify'] = $caBundle ?: $verify;
            $http = \Illuminate\Support\Facades\Http::timeout(30)->retry(2, 1000)->withOptions($options)->withHeaders([
                'Idempotence-Key' => uniqid('yk_', true),
                'Content-Type' => 'application/json',
            ])->withBasicAuth($shopId, $secretKey);
            $response = $http->post('https://api.yookassa.ru/v3/payments', $payload);
            $data = $response->json();
            if ($response->successful() && isset($data['confirmation']['confirmation_url'])) {
                $paymentUrl = $data['confirmation']['confirmation_url'];
                $order->update([
                    'payment_method_id' => $paymentMethod->id,
                    'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                    'payment_url' => $paymentUrl,
                    'yookassa_payment_id' => $data['id'] ?? null,
                ]);
                ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $order->total_amount,
                    'status' => 'pending',
                    'transaction_id' => $data['id'] ?? null,
                    'response_data' => $data,
                ]);
                try {
                    app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                } catch (\Exception $e) {
                    \Log::error('YooKassa: failed to send order_created notification', [
                        'order_id' => $order->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
                if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                    return response()->json([
                        'success' => true,
                        'two_stage_pay' => true,
                        'payment_url' => $paymentUrl,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentUrl,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $data['description'] ?? 'ЮКасса: не удалось создать платеж',
                'details' => $data,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('YooKassa connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'ЮКасса: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('YooKassa create payment failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'ЮКасса: ошибка создания платежа'], 500);
        }
    }

    protected function handleTbankEacqPayment(ShopOrder $order, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings);

        // Debug: Log the actual settings from database
        Log::debug('ShopPaymentController: handleTbankEacqPayment settings', [
            'payment_method_id' => $paymentMethod->id,
            'raw_settings' => $paymentMethod->settings,
            'normalized_settings' => $settings,
            'dolyame_keys' => array_filter(array_keys($settings), function ($key) {
                return strpos($key, 'dolyame') !== false;
            }),
        ]);

        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay && class_exists(\App\Models\Setting::class)) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame', 'tbank_eacq'];
        if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
            Log::error('handleTbankEacqPayment: missing terminal_key or terminal_password', ['method_id' => $paymentMethod->id]);

            return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured'], 500);
        }
        $tbankService = new TbankPaymentService($settings);
        try {
            $paymentResult = $tbankService->initiatePayment($order);
            if (isset($paymentResult['success']) && $paymentResult['success']) {
                $transaction = ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $order->total_amount,
                    'transaction_id' => $paymentResult['transaction_id'] ?? null,
                    'request_data' => $paymentResult['request_data'] ?? null,
                    'response_data' => $paymentResult['response_data'] ?? null,
                    'status' => 'pending',
                ]);
                $order->update([
                    'payment_url' => $paymentResult['payment_url'] ?? null,
                    'payment_method_id' => $paymentMethod->id,
                    'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                ]);
                if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                    return response()->json([
                        'success' => true,
                        'two_stage_pay' => true,
                        'payment_url' => $paymentResult['payment_url'] ?? null,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentResult['payment_url'] ?? null,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'transaction_id' => $transaction->id,
                ]);
            }
            if (isset($paymentResult['error']) && $paymentResult['error'] === 'connection_timeout') {
                ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $order->total_amount,
                    'status' => 'pending',
                    'response_data' => ['message' => 'Gateway unreachable, awaiting manager approval'],
                ]);
                $order->update([
                    'payment_method_id' => $paymentMethod->id,
                    'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                    'payment_url' => null,
                ]);

                return response()->json([
                    'success' => true,
                    'two_stage_pay' => true,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Gateway unreachable',
                ]);
            }
            Log::error('handleTbankEacqPayment: T-Bank eacq payment initiation failed for order '.$order->id.': '.($paymentResult['message'] ?? 'Unknown error'), ['payment_result' => $paymentResult]);

            return response()->json(['success' => false, 'message' => $paymentResult['message'] ?? 'Failed to initiate payment'], 500);
        } catch (\Exception $e) {
            Log::error('handleTbankEacqPayment: Exception during T-Bank eacq payment initiation for order '.$order->id.': '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => 'An error occurred during payment processing'], 500);
        }
    }

    protected function initYookassaPayment(string $orderNumber, array $orderData, ShopPaymentMethod $paymentMethod, ?string $returnUrl, Request $request): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings ?? []);
        $shopId = $settings['shop_id'] ?? null;
        $secretKey = $settings['secret_key'] ?? null;
        if (empty($shopId) || empty($secretKey)) {
            return response()->json(['success' => false, 'message' => 'ЮКасса: отсутствует shop_id/secret_key'], 400);
        }
        $returnUrl = $returnUrl ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=yookassa');
        $amountValue = number_format($orderData['total_amount'] ?? 0, 2, '.', '');
        // Определяем двухэтапность
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay && class_exists(\App\Models\Setting::class)) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame', 'tbank_eacq'];
        $payload = [
            'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
            'capture' => true,
            'description' => 'Оплата заказа №'.$orderNumber,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'metadata' => ['order_number' => $orderNumber],
        ];
        try {
            $proxy = env('HTTP_CLIENT_PROXY');
            $verify = config('services.tbank.verify_ssl', true);
            $caBundle = config('services.tbank.ca_bundle_path');
            $options = ['connect_timeout' => 10, 'proxy' => $proxy ?: null, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]];
            $disableVerify = app()->environment('production') ? false : true;
            if (($settings['mode'] ?? 'test') !== 'live') {
                $disableVerify = true;
            }
            $options['verify'] = $disableVerify ? false : ($caBundle ?: $verify);
            $http = \Illuminate\Support\Facades\Http::timeout(30)->retry(2, 1000)->withOptions($options)->withHeaders([
                'Idempotence-Key' => uniqid('yk_', true),
                'Content-Type' => 'application/json',
            ])->withBasicAuth($shopId, $secretKey);
            $response = $http->post('https://api.yookassa.ru/v3/payments', $payload);
            $data = $response->json();
            if ($response->successful() && isset($data['confirmation']['confirmation_url'])) {
                $paymentUrl = $data['confirmation']['confirmation_url'];
                // Если включена двухэтапная оплата — создаём заказ и не редиректим
                if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                    $order = $this->createOrderFromPayload(
                        $orderData,
                        $paymentMethod->id,
                        $orderNumber,
                        $request->ip(),
                        $request->userAgent()
                    );
                    if ($order) {
                        $pendingId = ShopPaymentStatus::where('name', 'pending')->value('id');
                        $order->update([
                            'payment_url' => $paymentUrl,
                            'payment_status_id' => $pendingId ?: $order->payment_status_id,
                            'yookassa_payment_id' => $data['id'] ?? null,
                        ]);
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $paymentMethod->id,
                            'amount' => $order->total_amount,
                            'status' => 'pending',
                            'transaction_id' => $data['id'] ?? null,
                            'request_data' => [
                                'order_data' => $orderData,
                                'order_number' => $orderNumber,
                                'ip' => $request->ip(),
                                'user_agent' => $request->userAgent(),
                            ],
                            'response_data' => $data,
                        ]);
                        try {
                            app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                        } catch (\Exception $e) {
                            \Log::error('YooKassa two-stage: failed to send order_created notification', [
                                'order_id' => $order->id ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return response()->json([
                            'success' => true,
                            'two_stage_pay' => true,
                            'payment_url' => $paymentUrl,
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                        ]);
                    }
                    // если заказ создать не удалось — падаем в режим черновика
                }

                // Обычный (одноэтапный) режим — кэшируем данные и редиректим на оплату
                $cacheKey = 'payment:init:yookassa:'.($data['id'] ?? '');
                Cache::put($cacheKey, [
                    'order_number' => $orderNumber,
                    'order_data' => $orderData,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $orderData['total_amount'] ?? 0,
                    'gateway_response' => $data,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ], now()->addDays(2));

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentUrl,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $data['description'] ?? 'ЮКасса: не удалось создать платеж',
                'details' => $data,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('YooKassa connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'ЮКасса: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('YooKassa create payment failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'ЮКасса: ошибка создания платежа'], 500);
        }
    }

    protected function handleYandexPayPayment(ShopOrder $order, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings ?? []);
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame'];
        $secretKey = $settings['secret_key'] ?? null;
        if (empty($secretKey)) {
            return response()->json(['success' => false, 'message' => 'Яндекс Пэй: отсутствует secret_key'], 400);
        }
        $isTest = ($settings['mode'] ?? 'test') !== 'live';
        $apiUrl = $isTest ? 'https://sandbox.pay.yandex.ru/api/merchant/v1' : 'https://pay.yandex.ru/api/merchant/v1';
        $returnUrl = request()->input('return_url') ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=yandex_pay');
        $amountValue = number_format((float) $order->total_amount + (float) ($order->delivery_cost ?? 0), 2, '.', '');
        $merchantId = $settings['merchant_id'] ?? null;
        $payload = [
            'orderId' => $order->order_number,
            'merchantId' => $merchantId,
            'currencyCode' => $settings['currency'] ?? 'RUB',
            'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
            'cart' => [
                'items' => [[
                    'productId' => 'ORDER-'.$order->id,
                    'description' => 'Оплата заказа №'.$order->order_number,
                    'quantity' => ['count' => '1.0', 'available' => '1.0'],
                    'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
                    'total' => $amountValue,
                ]],
                'total' => ['amount' => $amountValue],
            ],
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'redirectUrls' => [
                'onSuccess' => $returnUrl,
                'onError' => $returnUrl,
            ],
            'metadata' => json_encode(['order_id' => $order->id, 'payment_method_id' => $paymentMethod->id]),
        ];
        try {
            $proxy = env('HTTP_CLIENT_PROXY');
            $verify = config('services.tbank.verify_ssl', true);
            $caBundle = config('services.tbank.ca_bundle_path');
            $options = [
                'connect_timeout' => 10,
                'proxy' => $proxy ?: null,
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ];
            $options['verify'] = $caBundle ?: $verify;
            $http = \Illuminate\Support\Facades\Http::timeout(30)->retry(2, 1000)->withOptions($options)->withHeaders([
                'Authorization' => 'Api-Key '.$secretKey,
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('yp_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ]);
            \Log::info('Yandex Pay Request Debug', [
                'url' => $apiUrl.'/orders',
                'payload' => $payload,
                'merchant_id_used' => $merchantId,
                'secret_key_used' => substr((string)$secretKey, 0, 10) . '...', // Логируем начало ключа для проверки
            ]);

            $response = $http->post($apiUrl.'/orders', $payload);
            $data = $response->json();
            if ($response->successful()) {
                $paymentUrl = null;
                $yandexOrderId = null;

                if (isset($data['confirmation']['confirmation_url'])) {
                    $paymentUrl = $data['confirmation']['confirmation_url'];
                    $yandexOrderId = $data['id'] ?? ($data['orderId'] ?? null);
                } elseif (isset($data['data']) && is_array($data['data']) && ! empty($data['data']['paymentUrl'])) {
                    $paymentUrl = $data['data']['paymentUrl'];
                    $yandexOrderId = $data['data']['orderId'] ?? $data['data']['id'] ?? ($data['id'] ?? null);
                } elseif (isset($data['paymentUrl'])) {
                    $paymentUrl = $data['paymentUrl'];
                    $yandexOrderId = $data['orderId'] ?? $data['id'] ?? null;
                }

                if ($paymentUrl) {
                    $order->update([
                        'payment_method_id' => $paymentMethod->id,
                        'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                        'payment_url' => $paymentUrl,
                        'yandex_pay_order_id' => $yandexOrderId,
                    ]);
                    ShopPaymentTransaction::create([
                        'order_id' => $order->id,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $order->total_amount,
                        'status' => 'pending',
                        'transaction_id' => $yandexOrderId,
                        'response_data' => $data,
                    ]);
                    try {
                        app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                    } catch (\Exception $e) {
                        \Log::error('Yandex Pay: failed to send order_created notification', [
                            'order_id' => $order->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                        return response()->json([
                            'success' => true,
                            'two_stage_pay' => true,
                            'payment_url' => $paymentUrl,
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                        ]);
                    }

                    return response()->json([
                        'success' => true,
                        'payment_url' => $paymentUrl,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => $data['message'] ?? 'Яндекс Пэй: не удалось создать заказ',
                'details' => $data,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('Yandex Pay connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Яндекс Пэй: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('Yandex Pay create order failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Яндекс Пэй: ошибка создания заказа'], 500);
        }
    }

    protected function initYandexPayPayment(string $orderNumber, array $orderData, ShopPaymentMethod $paymentMethod, ?string $returnUrl, Request $request): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = method_exists($paymentMethod, 'getApiSettings')
            ? $paymentMethod->getApiSettings()
            : $this->normalizePaymentSettings($paymentMethod->settings ?? []);
        $secretKey = $settings['secret_key'] ?? ($settings['api_key'] ?? ($settings['apiKey'] ?? ($settings['token'] ?? null)));
        if (is_string($secretKey)) {
            $secretKey = trim(preg_replace('/^(Api-Key|Bearer)\s+/i', '', $secretKey));
        }
        if (empty($secretKey)) {
            return response()->json(['success' => false, 'message' => 'Яндекс Пэй: отсутствует secret_key'], 400);
        }
        $isTest = ($settings['mode'] ?? 'test') !== 'live';
        $apiUrl = $isTest ? 'https://sandbox.pay.yandex.ru/api/merchant/v1' : 'https://pay.yandex.ru/api/merchant/v1';
        $returnUrl = $returnUrl ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=yandex_pay');
        $amountValue = number_format($orderData['total_amount'] ?? 0, 2, '.', '');
        // Определяем двухэтапность
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay && class_exists(\App\Models\Setting::class)) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame', 'tbank_eacq'];
        $payload = [
            'orderId' => $orderNumber,
            'currencyCode' => $settings['currency'] ?? 'RUB',
            'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
            'cart' => [
                'items' => [[
                    'productId' => 'ORDER-DRAFT',
                    'title' => 'Заказ '.$orderNumber,
                    'description' => 'Оплата заказа №'.$orderNumber,
                    'quantity' => ['count' => '1.0', 'available' => '1.0'],
                    'amount' => ['value' => $amountValue, 'currency' => $settings['currency'] ?? 'RUB'],
                    'total' => $amountValue,
                ]],
                'total' => ['amount' => $amountValue],
            ],
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'metadata' => json_encode(['order_number' => $orderNumber, 'payment_method_id' => $paymentMethod->id]),
        ];
        try {
            $proxy = env('HTTP_CLIENT_PROXY');
            $verify = config('services.tbank.verify_ssl', true);
            $caBundle = config('services.tbank.ca_bundle_path');
            $options = ['connect_timeout' => 10, 'proxy' => $proxy ?: null, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]];
            $disableVerify = app()->environment('production') ? false : true;
            if (($settings['mode'] ?? 'test') !== 'live') {
                $disableVerify = true;
            }
            $options['verify'] = $disableVerify ? false : ($caBundle ?: $verify);
            $http = \Illuminate\Support\Facades\Http::timeout(30)->retry(2, 1000)->withOptions($options)->withHeaders([
                'Authorization' => 'Api-Key '.$secretKey,
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('yp_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ]);
            $response = $http->post($apiUrl.'/orders', $payload);
            $data = $response->json();

            if ($response->successful()) {
                $paymentUrl = null;
                $yandexOrderId = null;

                if (isset($data['confirmation']['confirmation_url'])) {
                    $paymentUrl = $data['confirmation']['confirmation_url'];
                    $yandexOrderId = $data['id'] ?? ($data['orderId'] ?? null);
                } elseif (isset($data['data']) && is_array($data['data']) && ! empty($data['data']['paymentUrl'])) {
                    $paymentUrl = $data['data']['paymentUrl'];
                    $yandexOrderId = $data['data']['orderId'] ?? $data['data']['id'] ?? ($data['id'] ?? null);
                } elseif (isset($data['paymentUrl'])) {
                    $paymentUrl = $data['paymentUrl'];
                    $yandexOrderId = $data['orderId'] ?? $data['id'] ?? null;
                }

                if ($paymentUrl) {
                    // Если включена двухэтапная оплата — создаём заказ и НЕ редиректим
                    if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                        $order = $this->createOrderFromPayload(
                            $orderData,
                            $paymentMethod->id,
                            $orderNumber,
                            $request->ip(),
                            $request->userAgent()
                        );
                        if ($order) {
                            $pendingId = ShopPaymentStatus::where('name', 'pending')->value('id');
                            $order->update([
                                'payment_url' => $paymentUrl,
                                'payment_status_id' => $pendingId ?: $order->payment_status_id,
                                'yandex_pay_order_id' => $yandexOrderId,
                            ]);
                            ShopPaymentTransaction::create([
                                'order_id' => $order->id,
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => $order->total_amount,
                                'transaction_id' => $yandexOrderId,
                                'status' => 'pending',
                                'request_data' => [
                                    'order_data' => $orderData,
                                    'order_number' => $orderNumber,
                                    'ip' => $request->ip(),
                                    'user_agent' => $request->userAgent(),
                                ],
                                'response_data' => $data,
                            ]);
                            try {
                                app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                            } catch (\Exception $e) {
                                \Log::error('Yandex Pay two-stage: failed to send order_created notification', [
                                    'order_id' => $order->id ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                            }

                            return response()->json([
                                'success' => true,
                                'two_stage_pay' => true,
                                'payment_url' => $paymentUrl,
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                            ]);
                        }
                        // если заказ создать не удалось — падаем в режим черновика
                    }
                    // Обычный (одноэтапный) режим — возвращаем ссылку для редиректа и кэшируем черновик
                    $cacheKey = 'payment:init:yandex_pay:'.($yandexOrderId ?: ($data['id'] ?? $orderNumber));
                    Cache::put($cacheKey, [
                        'order_number' => $orderNumber,
                        'order_data' => $orderData,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $orderData['total_amount'] ?? 0,
                        'gateway_response' => $data,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ], now()->addDays(2));

                    return response()->json([
                        'success' => true,
                        'payment_url' => $paymentUrl,
                    ]);
                }
            }
            \Illuminate\Support\Facades\Log::error('Yandex Pay create order failed', [
                'status' => $response->status(),
                'response' => $data,
                'payload' => $payload,
            ]);

            return response()->json([
                'success' => false,
                'message' => $data['message'] ?? 'Яндекс Пэй: не удалось создать заказ',
                'details' => $data,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('Yandex Pay connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Яндекс Пэй: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('Yandex Pay create order failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Яндекс Пэй: ошибка создания заказа'], 500);
        }
    }

    protected function initTbankEacqPayment(string $orderNumber, array $orderData, ShopPaymentMethod $paymentMethod, ?string $returnUrl, Request $request): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings);
        if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
            \Log::error('T-Bank eacq init: missing terminal_key or terminal_password', ['method_id' => $paymentMethod->id]);

            return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured'], 500);
        }
        // Определяем двухэтапность
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
        if (! $shouldBeTwoStagePay && class_exists(\App\Models\Setting::class)) {
            $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
            $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
        }
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame', 'tbank_eacq'];
        $tbankService = new TbankPaymentService($settings); // без DOLYAMI
        try {
            $orderStub = new \stdClass;
            $orderStub->id = 0;
            $orderStub->order_number = $orderNumber;
            $orderStub->total_amount = (float) ($orderData['total_amount'] ?? 0);
            $orderStub->customer_email = $orderData['customer_email'] ?? null;
            $orderStub->customer_phone = $orderData['customer_phone'] ?? null;
            $orderStub->user_id = $orderData['customer_id'] ?? null;
            $orderStub->delivery_cost = (float) ($orderData['delivery_cost'] ?? 0);
            $orderStub->items = $orderData['items'] ?? [];

            $init = $tbankService->initiatePayment($orderStub);
            if (! empty($init['success']) && ! empty($init['payment_url'])) {
                if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed)) {
                    // Создаем заказ и сохраняем ссылку для последующей оплаты
                    $order = $this->createOrderFromPayload(
                        $orderData,
                        $paymentMethod->id,
                        $orderNumber,
                        $request->ip(),
                        $request->userAgent()
                    );
                    if ($order) {
                        $pendingId = ShopPaymentStatus::where('name', 'pending')->value('id');
                        $order->update([
                            'payment_url' => $init['payment_url'],
                            'payment_status_id' => $pendingId ?: $order->payment_status_id,
                        ]);
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $paymentMethod->id,
                            'amount' => $order->total_amount,
                            'transaction_id' => $init['transaction_id'] ?? null,
                            'status' => 'pending',
                            'request_data' => [
                                'order_data' => $orderData,
                                'order_number' => $orderNumber,
                                'ip' => $request->ip(),
                                'user_agent' => $request->userAgent(),
                            ],
                            'response_data' => $init['response_data'] ?? null,
                        ]);
                        try {
                            app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                        } catch (\Exception $e) {
                            \Log::error('T-Bank eacq two-stage: failed to send order_created notification', [
                                'order_id' => $order->id ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return response()->json([
                            'success' => true,
                            'two_stage_pay' => true,
                            'payment_url' => $init['payment_url'],
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                        ]);
                    }
                }
                $cacheBasePayload = [
                    'order_number' => $orderNumber,
                    'order_data' => $orderData,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $orderData['total_amount'] ?? 0,
                    'gateway_response' => $init['response_data'] ?? null,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ];
                // Кладём в кэш по двум ключам: по PaymentId (если есть) и по номеру заказа
                $cacheKeyTx = 'payment:init:tbank_eacq:'.($init['transaction_id'] ?? $orderNumber);
                Cache::put($cacheKeyTx, $cacheBasePayload, now()->addDays(2));
                $cacheKeyByOrder = 'payment:init:tbank_eacq:order:'.$orderNumber;
                Cache::put($cacheKeyByOrder, $cacheBasePayload, now()->addDays(2));

                return response()->json([
                    'success' => true,
                    'payment_url' => $init['payment_url'],
                ]);
            }
            \Log::error('T-Bank eacq create payment failed', ['response' => $init, 'order_number' => $orderNumber]);

            return response()->json([
                'success' => false,
                'message' => $init['message'] ?? 'Т‑Банк: не удалось создать платеж',
                'details' => $init['response_data'] ?? $init,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('T-Bank eacq connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Т‑Банк: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('T-Bank eacq init exception: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json(['success' => false, 'message' => 'Т‑Банк: внутренняя ошибка'], 500);
        }
    }

    protected function initTbankDolyamePayment(string $orderNumber, array $orderData, ShopPaymentMethod $paymentMethod, ?string $returnUrl, Request $request): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings);
        $provider = $settings['dolyame_provider'] ?? 'tbank';
        Log::debug('handleTbankDolyamePayment: provider determined', [
            'provider' => $provider,
            'dolyame_provider_setting' => $settings['dolyame_provider'] ?? 'not_set',
        ]);
        if ($provider === 'partner') {
            $login = $settings['dolyame_login1'] ?? $settings['dolyame_login'] ?? '';
            $password = $settings['dolyame_password1'] ?? $settings['dolyame_password'] ?? '';
            
            if (empty($login) || empty($password)) {
                \Log::error('Dolyame partner init: missing login or password', ['method_id' => $paymentMethod->id, 'settings' => $settings]);

                return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured (Dolyame Partner)'], 500);
            }
            $partnerSettings = [
                'api_url' => $settings['api_url'] ?? config('services.dolyame.api_url'),
                'dolyame_login' => $login,
                'dolyame_password' => $password,
                'verify_ssl' => $settings['verify_ssl'] ?? config('services.dolyame.verify_ssl', true),
                'ca_bundle_path' => $settings['ca_bundle_path'] ?? config('services.dolyame.ca_bundle_path'),
                'cert_path' => $settings['dolyame_cert_path'] ?? null,
                'key_path' => $settings['dolyame_cert_key_path'] ?? null,
                'key_password' => $settings['dolyame_cert_key_password'] ?? null,
            ];
            $service = new \App\Services\DolyamePartnerService($partnerSettings);
            try {
                $amount = (float) ($orderData['total_amount'] ?? 0);
                $items = array_map(function ($it) {
                    return [
                        'name' => $it['good_name'] ?? $it['name'] ?? 'Товар',
                        'quantity' => (float) ($it['quantity'] ?? 1),
                        'price' => (float) ($it['final_price'] ?? $it['price'] ?? 0),
                    ];
                }, $orderData['items'] ?? []);
                $successUrl = ($returnUrl ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=tbank_dolyame'));
                $failUrl = ($returnUrl ?: (config('app.frontend_url').'/checkout?payment=return&payment_type=tbank_dolyame'));
                $notificationUrl = url('/api/webhooks/dolyame');
                $res = $service->createOrder($orderNumber, $amount, $items, $notificationUrl, $successUrl, $failUrl);
                if (! empty($res['success'])) {
                    $cacheKey = 'payment:init:dolyame:'.$orderNumber;
                    Cache::put($cacheKey, [
                        'order_number' => $orderNumber,
                        'order_data' => $orderData,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $amount,
                        'gateway_response' => $res['response'] ?? null,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ], now()->addDays(2));

                    return response()->json([
                        'success' => true,
                        'payment_url' => $res['link'] ?? null,
                    ]);
                }
                \Log::error('Dolyame partner create order failed', ['response' => $res, 'order_number' => $orderNumber]);

                return response()->json([
                    'success' => false,
                    'message' => $res['message'] ?? 'Dolyame: не удалось создать заказ',
                    'details' => $res['response'] ?? $res,
                ], 400);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                \Log::error('Dolyame partner connection failed: '.$e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Dolyame: ошибка подключения',
                    'details' => $e->getMessage(),
                ], 502);
            } catch (\Exception $e) {
                \Log::error('Dolyame partner init exception: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

                return response()->json(['success' => false, 'message' => 'Dolyame: внутренняя ошибка'], 500);
            }
        }
        if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
            \Log::error('T-Bank init: missing terminal_key or terminal_password', ['method_id' => $paymentMethod->id]);

            return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured'], 500);
        }
        $tbankService = new TbankPaymentService(array_merge($settings, ['pay_type' => 'DOLYAMI']));
        try {
            $orderStub = new \stdClass;
            $orderStub->id = 0;
            $orderStub->order_number = $orderNumber;
            $orderStub->total_amount = $orderData['total_amount'] ?? 0;
            $orderStub->customer_email = $orderData['customer_email'] ?? null;
            $orderStub->customer_phone = $orderData['customer_phone'] ?? null;
            $orderStub->items = $orderData['items'] ?? [];
            $orderStub->delivery_cost = $orderData['delivery_cost'] ?? 0;
            $orderStub->user_id = $orderData['customer_id'] ?? null;
            $result = $tbankService->initiatePayment($orderStub);
            if (! empty($result['success'])) {
                // Поддержка двухэтапной оплаты: создаем заказ и сохраняем ссылку
                $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false;
                if (! $shouldBeTwoStagePay && class_exists(\App\Models\Setting::class)) {
                    $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
                    $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
                }
                $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame', 'tbank_eacq'];
                if ($shouldBeTwoStagePay && in_array($paymentMethod->type, $twoStagePaymentTypesAllowed) && ! empty($result['payment_url'])) {
                    $order = $this->createOrderFromPayload(
                        $orderData,
                        $paymentMethod->id,
                        $orderNumber,
                        $request->ip(),
                        $request->userAgent()
                    );
                    if ($order) {
                        $pendingId = ShopPaymentStatus::where('name', 'pending')->value('id');
                        $order->update([
                            'payment_url' => $result['payment_url'],
                            'payment_method_id' => $paymentMethod->id,
                            'payment_status_id' => $pendingId ?: $order->payment_status_id,
                        ]);
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $paymentMethod->id,
                            'amount' => $order->total_amount,
                            'transaction_id' => $result['transaction_id'] ?? null,
                            'request_data' => [
                                'order_data' => $orderData,
                                'order_number' => $orderNumber,
                                'ip' => $request->ip(),
                                'user_agent' => $request->userAgent(),
                            ],
                            'response_data' => $result['response_data'] ?? null,
                            'status' => 'pending',
                        ]);
                        try {
                            app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                        } catch (\Exception $e) {
                            \Log::error('T-Bank Dolyame two-stage: failed to send order_created notification', [
                                'order_id' => $order->id ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return response()->json([
                            'success' => true,
                            'two_stage_pay' => true,
                            'payment_url' => $result['payment_url'],
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                        ]);
                    }
                }
                $txId = $result['transaction_id'] ?? null;
                if ($txId) {
                    $cacheKey = 'payment:init:tbank_dolyame:'.$txId;
                    Cache::put($cacheKey, [
                        'order_number' => $orderNumber,
                        'order_data' => $orderData,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $orderData['total_amount'] ?? 0,
                        'gateway_response' => $result,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ], now()->addDays(2));
                }

                return response()->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'] ?? null,
                ]);
            }
            if (! empty($result['error']) && $result['error'] === 'connection_timeout') {
                return response()->json([
                    'success' => false,
                    'message' => 'T-Bank: ошибка подключения',
                    'details' => $result['message'] ?? 'connection timeout',
                ], 502);
            }
            \Log::error('T-Bank create order failed', ['response' => $result, 'order_number' => $orderNumber]);

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'T-Bank: не удалось создать заказ',
                'details' => $result,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('T-Bank connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'T-Bank: ошибка подключения',
                'details' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            \Log::error('T-Bank init payment exception: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json(['success' => false, 'message' => 'T-Bank: внутренняя ошибка'], 500);
        }
    }

    protected function handleTbankDolyamePayment(ShopOrder $order, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        // Проверяем, что способ оплаты активен
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }

        $settings = $this->normalizePaymentSettings($paymentMethod->settings);
        Log::debug('handleTbankDolyamePayment: settings for payment method', [
            'method_id' => $paymentMethod->id,
            'settings' => $settings,
            'raw_settings' => $paymentMethod->settings,
            'settings_keys' => array_keys($settings),
            'has_shop_id' => isset($settings['shop_id']),
            'has_dolyame_shop_id' => isset($settings['dolyame_shop_id']),
        ]);

        // Определяем, должен ли этот платеж быть двухэтапным.
        // Приоритет: настройка в самом способе оплаты, затем глобальная настройка.
        $shouldBeTwoStagePay = $settings['two_stage_pay'] ?? false; // Локальная настройка в paymentMethod->settings

        // Если локальная настройка не true, проверяем глобальную
        if (! $shouldBeTwoStagePay) {
            // Если App\Models\Setting доступна, используем ее.
            // Проверяем class_exists, чтобы избежать ошибок, если модель Setting отсутствует
            if (class_exists(\App\Models\Setting::class)) {
                $globalTwoStagePaySetting = \App\Models\Setting::where('key', 'two_stage_pay')->value('value');
                $shouldBeTwoStagePay = $globalTwoStagePaySetting === '1' || $globalTwoStagePaySetting === true || $globalTwoStagePaySetting === 1;
            }
        }
        Log::debug('handleTbankDolyamePayment: determined shouldBeTwoStagePay', ['order_id' => $order->id, 'shouldBeTwoStagePay' => $shouldBeTwoStagePay]);

        // Дополнительно: Проверяем, что тип платежа 'tbank_dolyame' вообще допустим для двухэтапной оплаты
        // (аналогично тому, как это делается на фронтенде в requiresTwoStageApproval)
        $twoStagePaymentTypesAllowed = ['transfer', 'yandex_pay', 'yandex_split', 'yookassa', 'tbank_dolyame'];
        $isPaymentTypeAllowedForTwoStage = in_array($paymentMethod->type, $twoStagePaymentTypesAllowed);

        $provider = $settings['dolyame_provider'] ?? 'tbank';

        if ($provider === 'partner') {
            if (empty($settings['dolyame_login1']) || empty($settings['dolyame_password1'])) {
                Log::error('handleTbankDolyamePayment: Dolyame Partner payment method '.$paymentMethod->id.' is missing dolyame_login1 or dolyame_password1 in settings.');

                return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured (Dolyame Partner)'], 500);
            }
        } else {
            if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
                Log::error('handleTbankDolyamePayment: T-Bank payment method '.$paymentMethod->id.' is missing terminal_key or terminal_password in settings.');

                return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured (T-Bank)'], 500);
            }
        }
        $tbankService = new TbankPaymentService($settings);
        Log::debug('handleTbankDolyamePayment: TbankPaymentService instantiated', [
            'settings_keys' => array_keys($settings),
            'provider' => $settings['dolyame_provider'] ?? 'tbank',
            'settings' => $settings, // Log the full settings to see what's available
        ]);
        try {
            Log::debug('handleTbankDolyamePayment: Initiating payment via TbankService for order', ['order_id' => $order->id, 'payment_method_id' => $paymentMethod->id]);
            $paymentResult = $tbankService->initiatePayment($order);
            Log::debug('handleTbankDolyamePayment: Payment initiation result from TbankService', ['order_id' => $order->id, 'payment_result' => $paymentResult]);
            if (isset($paymentResult['success']) && $paymentResult['success']) {
                $transaction = ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $order->total_amount,
                    'transaction_id' => $paymentResult['transaction_id'] ?? null,
                    'request_data' => $paymentResult['request_data'] ?? null,
                    'response_data' => $paymentResult['response_data'] ?? null,
                    'status' => 'pending',
                ]);
                $order->update([
                    'payment_url' => $paymentResult['payment_url'] ?? null,
                    'payment_method_id' => $paymentMethod->id,
                    'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                ]);
                try {
                    app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                } catch (\Exception $e) {
                    \Log::error('T-Bank Dolyame: failed to send order_created notification', [
                        'order_id' => $order->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
                if ($shouldBeTwoStagePay && $isPaymentTypeAllowedForTwoStage) {
                    return response()->json([
                        'success' => true,
                        'two_stage_pay' => true,
                        'payment_url' => $paymentResult['payment_url'] ?? null,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentResult['payment_url'],
                    'transaction_id' => $transaction->id,
                ]);
            } elseif (isset($paymentResult['error']) && $paymentResult['error'] === 'connection_timeout') {
                ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $order->total_amount,
                    'status' => 'pending',
                    'response_data' => ['message' => 'Gateway unreachable, awaiting manager approval'],
                ]);
                $order->update([
                    'payment_method_id' => $paymentMethod->id,
                    'payment_status_id' => ShopPaymentStatus::where('name', 'pending')->value('id'),
                    'payment_url' => null,
                ]);

                return response()->json([
                    'success' => true,
                    'two_stage_pay' => true,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Gateway unreachable',
                ]);
            }
            Log::error('handleTbankDolyamePayment: T-Bank payment initiation failed for order '.$order->id.': '.($paymentResult['message'] ?? 'Unknown error'), ['payment_result' => $paymentResult]);

            return response()->json(['success' => false, 'message' => $paymentResult['message'] ?? 'Failed to initiate payment'], 500);
        } catch (\Exception $e) {
            Log::error('handleTbankDolyamePayment: Exception during T-Bank payment initiation for order '.$order->id.': '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => 'An error occurred during payment processing'], 500);
        }
    }

    protected function handleTbankDolyamePaymentDraft(ShopPaymentTransaction $transaction, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        if (! $paymentMethod->is_active) {
            return response()->json(['success' => false, 'message' => 'Payment method is inactive'], 400);
        }
        $settings = $this->normalizePaymentSettings($paymentMethod->settings);
        Log::debug('handleTbankDolyamePaymentDraft: settings for payment method', ['method_id' => $paymentMethod->id, 'settings' => $settings]);
        if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
            Log::error('handleTbankDolyamePaymentDraft: missing terminal_key or terminal_password');

            return response()->json(['success' => false, 'message' => 'Payment gateway misconfigured'], 500);
        }
        $tbankService = new TbankPaymentService($settings);
        try {
            Log::debug('handleTbankDolyamePaymentDraft: Initiating payment via TbankService for tx', ['tx_id' => $transaction->id, 'payment_method_id' => $paymentMethod->id]);
            // Создаем «виртуальный» объект заказа для сервисного слоя (минимально необходимый набор)
            $orderStub = new \stdClass;
            $orderStub->id = 0;
            $orderStub->order_number = $transaction->request_data['order_number'] ?? ('TX-'.$transaction->id);
            $orderStub->total_amount = $transaction->amount;
            $paymentResult = $tbankService->initiatePayment($orderStub);
            Log::debug('handleTbankDolyamePaymentDraft: Payment initiation result', ['tx_id' => $transaction->id, 'payment_result' => $paymentResult]);
            if (isset($paymentResult['success']) && $paymentResult['success']) {
                $transaction->update([
                    'transaction_id' => $paymentResult['transaction_id'] ?? null,
                    'request_data' => array_merge($transaction->request_data ?? [], ['tbank_request' => $paymentResult['request_data'] ?? null]),
                    'response_data' => $paymentResult['response_data'] ?? null,
                    'status' => 'pending',
                ]);

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentResult['payment_url'] ?? null,
                    'transaction_id' => $transaction->id,
                ]);
            } elseif (isset($paymentResult['error']) && $paymentResult['error'] === 'connection_timeout') {
                $transaction->update([
                    'status' => 'pending',
                    'response_data' => ['message' => 'Gateway unreachable, awaiting manager approval'],
                ]);

                return response()->json([
                    'success' => true,
                    'two_stage_pay' => true,
                    'message' => 'Gateway unreachable',
                    'transaction_id' => $transaction->id,
                ]);
            }
            Log::error('handleTbankDolyamePaymentDraft: initiation failed for tx '.$transaction->id, ['payment_result' => $paymentResult]);

            return response()->json(['success' => false, 'message' => $paymentResult['message'] ?? 'Failed to initiate payment'], 500);
        } catch (\Exception $e) {
            Log::error('handleTbankDolyamePaymentDraft: Exception for tx '.$transaction->id.': '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => 'An error occurred during payment processing'], 500);
        }
    }

    /**
     * Handle webhook for T-Bank (Dolyami).
     * This method should be available at: YOUR_APP_URL/api/webhooks/tbank
     *
     * @return \Illuminate\Http\Response
     */
    public function tbankWebhook(Request $request)
    {
        $webhookData = $request->all();
        Log::info('Received T-Bank webhook notification', ['data' => $webhookData]);

        // Перехват вебхуков от Долями Партнерского API, отправленных на старый URL
        if (isset($webhookData['id']) && !isset($webhookData['OrderId']) && !isset($webhookData['PaymentId'])) {
            Log::info('T-Bank Webhook: Redirecting to Dolyame Partner webhook due to payload format.');
            return $this->dolyamePartnerWebhook($request);
        }

        $settings = $this->normalizePaymentSettings($this->getPaymentMethodSettings($webhookData));
        $tbankService = new TbankPaymentService($settings);
        if (! $tbankService->verifyWebhook($webhookData, $request)) {
            Log::warning('T-Bank webhook verification failed.');

            return response('Invalid signature or token', 400);
        }

        // Вычисляем новый статус один раз
        $newStatus = $tbankService->processWebhookStatus($webhookData);

        // 2. Поиск соответствующей транзакции
        $transaction = $tbankService->findTransactionByWebhookData($webhookData);
        if (! $transaction) {
            Log::warning('T-Bank webhook: Transaction not found for webhook data, trying draft.', ['data' => $webhookData]);
            // Попробуем создать заказ из черновика для eacq по PaymentId или OrderId
            $draft = null;
            $paymentId = $webhookData['PaymentId'] ?? null;
            $orderIdParam = $webhookData['OrderId'] ?? null;
            if ($paymentId) {
                $draft = Cache::get('payment:init:tbank_eacq:'.$paymentId);
            }
            if (! $draft && $orderIdParam) {
                $draft = Cache::get('payment:init:tbank_eacq:order:'.$orderIdParam);
            }
            if ($draft && is_array($draft)) {
                $order = $this->createOrderFromPayload(
                    $draft['order_data'] ?? [],
                    $draft['payment_method_id'] ?? null,
                    $draft['order_number'] ?? ($orderIdParam ?: $paymentId ?: $this->generateOrderNumber()),
                    $draft['ip'] ?? null,
                    $draft['user_agent'] ?? null
                );
                if ($order) {
                    if ($newStatus === 'success') {
                        $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                        if ($paidStatusId) {
                            $order->update([
                                'payment_status_id' => $paidStatusId,
                                'payed' => true,
                            ]);
                        }
                    } else {
                        $failedStatusId = ShopPaymentStatus::where('name', 'failed')->value('id') ?? ShopPaymentStatus::where('name', 'canceled')->value('id');
                        if ($failedStatusId) {
                            $order->update([
                                'payment_status_id' => $failedStatusId,
                                'payed' => false,
                            ]);
                        }
                    }
                    $transaction = ShopPaymentTransaction::create([
                        'order_id' => $order->id,
                        'payment_method_id' => $draft['payment_method_id'] ?? null,
                        'amount' => $order->total_amount,
                        'transaction_id' => $paymentId,
                        'status' => $newStatus === 'success' ? 'paid' : 'failed',
                        'request_data' => $draft,
                        'response_data' => $webhookData,
                    ]);
                    try {
                        app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                    } catch (\Exception $e) {
                        \Log::error('T-Bank eacq webhook: failed to send order_created notification', [
                            'order_id' => $order->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    if (! empty($draft['order_number'])) {
                        Cache::forget('payment:init:tbank_eacq:order:'.$draft['order_number']);
                    }
                    if ($paymentId) {
                        Cache::forget('payment:init:tbank_eacq:'.$paymentId);
                    }
                }
            }
            if (! $transaction) {
                return response('Transaction not found', 404);
            }
        }

        $order = $transaction->order;
        if (! $order && ($this->normalizePaymentSettings($this->getPaymentMethodSettings($webhookData)) ?? true)) {
            if ($newStatus === 'success') {
                $order = $this->createOrderFromTransaction($transaction, 'tbank_dolyame');
                if ($order) {
                    $transaction->update(['order_id' => $order->id]);
                }
            }
        }
        if (! $order) {
            // Пытаемся найти данные черновика в кеше по предполагаемому идентификатору платежа
            $paymentId = $webhookData['PaymentId'] ?? $webhookData['payment_id'] ?? null;
            if ($paymentId) {
                $cacheKey = 'payment:init:tbank_dolyame:'.$paymentId;
                $draft = Cache::get($cacheKey);
                if ($draft && is_array($draft)) {
                    $order = $this->createOrderFromPayload(
                        $draft['order_data'] ?? [],
                        $draft['payment_method_id'] ?? null,
                        $draft['order_number'] ?? $this->generateOrderNumber(),
                        $draft['ip'] ?? null,
                        $draft['user_agent'] ?? null
                    );
                    if ($order) {
                        if ($newStatus === 'success') {
                            $paidStatusId = ShopPaymentStatus::where('name', 'paid')->value('id');
                            if ($paidStatusId) {
                                $order->update([
                                    'payment_status_id' => $paidStatusId,
                                    'payed' => true,
                                ]);
                            }
                        } else {
                            $failedStatusId = ShopPaymentStatus::where('name', 'failed')->value('id') ?? ShopPaymentStatus::where('name', 'canceled')->value('id');
                            if ($failedStatusId) {
                                $order->update([
                                    'payment_status_id' => $failedStatusId,
                                    'payed' => false,
                                ]);
                            }
                        }
                        
                        ShopPaymentTransaction::create([
                            'order_id' => $order->id,
                            'payment_method_id' => $draft['payment_method_id'] ?? null,
                            'amount' => $draft['amount'] ?? 0,
                            'transaction_id' => $paymentId,
                            'status' => $newStatus === 'success' ? 'paid' : 'failed',
                            'request_data' => $draft,
                            'response_data' => $webhookData,
                        ]);
                        Cache::forget($cacheKey);
                    }
                }
            }
            if (! $order) {
                Log::error('T-Bank webhook: Order not found for transaction '.$transaction->id, ['data' => $webhookData]);

                return response('Order not found', 404);
            }
        }

        // 3. Обработка статуса платежа
        $newStatus = $tbankService->processWebhookStatus($webhookData);

        // 4. Обновление статусов заказа и транзакции
        $paymentStatusMap = [
            'success' => 'paid',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
        ];

        if (isset($paymentStatusMap[$newStatus])) {
            $statusName = $paymentStatusMap[$newStatus];
            $statusId = ShopPaymentStatus::where('name', $statusName)->value('id');
            if ($statusId) {
                $order->update([
                    'payment_status_id' => $statusId,
                    'payed' => $newStatus === 'success' ? true : false,
                ]);
            } else {
                Log::warning('ShopPaymentStatus with name '.$statusName.' not found.');
            }
        }

        $transaction->update([
            'status' => $newStatus,
            'response_data' => $webhookData,
            'processed_at' => now(),
        ]);

        if ($newStatus === 'success') {
            try {
                app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
            } catch (\Exception $e) {
                Log::error('T-Bank webhook: failed to send order_created notification after success status', [
                    'order_id' => $order->id ?? null,
                    'transaction_id' => $transaction->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response('Webhook received', 200);
    }

    /**
     * Webhook для Dolyame Partner API (Долями партнерский)
     */
    public function dolyamePartnerWebhook(Request $request)
    {
        $webhookData = $request->all();
        \Log::info('Received Dolyame Partner webhook notification', ['data' => $webhookData]);

        $orderNumber = $webhookData['id'] ?? null;
        if (!$orderNumber) {
            return response('Missing order id', 400);
        }

        $order = \App\Models\ShopOrder::where('order_number', $orderNumber)->first();
        if (!$order) {
            \Log::warning('Dolyame webhook: Order not found', ['order_number' => $orderNumber]);
            return response('Order not found', 404);
        }

        $paymentMethod = $order->paymentMethod;
        if (!$paymentMethod || $paymentMethod->type !== 'tbank_dolyame') {
            $paymentMethod = \App\Models\ShopPaymentMethod::where('type', 'tbank_dolyame')
                ->where('is_active', true)
                ->first();
        }
        $settings = $this->normalizePaymentSettings($paymentMethod ? $paymentMethod->settings : []);
        
        \Log::info('Dolyame Webhook Debug: Payment method settings', [
            'method_id' => $paymentMethod ? $paymentMethod->id : 'null',
            'has_cert_path' => !empty($settings['dolyame_cert_path']),
        ]);
        
        $partnerSettings = [
            'api_url' => $settings['api_url'] ?? config('services.dolyame.api_url'),
            'dolyame_login' => $settings['dolyame_login1'] ?? $settings['dolyame_login'] ?? '',
            'dolyame_password' => $settings['dolyame_password1'] ?? $settings['dolyame_password'] ?? '',
            'verify_ssl' => $settings['verify_ssl'] ?? config('services.dolyame.verify_ssl', true),
            'ca_bundle_path' => $settings['ca_bundle_path'] ?? config('services.dolyame.ca_bundle_path'),
            'cert_path' => $settings['dolyame_cert_path'] ?? null,
            'key_path' => $settings['dolyame_cert_key_path'] ?? null,
            'key_password' => $settings['dolyame_cert_key_password'] ?? null,
        ];
        
        $service = new \App\Services\DolyamePartnerService($partnerSettings);
        
        // Verify via API
        $infoResponse = $service->getOrderInfo($orderNumber);
        if (empty($infoResponse['success'])) {
            \Log::error('Dolyame webhook: failed to get order info', ['response' => $infoResponse]);
            return response('Failed to verify order', 400);
        }
        
        $actualStatus = $infoResponse['response']['status'] ?? null;
        \Log::info('Dolyame webhook: actual order status', ['status' => $actualStatus, 'order_number' => $orderNumber]);

        if ($actualStatus === 'wait_for_commit') {
            \Log::info('Dolyame webhook: auto-committing order', ['order_number' => $orderNumber]);
            
            $items = [];
            $draft = \Illuminate\Support\Facades\Cache::get('payment:init:dolyame:'.$orderNumber);
            if ($draft && isset($draft['order_data']['items'])) {
                $items = array_map(function ($it) {
                    return [
                        'name' => $it['good_name'] ?? $it['name'] ?? 'Товар',
                        'quantity' => (float) ($it['quantity'] ?? 1),
                        'price' => (float) ($it['final_price'] ?? $it['price'] ?? 0),
                    ];
                }, $draft['order_data']['items']);
            } else {
                foreach ($order->shopOrderItems as $item) {
                    $items[] = [
                        'name' => $item->good->name ?? 'Товар',
                        'quantity' => (float) $item->quantity,
                        'price' => (float) $item->price,
                    ];
                }
                if ($order->delivery_cost > 0) {
                    $items[] = [
                        'name' => 'Доставка',
                        'quantity' => 1.0,
                        'price' => (float) $order->delivery_cost,
                    ];
                }
            }
            
            $commitResponse = $service->commitOrder($orderNumber, (float) $order->total_amount, $items);
            if (!empty($commitResponse['success'])) {
                \Log::info('Dolyame webhook: commit successful', ['response' => $commitResponse]);
                $paidStatusId = \App\Models\ShopPaymentStatus::where('name', 'paid')->value('id');
                if ($paidStatusId) {
                    $order->update([
                        'payment_status_id' => $paidStatusId,
                        'payed' => true,
                    ]);
                }
                
                $transaction = \App\Models\ShopPaymentTransaction::where('order_id', $order->id)->where('status', 'pending')->first();
                if ($transaction) {
                    $transaction->update(['status' => 'paid', 'response_data' => $commitResponse['response'] ?? null]);
                } else {
                     \App\Models\ShopPaymentTransaction::create([
                        'order_id' => $order->id,
                        'payment_method_id' => $paymentMethod->id ?? null,
                        'amount' => $order->total_amount,
                        'transaction_id' => $orderNumber,
                        'status' => 'paid',
                        'response_data' => $commitResponse['response'] ?? null
                    ]);
                }

                try {
                    app(\App\Services\NotificationService::class)->notifyOrderCreated($order);
                } catch (\Exception $e) {
                    \Log::error('Dolyame webhook: failed to send order_created notification', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

            } else {
                \Log::error('Dolyame webhook: commit failed', ['response' => $commitResponse]);
            }
        } elseif ($actualStatus === 'completed' || $actualStatus === 'committed') {
             $paidStatusId = \App\Models\ShopPaymentStatus::where('name', 'paid')->value('id');
             if ($paidStatusId && !$order->payed) {
                 $order->update([
                     'payment_status_id' => $paidStatusId,
                     'payed' => true,
                 ]);
             }
        } elseif ($actualStatus === 'rejected' || $actualStatus === 'canceled') {
            $failedStatusId = \App\Models\ShopPaymentStatus::where('name', 'canceled')->value('id') 
                           ?? \App\Models\ShopPaymentStatus::where('name', 'failed')->value('id');
            if ($failedStatusId) {
                $order->update([
                    'payment_status_id' => $failedStatusId,
                    'payed' => false,
                ]);
            }

            $transaction = \App\Models\ShopPaymentTransaction::where('order_id', $order->id)->where('status', 'pending')->first();
            if ($transaction) {
                $transaction->update([
                    'status' => $actualStatus === 'rejected' ? 'failed' : 'cancelled', 
                    'response_data' => $infoResponse['response'] ?? null
                ]);
            }
        }

        return response('Webhook processed', 200);
    }

    /**
     * Тестовый эндпоинт для возврата по Долями
     */
    public function testDolyameRefund($orderNumber)
    {
        $order = \App\Models\ShopOrder::where('order_number', $orderNumber)->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $paymentMethod = $order->paymentMethod;
        if (!$paymentMethod || $paymentMethod->type !== 'tbank_dolyame') {
            $paymentMethod = \App\Models\ShopPaymentMethod::where('type', 'tbank_dolyame')
                ->where('is_active', true)
                ->first();
        }

        $settings = $this->normalizePaymentSettings($paymentMethod ? $paymentMethod->settings : []);
        
        $partnerSettings = [
            'api_url' => $settings['api_url'] ?? config('services.dolyame.api_url'),
            'dolyame_login' => $settings['dolyame_login1'] ?? $settings['dolyame_login'] ?? '',
            'dolyame_password' => $settings['dolyame_password1'] ?? $settings['dolyame_password'] ?? '',
            'verify_ssl' => $settings['verify_ssl'] ?? config('services.dolyame.verify_ssl', true),
            'ca_bundle_path' => $settings['ca_bundle_path'] ?? config('services.dolyame.ca_bundle_path'),
            'cert_path' => $settings['dolyame_cert_path'] ?? null,
            'key_path' => $settings['dolyame_cert_key_path'] ?? null,
            'key_password' => $settings['dolyame_cert_key_password'] ?? null,
        ];
        
        $service = new \App\Services\DolyamePartnerService($partnerSettings);

        $items = [];
        $draft = \Illuminate\Support\Facades\Cache::get('payment:init:dolyame:'.$orderNumber);
        if ($draft && isset($draft['order_data']['items'])) {
            $items = array_map(function ($it) {
                return [
                    'name' => $it['good_name'] ?? $it['name'] ?? 'Товар',
                    'quantity' => (float) ($it['quantity'] ?? 1),
                    'price' => (float) ($it['final_price'] ?? $it['price'] ?? 0),
                ];
            }, $draft['order_data']['items']);
        } else {
            foreach ($order->shopOrderItems as $item) {
                $items[] = [
                    'name' => $item->good->name ?? 'Товар',
                    'quantity' => (float) $item->quantity,
                    'price' => (float) $item->price,
                ];
            }
            if ($order->delivery_cost > 0) {
                $items[] = [
                    'name' => 'Доставка',
                    'quantity' => 1.0,
                    'price' => (float) $order->delivery_cost,
                ];
            }
        }

        $response = $service->refundOrder($orderNumber, (float) $order->total_amount, $items);

        if (!empty($response['success'])) {
            $refundedStatusId = \App\Models\ShopPaymentStatus::where('name', 'refunded')->value('id') 
                            ?? \App\Models\ShopPaymentStatus::where('name', 'canceled')->value('id');
            if ($refundedStatusId) {
                $order->update([
                    'payment_status_id' => $refundedStatusId,
                ]);
            }
            
            $transaction = \App\Models\ShopPaymentTransaction::where('order_id', $order->id)->where('status', 'paid')->first();
            if ($transaction) {
               $transaction->update(['status' => 'refunded']);
            }
        }

        return response()->json($response);
    }

    /**
     * Handles bank transfer payment method.
     */
    protected function handleBankTransfer(ShopOrder $order, ShopPaymentMethod $paymentMethod): \Illuminate\Http\JsonResponse
    {
        $pendingStatusId = ShopPaymentStatus::where('name', 'pending')->value('id');
        if (! $pendingStatusId) {
            Log::error('ShopPaymentStatus \'pending\' not found.');

            return response()->json(['success' => false, 'message' => 'System configuration error: pending payment status not found'], 500);
        }

        $order->update([
            'payment_method_id' => $paymentMethod->id,
            'payment_status_id' => $pendingStatusId,
        ]);

        ShopPaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => $order->total_amount,
            'transaction_id' => null,
            'status' => 'pending',
            'response_data' => ['message' => 'Bank transfer, pending manual confirmation or user action'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment method set to bank transfer. Please check your order details for bank information.',
            'payment_method' => 'transfer',
        ]);
    }

    /**
     * Получает настройки платежного метода для инициализации TbankPaymentService.
     * Это необходимо, так как webhook может прийти без paymentMethod_id в явном виде.
     * Мы должны получить настройки по PaymentId или OrderId из webhookData.
     */
    protected function getPaymentMethodSettings(array $webhookData): array
    {
        // Попытка найти order_id или payment_id из webhookData
        $orderId = $webhookData['OrderId'] ?? null;
        $paymentId = $webhookData['PaymentId'] ?? null;

        if ($paymentId) {
            $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)->first();
            if ($transaction && $transaction->paymentMethod) {
                return $this->normalizePaymentSettings($transaction->paymentMethod->settings);
            }
        }

        if ($orderId) {
            $order = ShopOrder::find($orderId);
            if ($order && $order->paymentMethod) {
                return $this->normalizePaymentSettings($order->paymentMethod->settings);
            }
        }

        // В случае, если не удалось найти настройки по OrderId/PaymentId,
        // можно попробовать получить настройки по типу payment_method 'tbank_dolyame'
        // или использовать настройки по умолчанию, если они есть.
        Log::warning('T-Bank Webhook: Could not find payment method settings from webhook data. Using default/first tbank_dolyame settings.');
        $tbankMethod = ShopPaymentMethod::where('type', 'tbank_dolyame')->first();
        if ($tbankMethod) {
            return $this->normalizePaymentSettings($tbankMethod->settings);
        }

        return []; // Возвращаем пустой массив, если настройки не найдены
    }

    protected function normalizePaymentSettings($settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }
        if (is_string($settings) && $settings !== '') {
            $decoded = json_decode($settings, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
    /**
     * Генерирует уникальный номер заказа.
     *
     * Формат: SS-YYYYMMDD-XXXXXX
     * Где XXXXXX — случайное число с ведущими нулями. При коллизии генерируется повторно.
     */
    protected function generateOrderNumber(): string
    {
        $datePart = date('Ymd');
        $prefix = 'SS-'.$datePart.'-';
        $attempts = 0;
        do {
            $randomPart = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $orderNumber = $prefix.$randomPart;
            $exists = \App\Models\ShopOrder::where('order_number', $orderNumber)->exists();
            $attempts++;
        } while ($exists && $attempts < 5);
        if ($exists) {
            $orderNumber = $prefix.uniqid();
        }

        return $orderNumber;
    }

    /**
     * Webhook для Dolyame Partner API (минимальная заглушка для тестов).
     * Логирует событие и возвращает 200 OK.
     */
    public function dolyameWebhook(Request $request)
    {
        Log::info('Dolyame Webhook received', ['payload' => $request->all()]);

        return response('OK', 200);
    }

    public function yandexPayWebhook(Request $request)
    {
        Log::info('Yandex Pay Webhook received', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        $event = $request->input('event');
        $object = $request->input('object');
        
        // Извлекаем metadata. Она может быть во вложенном объекте или в корне.
        $metadataRaw = $request->input('metadata') ?? $object['metadata'] ?? null;
        $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : $metadataRaw;

        // Обработка тестового вебхука для диагностики
        if (isset($metadata['is_test']) && $metadata['is_test'] && isset($metadata['test_id'])) {
            $testId = $metadata['test_id'];
            Cache::put("yandex_pay_test_webhook:{$testId}", $request->all(), 600);
            Log::info("Yandex Pay Test Webhook recorded for test_id: {$testId}");
            return response()->json(['status' => 'ok']);
        }

        if ($event === 'payment.succeeded' && isset($object['status']) && $object['status'] === 'succeeded') {
            $yandexPaymentId = $object['id'] ?? null;
            if (! $yandexPaymentId) {
                Log::warning('Yandex Pay Webhook: payment.succeeded event without payment ID.');

                return response()->json(['status' => 'error', 'message' => 'No payment ID'], 400);
            }

            $orderId = $metadata['order_id'] ?? null;
            $order = null;
            
            if ($orderId) {
                $order = ShopOrder::find($orderId);
            }
            
            if (!$order) {
                $order = ShopOrder::where('yandex_pay_order_id', $yandexPaymentId)->first();
            }

            if ($order) {
                if ($order->payed) {
                    Log::info('Yandex Pay Webhook: Order '.$order->id.' is already marked as paid. Ignoring.');

                    return response()->json(['status' => 'ok']);
                }

                $paidStatus = ShopPaymentStatus::where('name', 'paid')->first();
                if ($paidStatus) {
                    $order->update([
                        'payment_status_id' => $paidStatus->id,
                        'payed' => true,
                    ]);

                    ShopPaymentTransaction::create([
                        'order_id' => $order->id,
                        'payment_method_id' => $order->payment_method_id,
                        'amount' => $object['amount']['value'] ?? $order->total_amount,
                        'transaction_id' => $yandexPaymentId,
                        'status' => 'paid',
                        'response_data' => $request->all(),
                    ]);

                    Log::info('Yandex Pay Webhook: Order '.$order->id.' payment succeeded and status updated.');
                } else {
                    Log::error('Yandex Pay Webhook: "paid" payment status not found in database.');
                }
            } else {
                Log::warning('Yandex Pay Webhook: Order not found for yandex_pay_order_id: '.$yandexPaymentId.' or metadata order_id: '.($orderId ?? 'null'));
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
