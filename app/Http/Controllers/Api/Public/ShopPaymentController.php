<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentTransaction;
use App\Models\ShopOrder;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ShopPaymentController extends Controller
{
    /**
     * Получить список активных способов оплаты
     */
    public function index(): JsonResponse
    {
        try {
            $paymentMethods = ShopPaymentMethod::active()
                ->ordered()
                ->get();

            // Add image_url to each payment method
            foreach ($paymentMethods as $method) {
                $method->image_url = $method->image_url;
            }

            return response()->json([
                'success' => true,
                'data' => $paymentMethods
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать платеж
     */
    public function createPayment(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payment_method_id' => 'required|exists:shop_payment_methods,id',
                'amount' => 'required|numeric|min:0.01',
                'order_number' => 'nullable|string',
                'order_data' => 'nullable|array',
                'return_url' => 'required|url'
            ]);

            $paymentMethod = ShopPaymentMethod::findOrFail($validated['payment_method_id']);

            // Проверяем, что способ оплаты активен
            if (!$paymentMethod->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты неактивен'
                ], 400);
            }

            // Создаем заказ в БД перед отправкой в ЮKassa
            $order = $this->createOrderFromPaymentData($validated);
            
            // Создаем транзакцию с ID заказа
            $transaction = ShopPaymentTransaction::create([
                'order_id' => $order->id,
                'payment_method_id' => $paymentMethod->id,
                'status' => 'pending',
                'amount' => $validated['amount'],
                'request_data' => $validated
            ]);

            // Обрабатываем платеж в зависимости от типа
            switch ($paymentMethod->type) {
                case 'yookassa':
                    return $this->createYooKassaPayment($paymentMethod, $transaction, $validated);
                case 'yandex_pay':
                case 'yandex_split':
                    return $this->createYandexPayPayment($paymentMethod, $transaction, $validated);
                case 'test_bank':
                    return $this->createTestBankPayment($paymentMethod, $transaction, $validated);
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Неподдерживаемый тип платежа'
                    ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка создания платежа: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания платежа',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать платеж через Ю-Касса
     */
    private function createYooKassaPayment(ShopPaymentMethod $paymentMethod, ShopPaymentTransaction $transaction, array $data): JsonResponse
    {
        try {
            $settings = $paymentMethod->getApiSettings();
            
            Log::info('YooKassa settings:', $settings);
            
            if (empty($settings['shop_id']) || empty($settings['secret_key'])) {
                Log::error('YooKassa settings missing: shop_id=' . ($settings['shop_id'] ?? 'empty') . ', secret_key=' . (empty($settings['secret_key']) ? 'empty' : 'present'));
                return response()->json([
                    'success' => false,
                    'message' => 'Не настроены параметры Ю-Касса'
                ], 400);
            }

            $apiUrl = $settings['mode'] === 'live' 
                ? 'https://api.yookassa.ru/v3/payments'
                : 'https://api.yookassa.ru/v3/payments';

            // Получаем номер заказа из созданного заказа
            $order = ShopOrder::find($transaction->order_id);
            $orderNumber = $order->order_number;
            
            $paymentData = [
                'amount' => [
                    'value' => number_format($data['amount'], 2, '.', ''),
                    'currency' => $settings['currency'] ?? 'RUB'
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'embedded'
                ],
                'description' => 'Заказ №' . $orderNumber,
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'order_number' => $orderNumber
                ],
                // Указываем доступные способы оплаты
                'payment_method_types' => [
                    'bank_card',
                    'yoo_money',
                    'qiwi',
                    'webmoney',
                    'alfabank',
                    'sberbank',
                    'tinkoff',
                    'b2b_sberbank',
                    'sberpay',
                    'apple_pay',
                    'google_pay',
                    'mir_pay',
                    'tpay',
                    'sbp',
                    'installments',
                    'installments_uzcard',
                    'installments_kaspi',
                    'installments_halva',
                    'installments_otp',
                    'installments_alfa',
                    'installments_sovcombank',
                    'installments_tinkoff',
                    'installments_raiffeisenbank',
                    'installments_rshb',
                    'installments_rosbank',
                    'installments_mkb',
                    'installments_gazprombank',
                    'installments_vtb',
                    'installments_psb',
                    'installments_uralsib',
                    'installments_akbars',
                    'installments_rnkb',
                    'installments_rosselkhozbank',
                    'installments_pochtabank',
                    'installments_sberbank_credit',
                    'installments_sberbank_installments'
                ]
            ];

            // Добавляем дополнительные настройки если есть
            if (!empty($settings['additional_settings'])) {
                $additionalSettings = json_decode($settings['additional_settings'], true);
                if (is_array($additionalSettings)) {
                    $paymentData = array_merge($paymentData, $additionalSettings);
                }
            }

            Log::info('YooKassa payment data:', $paymentData);
            
            $response = Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                ->withHeaders([
                    'Idempotence-Key' => uniqid('yk_', true),
                    'Content-Type' => 'application/json'
                ])
                ->withOptions([
                    'verify' => false // Отключаем проверку SSL для локальной разработки
                ])
                ->post($apiUrl, $paymentData);
                
            Log::info('YooKassa response status: ' . $response->status());
            Log::info('YooKassa response body: ' . $response->body());

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Обновляем транзакцию
                $transaction->update([
                    'transaction_id' => $responseData['id'],
                    'response_data' => $responseData,
                    'status' => 'pending'
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'confirmation_token' => $responseData['confirmation']['confirmation_token'],
                        'transaction_id' => $transaction->id,
                        'yookassa_payment_id' => $responseData['id'],
                        'payment_id' => $responseData['id']
                    ]
                ]);
            } else {
                $errorData = $response->json();
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => $errorData['description'] ?? 'Ошибка создания платежа в Ю-Касса'
                ]);

                // Удаляем заказ, так как платеж не удался
                $order = ShopOrder::find($transaction->order_id);
                if ($order) {
                    $order->delete();
                    Log::info('Order deleted due to payment failure', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'error' => $errorData['description'] ?? 'Unknown error'
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorData['description'] ?? 'Ошибка создания платежа в Ю-Касса'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка создания платежа Ю-Касса: ' . $e->getMessage());
            $transaction->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            // Удаляем заказ, так как произошла ошибка
            $order = ShopOrder::find($transaction->order_id);
            if ($order) {
                $order->delete();
                Log::info('Order deleted due to payment error', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания платежа в Ю-Касса'
            ], 500);
        }
    }

    /**
     * Создать платеж через Яндекс Пэй
     */
    private function createYandexPayPayment(ShopPaymentMethod $paymentMethod, ShopPaymentTransaction $transaction, array $data): JsonResponse
    {
        try {
            $settings = $paymentMethod->getApiSettings();
            
            if (empty($settings['merchant_id']) || empty($settings['secret_key'])) {
                Log::error('YandexPay: Не настроены параметры', [
                    'merchant_id' => empty($settings['merchant_id']) ? 'missing' : 'present',
                    'secret_key' => empty($settings['secret_key']) ? 'missing' : 'present'
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Не настроены параметры Яндекс Пэй'
                ], 400);
            }

            $mode = $settings['mode'] ?? 'test';

            // Подготавливаем данные для создания заказа в Яндекс Пэй
            $order = ShopOrder::find($transaction->order_id);
            
            if (!$order) {
                Log::error('YandexPay: Заказ не найден', [
                    'transaction_id' => $transaction->id,
                    'order_id' => $transaction->order_id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Заказ не найден'
                ], 400);
            }

            $yandexPayData = [
                'orderId' => $order->order_number, // Уникальный идентификатор заказа
                'currencyCode' => $settings['currency'] ?? 'RUB',
                'amount' => [
                    'value' => number_format($data['amount'], 2, '.', ''),
                    'currency' => $settings['currency'] ?? 'RUB'
                ],
                'cart' => [
                    'items' => [
                        [
                            'productId' => 'ORDER-' . $order->id,
                            'description' => 'Заказ №' . $order->order_number,
                            'quantity' => [
                                'count' => '1.0',
                                'available' => '1.0'
                            ],
                            'amount' => [
                                'value' => number_format($data['amount'], 2, '.', ''),
                                'currency' => $settings['currency'] ?? 'RUB'
                            ],
                            'total' => number_format($data['amount'], 2, '.', '')
                        ]
                    ],
                    'total' => [
                        'amount' => number_format($data['amount'], 2, '.', '')
                    ]
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $data['return_url']
                ],
                'metadata' => json_encode([
                    'order_id' => $transaction->order_id,
                    'payment_method_id' => $paymentMethod->id
                ])
            ];

            // Добавляем настройки Сплит если это Яндекс Сплит
            if ($paymentMethod->type === 'yandex_split') {
                $splitSettings = json_decode($settings['split_settings'] ?? '{}', true);
                if (!empty($splitSettings['enabled'])) {
                    $yandexPayData['split'] = [
                        'enabled' => true,
                        'installments_count' => $splitSettings['installments_count'] ?? 4,
                        'first_payment_percent' => $splitSettings['first_payment_percent'] ?? 25
                    ];
                }
            }

            $apiUrl = $this->getYandexPayApiUrl($mode) . '/orders';
            
            // Согласно документации Яндекс Пэй:
            // В sandbox окружении API-ключ равен значению merchant_id
            // В production окружении используется полный API-ключ из secret_key
            $apiKey = ($mode === 'test') ? $settings['merchant_id'] : $settings['secret_key'];

            // Вызываем реальный API Яндекс Пэй
            $response = Http::withOptions([
                'verify' => false, // Отключаем проверку SSL для локальной разработки
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Api-Key ' . $apiKey,
                'Content-Type' => 'application/json',
                'X-Request-Id' => uniqid('req_', true),
                'X-Request-Timeout' => '30000',
                'X-Request-Attempt' => '0'
            ])->post($apiUrl, $yandexPayData);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Определяем URL для оплаты из ответа
                $paymentUrl = null;
                if (isset($responseData['confirmation']['confirmation_url'])) {
                    $paymentUrl = $responseData['confirmation']['confirmation_url'];
                } elseif (isset($responseData['data']['paymentUrl'])) {
                    $paymentUrl = $responseData['data']['paymentUrl'];
                } elseif (isset($responseData['payment_url'])) {
                    $paymentUrl = $responseData['payment_url'];
                } elseif (isset($responseData['data']['confirmation_url'])) {
                    $paymentUrl = $responseData['data']['confirmation_url'];
                }
                
                // Обновляем транзакцию с данными от Яндекс Пэй
                $transaction->update([
                    'status' => 'pending',
                    'transaction_id' => $responseData['id'] ?? null,
                    'external_id' => $paymentUrl,
                    'response_data' => $responseData
                ]);

                if (!$paymentUrl) {
                    Log::warning('YandexPay: URL оплаты не найден в ответе', [
                        'response_structure' => array_keys($responseData)
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'payment_url' => $paymentUrl,
                    'order_id' => $order->id,
                    'transaction_id' => $transaction->id,
                    'status' => 'pending'
                ]);
            } else {
                $errorBody = $response->body();
                $errorJson = $response->json();
                
                Log::error('YandexPay: Ошибка от API', [
                    'status' => $response->status(),
                    'error_body' => $errorBody,
                    'error_json' => $errorJson,
                    'mode' => $mode
                ]);
                
                // Определяем понятное сообщение для пользователя
                $userMessage = 'Ошибка создания заказа в Яндекс Пэй';
                if (isset($errorJson['reasonCode']) && $errorJson['reasonCode'] === 'AUTHENTICATION_ERROR') {
                    if ($mode === 'test') {
                        $reason = $errorJson['reason'] ?? '';
                        if (str_contains($reason, 'production key in sandbox')) {
                            $userMessage = 'Неверный API ключ для sandbox. Используется production ключ в тестовом режиме. Получите тестовый API ключ в личном кабинете Яндекс Пэй (https://sandbox.pay.yandex.ru) → Настройки → API ключи.';
                        } elseif (str_contains($reason, 'Malformed API key')) {
                            $userMessage = 'Неправильный формат API ключа для sandbox. Убедитесь, что вы используете ключ именно из sandbox окружения (https://sandbox.pay.yandex.ru), а не из production.';
                        } else {
                            $userMessage = 'Неверный API ключ для тестового режима. Проверьте: 1) Ключ получен из sandbox кабинета (https://sandbox.pay.yandex.ru), 2) Merchant ID совпадает с ключом, 3) Режим установлен в "test".';
                        }
                    } else {
                        $userMessage = 'Неверный API ключ для боевого режима. Проверьте настройки в личном кабинете Яндекс Пэй (https://pay.yandex.ru).';
                    }
                } elseif (isset($errorJson['reason'])) {
                    $userMessage = $errorJson['reason'];
                } elseif (isset($errorJson['message'])) {
                    $userMessage = $errorJson['message'];
                }
                
                // Обновляем транзакцию с ошибкой
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => $userMessage,
                    'response_data' => $errorJson
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $userMessage,
                    'details' => config('app.debug') ? $errorJson : null
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('YandexPay: Исключение при создании платежа', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transaction->id ?? null
            ]);
            
            // Обновляем транзакцию с ошибкой
            if (isset($transaction)) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания платежа Яндекс Пэй: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Получить URL API Яндекс Пэй в зависимости от режима
     */
    private function getYandexPayApiUrl(string $mode): string
    {
        // Режим может быть 'test' или 'live'
        return ($mode === 'test' || $mode === 'sandbox') 
            ? 'https://sandbox.pay.yandex.ru/api/merchant/v1'
            : 'https://pay.yandex.ru/api/merchant/v1';
    }

    /**
     * Создать платеж через Тест-Банк
     */
    private function createTestBankPayment(ShopPaymentMethod $paymentMethod, ShopPaymentTransaction $transaction, array $data): JsonResponse
    {
        // Логика для тест-банка (уже существует)
        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->id,
                'test_mode' => true
            ]
        ]);
    }

    /**
     * Проверка статуса платежа
     */
    public function checkPaymentStatus(Request $request): JsonResponse
    {
        try {
            $paymentId = $request->input('payment_id');
            $transactionId = $request->input('transaction_id');
            
            if (!$paymentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment ID is required'
                ], 400);
            }
            
            // Находим транзакцию
            $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)
                ->orWhere('id', $transactionId)
                ->first();
                
            if (!$transaction) {
                Log::warning('Transaction not found for payment check', [
                    'payment_id' => $paymentId,
                    'transaction_id' => $transactionId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }
            
            Log::info('Transaction found for payment check', [
                'transaction_id' => $transaction->id,
                'payment_id' => $paymentId,
                'current_status' => $transaction->status
            ]);
            
            // Проверяем статус в ЮKassa
            $paymentMethod = ShopPaymentMethod::find($transaction->payment_method_id);
            if (!$paymentMethod || $paymentMethod->type !== 'yookassa') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment method'
                ], 400);
            }
            
            $settings = $paymentMethod->getApiSettings();
            $apiUrl = "https://api.yookassa.ru/v3/payments/{$paymentId}";
            
            $response = Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                ->withOptions(['verify' => false])
                ->get($apiUrl);
                
            if ($response->successful()) {
                $paymentData = $response->json();
                $status = $this->mapYooKassaStatus($paymentData['status']);
                
                // Обновляем статус транзакции
                $transaction->update([
                    'status' => $status,
                    'response_data' => $paymentData,
                    'processed_at' => now()
                ]);
                
                return response()->json([
                    'success' => true,
                    'status' => $status,
                    'paid' => $paymentData['paid'] ?? false
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to check payment status'
                ], 400);
            }
            
        } catch (\Exception $e) {
            Log::error('Payment status check error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error checking payment status'
            ], 500);
        }
    }
    
    /**
     * Обновление статуса заказа после успешной оплаты
     */
    public function updateOrderStatus(Request $request): JsonResponse
    {
        try {
            $paymentId = $request->input('payment_id');
            $status = $request->input('status');
            
            if (!$paymentId || !$status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment ID and status are required'
                ], 400);
            }
            
            // Находим транзакцию
            $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)->first();
            
            if (!$transaction) {
                Log::warning('Transaction not found for order status update', [
                    'payment_id' => $paymentId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }
            
            Log::info('Transaction found for order status update', [
                'transaction_id' => $transaction->id,
                'payment_id' => $paymentId,
                'order_id' => $transaction->order_id,
                'status' => $status
            ]);
            
            // Обновляем статус транзакции
            $transaction->update([
                'status' => $status,
                'processed_at' => now()
            ]);
            
               // Если платеж успешен, обновляем статус заказа
               if ($status === 'success') {
                   $order = ShopOrder::find($transaction->order_id);
                   if ($order) {
                       $order->update([
                           'status_id' => 2, // Подтвержден
                           'payment_status_id' => 2, // Оплачен
                           'delivery_status_id' => 1, // Создан
                           'metadata' => json_encode(array_merge(
                               json_decode($order->metadata, true) ?? [],
                               ['payment_status' => 'paid', 'payment_method' => 'yookassa']
                           ))
                       ]);

                       // Обновляем остатки товаров
                       $this->updateStockQuantities($order);

                       Log::info('Order payment successful', [
                           'order_id' => $order->id,
                           'order_number' => $order->order_number,
                           'transaction_id' => $transaction->id
                       ]);
                   }
               }
            
            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Order status update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating order status'
            ], 500);
        }
    }

    /**
     * Обработка webhook от Ю-Касса
     */
    public function yookassaWebhook(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            
            Log::info('YooKassa webhook received', $data);

            // Проверяем подпись (в реальном проекте нужно добавить проверку)
            $paymentId = $data['object']['id'] ?? null;
            $status = $data['object']['status'] ?? null;

            if (!$paymentId || !$status) {
                return response()->json(['success' => false, 'message' => 'Invalid webhook data'], 400);
            }

            // Находим транзакцию
            $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)->first();
            
            if (!$transaction) {
                Log::warning('Transaction not found for YooKassa payment', ['payment_id' => $paymentId]);
                return response()->json(['success' => false, 'message' => 'Transaction not found'], 404);
            }

            // Обновляем статус транзакции
            $newStatus = $this->mapYooKassaStatus($status);
            $transaction->update([
                'status' => $newStatus,
                'response_data' => $data,
                'processed_at' => now()
            ]);

            // Если платеж успешен, обновляем статус заказа
            if ($newStatus === 'success') {
                $order = ShopOrder::find($transaction->order_id);
                if ($order) {
                    // Подготавливаем данные для обновления
                    $updateData = [
                        'status_id' => 2, // Подтвержден
                        'payment_status_id' => 2, // Оплачен
                        'delivery_status_id' => 1, // Создан (остается неизменным)
                        'metadata' => json_encode(array_merge(
                            json_decode($order->metadata, true) ?? [],
                            ['payment_status' => 'paid']
                        ))
                    ];
                    
                    $order->update($updateData);
                    
                    Log::info('Order payment successful', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id
                    ]);
                }
            } elseif ($newStatus === 'cancelled') {
                // Если платеж отменен, удаляем заказ
                $order = ShopOrder::find($transaction->order_id);
                if ($order) {
                    $order->delete();
                    Log::info('Order deleted due to payment cancellation', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id
                    ]);
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('YooKassa webhook error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Webhook processing error'], 500);
        }
    }

    /**
     * Маппинг статусов Ю-Касса
     */
    private function mapYooKassaStatus(string $yookassaStatus): string
    {
        return match($yookassaStatus) {
            'succeeded' => 'success',
            'canceled' => 'cancelled',
            'waiting_for_capture' => 'pending',
            default => 'pending'
        };
    }

    /**
     * Создание заказа из данных платежа
     */
    private function createOrderFromPaymentData(array $data): ShopOrder
    {
        $orderData = $data['order_data'] ?? [];
        
        // Подготавливаем данные товаров для JSON поля
        $items = $orderData['items'] ?? [];
        $totalQuantity = array_sum(array_column($items, 'quantity'));
        
        // Получаем способ оплаты для определения названия
        $paymentMethod = ShopPaymentMethod::find($data['payment_method_id']);
        $paymentMethodName = $paymentMethod ? $paymentMethod->name : 'Онлайн оплата';

        // Подготавливаем данные для создания заказа
        $orderDataForCreate = [
            'order_number' => 'TEMP-' . time(), // Временный номер
            'user_id' => $orderData['customer_id'] ?? null,
            'status_id' => 1, // Статус "Обрабатывается"
            'customer_name' => $orderData['customer_name'] ?? 'Покупатель',
            'customer_email' => $orderData['customer_email'] ?? null,
            'customer_phone' => $orderData['customer_phone'] ?? null,
            'items' => json_encode($items), // JSON поле с товарами
            'subtotal' => $orderData['subtotal'] ?? $data['amount'],
            'discount_amount' => $orderData['total_discount_amount'] ?? 0,
            'total_amount' => $data['amount'],
            'total_quantity' => $totalQuantity,
            'payment_method' => $paymentMethodName,
            'payment_method_id' => $data['payment_method_id'],
            'shipping_method' => $orderData['shipping_method'] ?? 'Самовывоз',
            'shipping_address' => $orderData['shipping_address'] ?? null,
            'notes' => $orderData['notes'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => json_encode([
                'delivery_cost' => $orderData['delivery_cost'] ?? '0.00',
                'sale_discount_amount' => $orderData['sale_discount_amount'] ?? 0,
                'registered_user_discount_amount' => $orderData['registered_user_discount_amount'] ?? 0,
                'promo_code_discount_amount' => $orderData['promo_code_discount_amount'] ?? 0,
                'total_discount_amount' => $orderData['total_discount_amount'] ?? 0,
                'promo_code' => $orderData['promo_code'] ?? null,
                'promo_code_id' => $orderData['promo_code_id'] ?? null,
                'use_bonus_points' => $orderData['use_bonus_points'] ?? false,
                'bonus_points_to_use' => $orderData['bonus_points_to_use'] ?? 0,
                'order_bonus_points' => $orderData['order_bonus_points'] ?? 0,
                'telegram_chat_id' => $orderData['telegram_chat_id'] ?? null,
                'payment_status' => 'pending'
            ])
        ];

        // Добавляем поля статусов при создании заказа
        $orderDataForCreate['payment_status_id'] = 1; // Статус "Ожидает оплаты" (изменится на 2 при оплате)
        $orderDataForCreate['delivery_status_id'] = 1; // Статус "Создан" (остается неизменным)
        
        Log::info('Order data for create', $orderDataForCreate);

        // Создаем заказ
        $order = ShopOrder::create($orderDataForCreate);

        // Товары уже сохранены в JSON поле items

        // Генерируем правильный номер заказа на основе ID
        $orderNumber = $this->generateUniqueOrderNumber($order->id);
        $order->update(['order_number' => $orderNumber]);

        Log::info('Order created for payment', [
            'order_id' => $order->id,
            'order_number' => $orderNumber,
            'amount' => $data['amount']
        ]);

        return $order;
    }

    /**
     * Генерация уникального номера заказа на основе ID заказа
     */
    private function generateUniqueOrderNumber(int $orderId): string
    {
        $date = date('Ymd');
        $prefix = 'ORD-' . $date . '-';
        
        // Используем ID заказа для генерации номера
        $orderNumber = $prefix . str_pad($orderId, 4, '0', STR_PAD_LEFT);
        
        return $orderNumber;
    }

    /**
     * Обработка возврата с ЮKassa
     */
    public function handlePaymentReturn(Request $request)
    {
        try {
            $paymentId = $request->query('payment_id');
            $orderNumber = $request->query('order_number');
            
            Log::info('Payment return from YooKassa', [
                'payment_id' => $paymentId,
                'order_number' => $orderNumber,
                'query_params' => $request->query()
            ]);

            if ($paymentId) {
                // Находим транзакцию по ID платежа
                $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)->first();
                
                if ($transaction && $transaction->order_id) {
                    $order = ShopOrder::find($transaction->order_id);
                    
                    if ($order) {
                        // Проверяем статус платежа в ЮKassa
                        $paymentStatus = $this->checkYooKassaPaymentStatus($paymentId);
                        
                        if ($paymentStatus === 'succeeded') {
                            // Платеж успешен - обновляем статус
                            $updateData = [
                                'status_id' => 2, // Подтвержден
                                'payment_status_id' => 2, // Оплачен
                                'delivery_status_id' => 1, // Создан (остается неизменным)
                            ];
                            
                            $order->update($updateData);
                            
                            // Обновляем остатки товаров
                            $this->updateStockQuantities($order);
                            
                            Log::info('Order payment confirmed on return', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'payment_id' => $paymentId
                            ]);
                            
                            // Перенаправляем на страницу возврата с параметрами
                            return redirect()->to('/checkout?payment=return&payment_id=' . $paymentId . '&order_number=' . $order->order_number);
                        } else {
                            // Платеж не успешен - удаляем заказ
                            $order->delete();
                            Log::info('Order deleted due to failed payment on return', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'payment_status' => $paymentStatus
                            ]);
                            
                            return redirect()->to('/checkout?payment=failed&payment_id=' . $paymentId);
                        }
                    }
                }
            }
            
            // Если не удалось найти заказ или платеж
            return redirect()->to('/checkout?payment=error');
            
        } catch (\Exception $e) {
            Log::error('Payment return error: ' . $e->getMessage());
            return redirect()->to('/checkout?payment=error');
        }
    }

    /**
     * Проверка статуса платежа в ЮKassa
     */
    private function checkYooKassaPaymentStatus(string $paymentId): ?string
    {
        try {
            // Получаем настройки ЮKassa
            $paymentMethod = ShopPaymentMethod::where('type', 'yookassa')->first();
            if (!$paymentMethod) {
                return null;
            }
            
            $settings = $paymentMethod->getApiSettings();
            $apiUrl = 'https://api.yookassa.ru/v3/payments/' . $paymentId;
            
            $response = Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                ->withOptions(['verify' => false])
                ->get($apiUrl);
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['status'] ?? null;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Error checking YooKassa payment status: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Обновление остатков товаров при успешной оплате
     */
    private function updateStockQuantities(ShopOrder $order): void
    {
        try {
            Log::info('Обновление остатков товаров для заказа', [
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
                    Log::warning('Пропускаем товар с некорректными данными', [
                        'good_id' => $goodId,
                        'quantity' => $quantity,
                        'variation_id' => $variationId
                    ]);
                    continue;
                }

                // Обновляем остаток основного товара
                $this->updateGoodStock($goodId, $quantity, $order->id);

                // Если есть вариация, обновляем её остаток
                if ($variationId) {
                    $this->updateVariationStock($variationId, $quantity, $order->id);
                }
            }

            Log::info('Остатки товаров успешно обновлены для заказа', [
                'order_id' => $order->id
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатков товаров: ' . $e->getMessage(), [
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
            $good = ShopGood::find($goodId);
            
            if (!$good) {
                Log::warning('Товар не найден для обновления остатка', [
                    'good_id' => $goodId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $currentStock = $good->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity); // Не уходим в минус

            $good->update(['stock_quantity' => $newStock]);

            Log::info('Остаток товара обновлен', [
                'good_id' => $goodId,
                'good_name' => $good->name,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка товара: ' . $e->getMessage(), [
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
            // Проверяем, есть ли таблица вариаций и поле stock_quantity
            if (!Schema::hasTable('shop_good_variations') || !Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                Log::info('Таблица вариаций или поле stock_quantity не найдены, пропускаем обновление вариации', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $variation = DB::table('shop_good_variations')->find($variationId);
            
            if (!$variation) {
                Log::warning('Вариация товара не найдена для обновления остатка', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $currentStock = $variation->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity); // Не уходим в минус

            DB::table('shop_good_variations')
                ->where('id', $variationId)
                ->update(['stock_quantity' => $newStock]);

            Log::info('Остаток вариации товара обновлен', [
                'variation_id' => $variationId,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка вариации товара: ' . $e->getMessage(), [
                'variation_id' => $variationId,
                'order_id' => $orderId
            ]);
        }
    }

}
