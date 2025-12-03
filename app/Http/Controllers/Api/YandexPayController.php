<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use App\Models\ShopOrder;
use App\Models\ShopPaymentTransaction;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class YandexPayController extends Controller
{
    private $apiUrl = 'https://pay.yandex.ru/api/merchant/v1';
    private $testApiUrl = 'https://sandbox.pay.yandex.ru/api/merchant/v1';

    /**
     * Создание заказа в Яндекс Пэй
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:RUB,USD,EUR',
            'description' => 'required|string|max:255',
            'return_url' => 'required|url',
            'payment_method_id' => 'required|integer|exists:shop_payment_methods,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);
            $settings = $paymentMethod->getApiSettings();

            if (!$this->validateSettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки платежного метода'
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $orderData = [
                'orderId' => $request->order_number ?? 'ORDER-' . $request->order_id,
                'currencyCode' => $request->currency,
                'amount' => [
                    'value' => number_format($request->amount, 2, '.', ''),
                    'currency' => $request->currency
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'ORDER-' . $request->order_id,
                            'description' => $request->description,
                            'quantity' => [
                                'count' => '1.0',
                                'available' => '1.0'
                            ],
                            'amount' => [
                                'value' => number_format($request->amount, 2, '.', ''),
                                'currency' => $request->currency
                            ],
                            'total' => number_format($request->amount, 2, '.', '')
                        ]
                    ],
                    'total' => [
                        'amount' => number_format($request->amount, 2, '.', '')
                    ]
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $request->return_url
                ],
                'metadata' => json_encode([
                    'order_id' => $request->order_id,
                    'payment_method_id' => $request->payment_method_id
                ])
            ];

            // Добавляем настройки Сплит если это Яндекс Сплит
            if ($paymentMethod->type === 'yandex_split') {
                $splitSettings = json_decode($settings['split_settings'] ?? '{}', true);
                if (!empty($splitSettings['enabled'])) {
                    $orderData['split'] = [
                        'enabled' => true,
                        'installments_count' => $splitSettings['installments_count'] ?? 4,
                        'first_payment_percent' => $splitSettings['first_payment_percent'] ?? 25
                    ];
                }
            }

            $response = Http::withOptions([
                'verify' => false, // Отключаем проверку SSL для локальной разработки
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Api-Key ' . $settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0'
            ])->post($apiUrl . '/orders', $orderData);

            if ($response->successful()) {
                $data = $response->json();
                
                // Сохраняем ID заказа в Яндекс Пэй
                $order = ShopOrder::where('id', $request->order_id)->first();
                if ($order) {
                    $order->update([
                        'yandex_pay_order_id' => $data['id'],
                        'payment_status' => 'pending'
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
                        'status' => $data['status']
                    ]
                ]);
            } else {

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания заказа в Яндекс Пэй',
                    'details' => $response->json()
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Получение статуса заказа
     */
    public function getOrderStatus(Request $request, $orderId): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден'
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Api-Key ' . $settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0'
            ])->get($apiUrl . '/orders/' . $orderId);

            if ($response->successful()) {
                $data = $response->json();
                
                // Обновляем статус заказа в базе данных
                $order = ShopOrder::where('yandex_pay_order_id', $orderId)->first();
                if ($order) {
                    $order->update([
                        'payment_status' => $this->mapPaymentStatus($data['status'])
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'status' => $data['status'],
                        'amount' => $data['amount'],
                        'created_at' => $data['created_at']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения статуса заказа'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Отмена заказа
     */
    public function cancelOrder(Request $request, $orderId): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден'
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Api-Key ' . $settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0'
            ])->post($apiUrl . '/orders/' . $orderId . '/cancel');

            if ($response->successful()) {
                $data = $response->json();
                
                // Обновляем статус заказа в базе данных
                $order = ShopOrder::where('yandex_pay_order_id', $orderId)->first();
                if ($order) {
                    $order->update([
                        'payment_status' => 'cancelled'
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'status' => $data['status']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отмены заказа'
                ], 400);
            }

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Возврат платежа
     */
    public function refundOrder(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:RUB,USD,EUR'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден'
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $refundData = [
                'amount' => [
                    'value' => number_format($request->amount, 2, '.', ''),
                    'currency' => $request->currency
                ]
            ];

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Api-Key ' . $settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0'
            ])->post($apiUrl . '/orders/' . $orderId . '/refund', $refundData);

            if ($response->successful()) {
                $data = $response->json();
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'refund_id' => $data['id'],
                        'status' => $data['status'],
                        'amount' => $data['amount']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка возврата платежа'
                ], 400);
            }

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Валидация merchant_id и подготовка данных сессии для SDK
     * Соответствует инструкции по интеграции Яндекс.Пэй Web SDK
     */
    public function createSession(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string|regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'total_amount' => 'required|numeric|min:1',
            'order_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        try {
            $data = $validator->validated();

            // Получаем платежный метод для определения режима
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found'
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            if (!$this->validateSettings($settings)) {
                return response()->json([
                    'error' => 'Invalid payment method settings'
                ], 400);
            }

            // Проверка, что merchant_id из запроса соответствует настройкам
            if ($data['merchant_id'] !== $settings['merchant_id']) {
                return response()->json([
                    'error' => 'Invalid merchant_id'
                ], 400);
            }

            // Генерация order_id (если не передан)
            if (!isset($data['order_id']) || empty($data['order_id'])) {
                $data['order_id'] = 'ORDER_' . time() . '_' . rand(1000, 9999);
            }

            // Определение окружения
            $env = ($settings['mode'] ?? 'test') === 'live' ? 'PRODUCTION' : 'SANDBOX';
            $currency = $settings['currency'] ?? 'RUB';

            // Подготовка данных для SDK (согласно инструкции)
            // totalAmount передается в копейках как число
            $sessionData = [
                'env' => $env,
                'version' => 4,
                'currencyCode' => $currency,
                'merchant' => [
                    'id' => $data['merchant_id']
                ],
                'countryCode' => 'RU',
                'totalAmount' => (int)round($data['total_amount'] * 100), // в копейках
                'orderId' => $data['order_id'],
                'availablePaymentMethods' => ['SPLIT', 'CARD']
                // Примечание: onPayButtonClick передается на клиенте, не на сервере
            ];

            return response()->json($sessionData);
        } catch (\Exception $e) {
            Log::error('YandexPay createSession failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Не удалось создать сессию'
            ], 500);
        }
    }

    /**
     * Проверка статуса платежа (опционально)
     */
    public function checkPaymentStatus(Request $request, $orderId): JsonResponse
    {
        try {
            // Здесь логика проверки статуса через API Яндекс.Пэй
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found'
                ], 404);
            }

            // Поиск заказа в базе по order_id
            $order = ShopOrder::where('yandex_pay_order_id', $orderId)
                ->orWhere('id', $orderId)
                ->first();

            if (!$order) {
                return response()->json([
                    'order_id' => $orderId,
                    'status' => 'not_found'
                ]);
            }

            return response()->json([
                'order_id' => $orderId,
                'status' => $order->payment_status ?? 'pending'
            ]);
        } catch (\Exception $e) {
            Log::error('YandexPay checkPaymentStatus failed', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            return response()->json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Возвращает paymentData для Web SDK (Ultimate widget)
     * Использует настройки активного платежного метода (merchant_id, режим, валюта)
     */
    public function getPaymentSessionData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|in:RUB,USD,EUR',
            'available_payment_methods' => 'nullable|array',
            'available_payment_methods.*' => 'in:CARD,SPLIT'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден'
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            if (!$this->validateSettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки платежного метода'
                ], 400);
            }

            $env = ($settings['mode'] ?? 'test') === 'live' ? 'PRODUCTION' : 'SANDBOX';
            $currency = $request->input('currency', $settings['currency'] ?? 'RUB');
            $amount = (float) $request->input('amount');
            $availablePaymentMethods = $request->input('available_payment_methods', ['CARD', 'SPLIT']);
            $orderId = $request->input('order_id');
            $forInfoWidgets = $request->input('for_info_widgets', false);
            $useCreatePayment = $request->input('use_create_payment', false); // Для createPayment нужен totalAmount как строка

            // Use merchant_name from settings or fallback to payment method name
            $merchantName = $settings['merchant_name'] ?? $paymentMethod->name ?? 'Skate & Snow';
            
            // Получаем main_site из настроек сайта
            $mainSite = Setting::where('key', 'main_site')->value('value');
            // Если main_site не найден, используем fallback
            $merchantUrl = $mainSite ?: config('app.frontend_url') ?: $request->getSchemeAndHttpHost();
            
            // Build paymentData
            $paymentData = [
                'env' => $env,
                'version' => 2,
                'countryCode' => 'RU',
                'currencyCode' => $currency,
                'merchant' => [
                    'id' => $settings['merchant_id'],
                    'name' => $merchantName,
                    'url' => $merchantUrl // Используем main_site из настроек сайта
                ],
                'availablePaymentMethods' => $availablePaymentMethods,
            ];
            
            // Для createPayment totalAmount должен быть строкой, для createSession - объектом
            if ($useCreatePayment) {
                $paymentData['totalAmount'] = (string)round($amount, 2);
            } else {
                $paymentData['totalAmount'] = [
                    'amount' => (string)round($amount, 2),
                    'currency' => $currency,
            ];
            }
            
            // For info widgets, use only minimal data
            if ($forInfoWidgets) {
                // For info widgets, SDK needs only basic payment info
                // Remove availablePaymentMethods to avoid SDK creating order objects
                unset($paymentData['availablePaymentMethods']);
            } else {
                // For full payment widgets
                // Add items if provided (from checkout cart items)
                $items = $request->input('items');
                if ($items && is_array($items) && count($items) > 0) {
                    $paymentData['items'] = $items;
                    
                    // Add orderId if provided
                    if ($orderId) {
                        $paymentData['orderId'] = $orderId;
                    }
                    
                    // Add cart object to make SDK think this is full payment context
                    $paymentData['cart'] = [
                        'items' => $items
                    ];
                } elseif ($orderId) {
                    // Add orderId and create fake items for product page
                    $paymentData['orderId'] = $orderId;
                    $goodId = $request->input('good_id', 0);
                    $goodName = $request->input('product_name', 'Product');
                    $qty = $request->input('qty', 1);
                    $unitPrice = $qty > 0 ? $amount / $qty : $amount;
                    
                    $paymentData['items'] = [
                        [
                            'productId' => (string)$goodId,
                            'name' => $goodName,
                            'quantity' => [
                                'count' => (string)$qty,
                                'label' => 'шт',
                                'type' => 'FLOAT'
                            ],
                            'unitPrice' => [
                                'amount' => (string)round($unitPrice, 2),
                                'currency' => $currency
                            ],
                            'totalPrice' => [
                                'amount' => (string)round($amount, 2),
                                'currency' => $currency
                            ]
                        ]
                    ];
                    
                    // Add cart to make SDK recognize Payment context
                    $paymentData['cart'] = [
                        'items' => $paymentData['items']
                    ];
                    
                    // Add order object to make SDK recognize Payment context
                    $paymentData['order'] = [
                        'id' => $orderId,
                        'total' => [
                            'amount' => (string)round($amount, 2)
                        ]
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $paymentData,
            ]);
        } catch (\Exception $e) {
            Log::error('YandexPay getPaymentSessionData failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Обработка webhook от Яндекс Пэй
     * 
     * Формат уведомления от Яндекс Пэй может быть разным в зависимости от версии API.
     * Обычно приходит объект с полями: event, object (или data)
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            
            Log::info('YandexPay webhook received', [
                'ip' => $request->ip(),
                'data' => $data
            ]);

            // Проверяем подпись webhook (если требуется)
            // В тестовом режиме можем пропустить проверку
            $isProduction = config('app.env') === 'production';
            if ($isProduction && !$this->verifyWebhookSignature($request)) {
                Log::warning('YandexPay webhook signature verification failed', [
                    'ip' => $request->ip()
                ]);
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Извлекаем данные события
            // Формат может быть: { "event": "...", "object": {...} } или { "event": "...", "data": {...} }
            $event = $data['event'] ?? null;
            $paymentObject = $data['object'] ?? $data['data'] ?? null;

            if (!$event || !$paymentObject) {
                Log::warning('Invalid YandexPay webhook format', [
                    'event' => $event,
                    'has_object' => !empty($paymentObject)
                ]);
                return response()->json(['error' => 'Invalid webhook data'], 400);
            }

            // Извлекаем ID заказа из Яндекс Пэй
            $yandexOrderId = $paymentObject['id'] ?? $paymentObject['orderId'] ?? null;
            $status = $paymentObject['status'] ?? null;

            if (!$yandexOrderId || !$status) {
                Log::warning('Missing payment data in YandexPay webhook', [
                    'yandex_order_id' => $yandexOrderId,
                    'status' => $status
                ]);
                return response()->json(['error' => 'Invalid webhook data'], 400);
            }

            // Находим транзакцию по ID заказа в Яндекс Пэй
            $transaction = ShopPaymentTransaction::where('transaction_id', $yandexOrderId)->first();
            
            if (!$transaction) {
                // Пробуем найти по yandex_pay_order_id в заказе
                $order = ShopOrder::where('yandex_pay_order_id', $yandexOrderId)->first();
                if ($order) {
                    // Находим payment_method_id для Яндекс Пэй
                    $paymentMethodIds = ShopPaymentMethod::whereIn('type', ['yandex_pay', 'yandex_split'])
                        ->pluck('id')
                        ->toArray();
                    
                    if (!empty($paymentMethodIds)) {
                        $transaction = ShopPaymentTransaction::where('order_id', $order->id)
                            ->whereIn('payment_method_id', $paymentMethodIds)
                            ->first();
                    }
                }
            }

            if (!$transaction) {
                Log::warning('Transaction not found for YandexPay payment', [
                    'yandex_order_id' => $yandexOrderId,
                    'event' => $event,
                    'status' => $status
                ]);
                // Возвращаем 200, чтобы Яндекс Пэй не повторяла запрос
                return response()->json(['status' => 'ok', 'message' => 'Transaction not found']);
            }

            // Получаем заказ
            $order = ShopOrder::find($transaction->order_id);
            
            if (!$order) {
                Log::warning('Order not found for transaction', [
                    'transaction_id' => $transaction->id,
                    'order_id' => $transaction->order_id
                ]);
                return response()->json(['status' => 'ok', 'message' => 'Order not found']);
            }

            // Маппим статус из Яндекс Пэй в наш формат
            $newStatus = $this->mapYandexPayStatus($status);
            
            // Обновляем транзакцию
            $transaction->update([
                'status' => $newStatus,
                'response_data' => $data,
                'processed_at' => now()
            ]);

            // Обрабатываем событие в зависимости от типа
            switch ($event) {
                case 'payment.succeeded':
                case 'order.succeeded':
                    // Платеж успешно завершен
                    $this->handlePaymentSucceeded($order, $transaction, $paymentObject);
                    break;
                    
                case 'payment.canceled':
                case 'order.canceled':
                    // Платеж отменен
                    $this->handlePaymentCanceled($order, $transaction, $paymentObject);
                    break;
                    
                case 'payment.waiting_for_capture':
                case 'order.waiting_for_capture':
                    // Платеж ожидает подтверждения (capture)
                    // Обычно это промежуточный статус, не обновляем payed
                    Log::info('YandexPay payment waiting for capture', [
                        'order_id' => $order->id,
                        'yandex_order_id' => $yandexOrderId
                    ]);
                    break;
                    
                default:
                    Log::info('Unhandled YandexPay event', [
                        'event' => $event,
                        'order_id' => $order->id,
                        'yandex_order_id' => $yandexOrderId
                    ]);
            }

            // Всегда возвращаем 200, чтобы Яндекс Пэй знала, что уведомление получено
            return response()->json(['status' => 'ok'], 200);

        } catch (\Exception $e) {
            Log::error('YandexPay webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            // Возвращаем 200, чтобы не провоцировать повторные запросы
            // Но логируем ошибку для отладки
            return response()->json(['status' => 'ok', 'error' => 'Webhook processing error'], 200);
        }
    }

    /**
     * Проверка подписи webhook
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-Yandex-Pay-Signature');
        $body = $request->getContent();

        if (!$signature) {
            return false;
        }

        // Получаем секретный ключ из настроек
        $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
            ->orWhere('type', 'yandex_split')
            ->where('is_active', true)
            ->first();

        if (!$paymentMethod) {
            return false;
        }

        $settings = $paymentMethod->getApiSettings();
        $secret = $settings['secret_key'] ?? '';

        $expectedSignature = base64_encode(hash_hmac('sha256', $body, $secret, true));
        
        return hash_equals($signature, $expectedSignature);
    }

    /**
     * Обработка успешного платежа
     */
    private function handlePaymentSucceeded(ShopOrder $order, ShopPaymentTransaction $transaction, array $paymentObject): void
    {
        try {
            // Обновляем заказ: помечаем как оплаченный и активный
            $updateData = [
                'payed' => true, // Основной статус оплаты заказа
                'is_active' => true,
                'status_id' => 2, // Подтвержден
                'delivery_status_id' => 1, // Создан (остается неизменным)
                'metadata' => json_encode(array_merge(
                    json_decode($order->metadata, true) ?? [],
                    [
                        'payment_status' => 'paid',
                        'payment_method' => 'yandex_pay',
                        'yandex_pay_order_id' => $paymentObject['id'] ?? null,
                        'paid_at' => now()->toIso8601String()
                    ]
                ))
            ];
            
            $order->update($updateData);
            
            // Обновляем остатки товаров
            $this->updateStockQuantities($order);
            
            Log::info('YandexPay order payment successful via webhook', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'yandex_order_id' => $paymentObject['id'] ?? null,
                'amount' => $paymentObject['amount']['value'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling YandexPay payment succeeded: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id
            ]);
            throw $e;
        }
    }

    /**
     * Обработка отмененного платежа
     */
    private function handlePaymentCanceled(ShopOrder $order, ShopPaymentTransaction $transaction, array $paymentObject): void
    {
        try {
            // Обновляем заказ: помечаем как неоплаченный и неактивный
            $updateData = [
                'payed' => false, // Основной статус оплаты заказа
                'is_active' => false,
                'metadata' => json_encode(array_merge(
                    json_decode($order->metadata, true) ?? [],
                    [
                        'payment_status' => 'canceled',
                        'payment_method' => 'yandex_pay',
                        'yandex_pay_order_id' => $paymentObject['id'] ?? null,
                        'canceled_at' => now()->toIso8601String(),
                        'cancellation_reason' => $paymentObject['cancellation_details']['reason'] ?? null
                    ]
                ))
            ];
            
            $order->update($updateData);
            
            Log::info('YandexPay order payment canceled via webhook', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'yandex_order_id' => $paymentObject['id'] ?? null,
                'cancellation_reason' => $paymentObject['cancellation_details']['reason'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling YandexPay payment canceled: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id
            ]);
            throw $e;
        }
    }

    /**
     * Обновление остатков товаров при успешной оплате
     */
    private function updateStockQuantities(ShopOrder $order): void
    {
        try {
            Log::info('Обновление остатков товаров для заказа YandexPay', [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);

            if (!$order->items) {
                Log::warning('У заказа нет товаров для обновления остатков', [
                    'order_id' => $order->id
                ]);
                return;
            }

            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
            
            if (!is_array($items)) {
                Log::warning('Товары заказа не являются массивом', [
                    'order_id' => $order->id,
                    'items' => $order->items
                ]);
                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $quantity = $item['quantity'] ?? 0;
                $variationId = $item['variation_id'] ?? null;

                if (!$goodId || $quantity <= 0) {
                    continue;
                }

                // Обновляем остаток основного товара
                $this->updateGoodStock($goodId, $quantity, $order->id);

                // Если есть вариация, обновляем её остаток
                if ($variationId) {
                    $this->updateVariationStock($variationId, $quantity, $order->id);
                }
            }

            Log::info('Остатки товаров успешно обновлены для заказа YandexPay', [
                'order_id' => $order->id
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатков товаров YandexPay: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Обновление остатка основного товара
     */
    private function updateGoodStock(int $goodId, int $quantity, int $orderId): void
    {
        try {
            $good = \App\Models\ShopGood::find($goodId);
            
            if (!$good) {
                return;
            }

            $currentStock = $good->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity);

            $good->update(['stock_quantity' => $newStock]);

            Log::info('Остаток товара обновлен YandexPay', [
                'good_id' => $goodId,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка товара YandexPay: ' . $e->getMessage(), [
                'good_id' => $goodId,
                'order_id' => $orderId
            ]);
        }
    }

    /**
     * Обновление остатка вариации товара
     */
    private function updateVariationStock(int $variationId, int $quantity, int $orderId): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('shop_good_variations') || 
                !\Illuminate\Support\Facades\Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                return;
            }

            $variation = \Illuminate\Support\Facades\DB::table('shop_good_variations')->find($variationId);
            
            if (!$variation) {
                return;
            }

            $currentStock = $variation->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity);

            \Illuminate\Support\Facades\DB::table('shop_good_variations')
                ->where('id', $variationId)
                ->update(['stock_quantity' => $newStock]);

            Log::info('Остаток вариации товара обновлен YandexPay', [
                'variation_id' => $variationId,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка вариации товара YandexPay: ' . $e->getMessage(), [
                'variation_id' => $variationId,
                'order_id' => $orderId
            ]);
        }
    }

    /**
     * Валидация настроек платежного метода
     */
    private function validateSettings(array $settings): bool
    {
        $required = ['merchant_id', 'secret_key', 'mode', 'currency'];
        
        foreach ($required as $field) {
            if (empty($settings[$field])) {
                return false;
            }
        }

        return in_array($settings['mode'], ['test', 'live']) &&
               in_array($settings['currency'], ['RUB', 'USD', 'EUR']);
    }

    /**
     * Маппинг статусов платежей для транзакций
     */
    private function mapPaymentStatus(string $yandexStatus): string
    {
        $statusMap = [
            'pending' => 'pending',
            'waiting_for_capture' => 'pending',
            'succeeded' => 'success',
            'canceled' => 'cancelled'
        ];

        return $statusMap[$yandexStatus] ?? 'pending';
    }

    /**
     * Маппинг статусов Яндекс Пэй в наш формат для транзакций
     */
    private function mapYandexPayStatus(string $yandexStatus): string
    {
        return match($yandexStatus) {
            'succeeded' => 'success',
            'canceled' => 'cancelled',
            'waiting_for_capture' => 'pending',
            default => 'pending'
        };
    }
}
