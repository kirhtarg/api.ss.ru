<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use App\Models\ShopOrder;
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

            // Use merchant_name from settings or fallback to payment method name
            $merchantName = $settings['merchant_name'] ?? $paymentMethod->name ?? 'Skate & Snow';
            
            // Build paymentData
            $paymentData = [
                'env' => $env,
                'version' => 2,
                'countryCode' => 'RU',
                'currencyCode' => $currency,
                'merchant' => [
                    'id' => $settings['merchant_id'],
                    'name' => $merchantName
                ],
                'totalAmount' => [
                    'amount' => (string)round($amount, 2),
                    'currency' => $currency,
                ],
                'availablePaymentMethods' => $availablePaymentMethods,
            ];
            
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
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            
            // Проверяем подпись webhook
            if (!$this->verifyWebhookSignature($request)) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $event = $data['event'] ?? null;
            $object = $data['object'] ?? null;

            if (!$event || !$object) {
                return response()->json(['error' => 'Invalid webhook data'], 400);
            }


            // Обрабатываем событие
            switch ($event) {
                case 'payment.succeeded':
                    $this->handlePaymentSucceeded($object);
                    break;
                case 'payment.canceled':
                    $this->handlePaymentCanceled($object);
                    break;
                case 'payment.waiting_for_capture':
                    $this->handlePaymentWaitingForCapture($object);
                    break;
                default:
            }

            return response()->json(['status' => 'ok']);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Internal server error'], 500);
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
    private function handlePaymentSucceeded(array $payment): void
    {
        $orderId = $payment['metadata']['order_id'] ?? null;
        
        if ($orderId) {
            $order = ShopOrder::find($orderId);
            if ($order) {
                $order->update([
                    'payment_status' => 'paid',
                    'paid_at' => now()
                ]);
                
            }
        }
    }

    /**
     * Обработка отмененного платежа
     */
    private function handlePaymentCanceled(array $payment): void
    {
        $orderId = $payment['metadata']['order_id'] ?? null;
        
        if ($orderId) {
            $order = ShopOrder::find($orderId);
            if ($order) {
                $order->update([
                    'payment_status' => 'cancelled'
                ]);
                
            }
        }
    }

    /**
     * Обработка платежа, ожидающего подтверждения
     */
    private function handlePaymentWaitingForCapture(array $payment): void
    {
        $orderId = $payment['metadata']['order_id'] ?? null;
        
        if ($orderId) {
            $order = ShopOrder::find($orderId);
            if ($order) {
                $order->update([
                    'payment_status' => 'waiting_capture'
                ]);
                
            }
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
     * Маппинг статусов платежей
     */
    private function mapPaymentStatus(string $yandexStatus): string
    {
        $statusMap = [
            'pending' => 'pending',
            'waiting_for_capture' => 'waiting_capture',
            'succeeded' => 'paid',
            'canceled' => 'cancelled'
        ];

        return $statusMap[$yandexStatus] ?? 'unknown';
    }
}
