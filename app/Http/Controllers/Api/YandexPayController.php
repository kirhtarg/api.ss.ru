<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
            'payment_method_id' => 'required|integer|exists:shop_payment_methods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);
            $settings = $paymentMethod->getApiSettings();

            if (! $this->validateSettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки платежного метода',
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $orderData = [
                'orderId' => $request->order_number ?? 'ORDER-'.$request->order_id,
                'currencyCode' => $request->currency,
                'amount' => [
                    'value' => number_format($request->amount, 2, '.', ''),
                    'currency' => $request->currency,
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'ORDER-'.$request->order_id,
                            'description' => $request->description,
                            'quantity' => [
                                'count' => '1.0',
                                'available' => '1.0',
                            ],
                            'amount' => [
                                'value' => number_format($request->amount, 2, '.', ''),
                                'currency' => $request->currency,
                            ],
                            'total' => number_format($request->amount, 2, '.', ''),
                        ],
                    ],
                    'total' => [
                        'amount' => number_format($request->amount, 2, '.', ''),
                    ],
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $request->return_url,
                ],
                'metadata' => json_encode([
                    'order_id' => $request->order_id,
                    'payment_method_id' => $request->payment_method_id,
                ]),
            ];

            // Добавляем настройки Сплит если это Яндекс Сплит
            if ($paymentMethod->type === 'yandex_split') {
                $splitSettings = json_decode($settings['split_settings'] ?? '{}', true);
                if (! empty($splitSettings['enabled'])) {
                    $orderData['split'] = [
                        'enabled' => true,
                        'installments_count' => $splitSettings['installments_count'] ?? 4,
                        'first_payment_percent' => $splitSettings['first_payment_percent'] ?? 25,
                    ];
                }
            }

            $response = Http::withOptions([
                'verify' => false, // Отключаем проверку SSL для локальной разработки
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Api-Key '.$settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ])->post($apiUrl.'/orders', $orderData);

            if ($response->successful()) {
                $data = $response->json();

                // Сохраняем ID заказа в Яндекс Пэй
                $order = ShopOrder::where('id', $request->order_id)->first();
                if ($order) {
                    $order->update([
                        'yandex_pay_order_id' => $data['id'],
                        'payment_status_id' => 1, // pending
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
                        'status' => $data['status'],
                    ],
                ]);
            } else {

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка создания заказа в Яндекс Пэй',
                    'details' => $response->json(),
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
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

            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден',
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Api-Key '.$settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ])->get($apiUrl.'/orders/'.$orderId);

            if ($response->successful()) {
                $data = $response->json();

                $order = ShopOrder::where('yandex_pay_order_id', $orderId)->first();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'status' => $data['status'],
                        'amount' => $data['amount'],
                        'created_at' => $data['created_at'],
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения статуса заказа',
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
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

            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден',
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Api-Key '.$settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ])->post($apiUrl.'/orders/'.$orderId.'/cancel');

            if ($response->successful()) {
                $data = $response->json();

                // Обновляем статус заказа в базе данных
                $order = ShopOrder::where('yandex_pay_order_id', $orderId)->first();
                if ($order) {
                    $order->update([
                        'payment_status_id' => 3, // cancelled
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_id' => $data['id'],
                        'status' => $data['status'],
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отмены заказа',
                ], 400);
            }

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
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
            'currency' => 'required|string|in:RUB,USD,EUR',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден',
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;

            $refundData = [
                'amount' => [
                    'value' => number_format($request->amount, 2, '.', ''),
                    'currency' => $request->currency,
                ],
            ];

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Api-Key '.$settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0',
            ])->post($apiUrl.'/orders/'.$orderId.'/refund', $refundData);

            if ($response->successful()) {
                $data = $response->json();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'refund_id' => $data['id'],
                        'status' => $data['status'],
                        'amount' => $data['amount'],
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка возврата платежа',
                ], 400);
            }

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
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
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $data = $validator->validated();

            // Получаем платежный метод для определения режима
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (! $paymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found',
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            if (! $this->validateSettings($settings)) {
                return response()->json([
                    'error' => 'Invalid payment method settings',
                ], 400);
            }

            // Проверка, что merchant_id из запроса соответствует настройкам
            if ($data['merchant_id'] !== $settings['merchant_id']) {
                return response()->json([
                    'error' => 'Invalid merchant_id',
                ], 400);
            }

            // Генерация order_id (если не передан)
            if (! isset($data['order_id']) || empty($data['order_id'])) {
                $data['order_id'] = 'ORDER_'.time().'_'.rand(1000, 9999);
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
                    'id' => $data['merchant_id'],
                ],
                'countryCode' => 'RU',
                'totalAmount' => (int) round($data['total_amount'] * 100), // в копейках
                'orderId' => $data['order_id'],
                'availablePaymentMethods' => ['SPLIT', 'CARD'],
                // Примечание: onPayButtonClick передается на клиенте, не на сервере
            ];

            return response()->json($sessionData);
        } catch (\Exception $e) {
            Log::error('YandexPay createSession failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Не удалось создать сессию',
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

            if (! $paymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found',
                ], 404);
            }

            // Поиск заказа в базе по order_id
            $order = ShopOrder::where('yandex_pay_order_id', $orderId)
                ->orWhere('id', $orderId)
                ->first();

            if (! $order) {
                return response()->json([
                    'order_id' => $orderId,
                    'status' => 'not_found',
                ]);
            }

            return response()->json([
                'order_id' => $orderId,
                'status_id' => $order->payment_status_id ?? 1,
            ]);
        } catch (\Exception $e) {
            Log::error('YandexPay checkPaymentStatus failed', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'error' => 'Internal server error',
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
            'available_payment_methods.*' => 'in:CARD,SPLIT',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::where('type', 'yandex_pay')
                ->orWhere('type', 'yandex_split')
                ->where('is_active', true)
                ->first();

            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платежный метод не найден',
                ], 404);
            }

            $settings = $paymentMethod->getApiSettings();
            if (! $this->validateSettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки платежного метода',
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;
            $orderId = 'sdk-order-'.time();
            $currency = $request->currency ?? $settings['currency'] ?? 'RUB';
            $availablePaymentMethods = $request->available_payment_methods ?? ['CARD', 'SPLIT'];

            $orderData = [
                'amount' => number_format($request->amount, 2, '.', ''),
                'currency' => $currency,
                'order_id' => $orderId,
                'available_payment_methods' => $availablePaymentMethods,
            ];

            $payload = [
                'merchant_id' => $settings['merchant_id'],
                'order' => $orderData,
                'signature' => $this->generateSignature($orderData, $settings['secret_key']),
            ];

            return response()->json($payload);

        } catch (\Exception $e) {
            Log::error('YandexPay getPaymentSessionData failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить данные сессии',
            ], 500);
        }
    }

    /**
     * Валидация настроек платежного метода
     */
    private function validateSettings(array $settings): bool
    {
        return ! empty($settings['merchant_id']) && ! empty($settings['secret_key']);
    }

    /**
     * Генерация подписи для Ultimate Widget
     */
    private function generateSignature(array $orderData, string $secretKey): string
    {
        $stringToSign = $orderData['currency'].$orderData['amount'].$orderData['order_id'];

        return base64_encode(hash_hmac('sha256', $stringToSign, $secretKey, true));
    }

    /**
     * Создание тестового заказа для диагностики в Яндекс Пэй
     */
    public function createTestOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|integer|exists:shop_payment_methods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);
            $settings = $paymentMethod->getApiSettings();

            if (! $this->validateSettings($settings)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверные настройки платежного метода',
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'test' ? $this->testApiUrl : $this->apiUrl;
            $testId = (string) Str::uuid();
            $orderNumber = 'TEST-'.date('Ymd').'-'.strtoupper(Str::random(6));
            $currency = $settings['currency'] ?? 'RUB';
            $amountString = number_format($request->amount, 2, '.', '');

            $orderData = [
                'orderId' => $orderNumber,
                'currencyCode' => $currency,
                'amount' => [
                    'value' => $amountString,
                    'currency' => $currency,
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'TEST-ITEM-1',
                            'description' => 'Тестовый товар (Диагностика)',
                            'quantity' => [
                                'count' => '1.0',
                                'available' => '1.0',
                            ],
                            'amount' => [
                                'value' => $amountString,
                                'currency' => $currency,
                            ],
                            'total' => $amountString,
                        ],
                    ],
                    'total' => ['amount' => $amountString],
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => config('app.frontend_url', 'http://localhost:3000').'/checkout?payment=test_return',
                ],
                'redirectUrls' => [
                    'onSuccess' => config('app.frontend_url', 'http://localhost:3000').'/checkout?payment=test_success',
                    'onError' => config('app.frontend_url', 'http://localhost:3000').'/checkout?payment=test_error',
                ],
                'metadata' => json_encode([
                    'is_test' => true,
                    'test_id' => $testId,
                    'payment_method_id' => $request->payment_method_id,
                ]),
            ];

            Log::info('Yandex Pay Test Order Request', [
                'url' => $apiUrl.'/orders',
                'payload' => $orderData,
                'test_id' => $testId,
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
            ])->withHeaders([
                'Authorization' => 'Api-Key '.$settings['secret_key'],
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('test_', true),
            ])->post($apiUrl.'/orders', $orderData);

            $responseData = $response->json();

            return response()->json([
                'success' => true,
                'test_id' => $testId,
                'api_url' => $apiUrl.'/orders',
                'request_payload' => $orderData,
                'server_response' => $responseData,
                'mode' => $settings['mode'] ?? 'test',
                'confirmation_url' => $responseData['confirmation']['confirmation_url'] ?? null,
                'yandex_order_id' => $responseData['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Yandex Pay createTestOrder failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получение статуса тестового вебхука
     */
    public function getTestWebhookStatus($testId): JsonResponse
    {
        $cacheKey = "yandex_pay_test_webhook:{$testId}";
        $webhookData = Cache::get($cacheKey);

        if ($webhookData) {
            return response()->json([
                'success' => true,
                'received' => true,
                'data' => $webhookData,
            ]);
        }

        return response()->json([
            'success' => true,
            'received' => false,
        ]);
    }
}
