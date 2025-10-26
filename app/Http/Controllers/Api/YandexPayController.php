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
