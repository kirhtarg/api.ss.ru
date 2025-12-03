<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopGood;
use App\Models\Setting;
use App\Services\NotificationService;
use App\Services\TelegramService;
use App\Mail\OrderInvoiceMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

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
            
            $response = Http::withBasicAuth($settings['shop_id'], $settings['secret_key'])
                ->withHeaders([
                    'Idempotence-Key' => uniqid('yk_', true),
                    'Content-Type' => 'application/json'
                ])
                ->withOptions([
                    'verify' => false // Отключаем проверку SSL для локальной разработки
                ])
                ->post($apiUrl, $paymentData);

            if ($response->successful()) {
                $responseData = $response->json();
                $yookassaPaymentId = $responseData['id'] ?? null;
                
                // Обновляем транзакцию
                $transaction->update([
                    'transaction_id' => $yookassaPaymentId,
                    'response_data' => $responseData,
                    'status' => 'pending'
                ]);

                // Проверяем настройку two_stage_pay
                $twoStagePay = Setting::where('key', 'two_stage_pay')->first();
                $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
                
                // Получаем payment_url из ответа (если есть)
                // YooKassa может возвращать URL в разных местах
                $paymentUrl = null;
                if (isset($responseData['confirmation']['confirmation_url'])) {
                    $paymentUrl = $responseData['confirmation']['confirmation_url'];
                } elseif (isset($responseData['confirmation_url'])) {
                    $paymentUrl = $responseData['confirmation_url'];
                } elseif (isset($responseData['payment_url'])) {
                    $paymentUrl = $responseData['payment_url'];
                } elseif (isset($responseData['data']['confirmation_url'])) {
                    $paymentUrl = $responseData['data']['confirmation_url'];
                }
                
                // Если payment_url не найден, но есть confirmation_token, формируем URL
                if (!$paymentUrl && isset($responseData['confirmation']['confirmation_token'])) {
                    $confirmationType = $responseData['confirmation']['type'] ?? 'redirect';
                    // Формируем URL для оплаты на основе payment ID
                    $paymentUrl = 'https://yoomoney.ru/checkout/payments/v2/contract?orderId=' . $yookassaPaymentId;
                }
                
                if (!$paymentUrl) {
                    Log::warning('YooKassa: URL оплаты не найден в ответе', [
                        'response_structure' => array_keys($responseData),
                        'confirmation_structure' => isset($responseData['confirmation']) ? array_keys($responseData['confirmation']) : null,
                        'has_confirmation_token' => isset($responseData['confirmation']['confirmation_token'])
                    ]);
                }
                
                // Сохраняем ID платежа в ЮKassa и payment_url в заказе
                $updateData = [];
                if ($yookassaPaymentId) {
                    $updateData['yookassa_payment_id'] = $yookassaPaymentId;
                }
                if ($paymentUrl) {
                    $updateData['payment_url'] = $paymentUrl;
                }
                if (!empty($updateData)) {
                    $order->update($updateData);
                }

                // Обновляем заказ из БД, чтобы получить актуальные данные (включая order_number)
                $order->refresh();
                
                // Отправляем уведомления о создании заказа
                $this->sendOrderNotifications($order, $data['order_data'] ?? []);
                
                // Если включена двухэтапная оплата, не возвращаем confirmation_token
                if ($isTwoStagePay) {
                    return response()->json([
                        'success' => true,
                        'two_stage_pay' => true,
                        'message' => 'Заказ создан. Менеджер проверит наличие товаров и после одобрения вы сможете произвести оплату.',
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id,
                        'status' => 'pending'
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'confirmation_token' => $responseData['confirmation']['confirmation_token'],
                        'transaction_id' => $transaction->id,
                        'yookassa_payment_id' => $yookassaPaymentId,
                        'payment_id' => $yookassaPaymentId
                    ]
                ]);
            } else {
                $errorData = $response->json();
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => $errorData['description'] ?? 'Ошибка создания платежа в Ю-Касса'
                ]);

                // Проверяем настройку two_stage_pay
                $twoStagePay = Setting::where('key', 'two_stage_pay')->first();
                $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);

                // Удаляем заказ только если двухэтапная оплата выключена
                // При двухэтапной оплате заказ должен остаться, чтобы менеджер мог его обработать
                $order = ShopOrder::find($transaction->order_id);
                if ($order && !$isTwoStagePay) {
                    $order->delete();
                    Log::info('Order deleted due to payment failure', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'error' => $errorData['description'] ?? 'Unknown error'
                    ]);
                } elseif ($order && $isTwoStagePay) {
                    Log::info('Order kept despite payment failure (two_stage_pay enabled)', [
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

            // Проверяем настройку two_stage_pay
            $twoStagePay = Setting::where('key', 'two_stage_pay')->first();
            $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);

            // Удаляем заказ только если двухэтапная оплата выключена
            // При двухэтапной оплате заказ должен остаться, чтобы менеджер мог его обработать
            $order = ShopOrder::find($transaction->order_id);
            if ($order && !$isTwoStagePay) {
                $order->delete();
                Log::info('Order deleted due to payment error', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage()
                ]);
            } elseif ($order && $isTwoStagePay) {
                Log::info('Order kept despite payment error (two_stage_pay enabled)', [
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
                $yandexOrderId = $responseData['id'] ?? null;
                $transaction->update([
                    'status' => 'pending',
                    'transaction_id' => $yandexOrderId,
                    'external_id' => $paymentUrl,
                    'response_data' => $responseData
                ]);

                // Проверяем настройку two_stage_pay
                $twoStagePay = Setting::where('key', 'two_stage_pay')->first();
                $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
                
                // Сохраняем ID заказа в Яндекс Пэй и payment_url в заказе
                $updateData = [];
                if ($yandexOrderId) {
                    $updateData['yandex_pay_order_id'] = $yandexOrderId;
                }
                if ($paymentUrl) {
                    $updateData['payment_url'] = $paymentUrl;
                }
                if (!empty($updateData)) {
                    $order->update($updateData);
                }

                if (!$paymentUrl) {
                    Log::warning('YandexPay: URL оплаты не найден в ответе', [
                        'response_structure' => array_keys($responseData)
                    ]);
                }

                // Обновляем заказ из БД, чтобы получить актуальные данные (включая order_number)
                $order->refresh();
                
                // Отправляем уведомления о создании заказа
                $this->sendOrderNotifications($order, $data['order_data'] ?? []);
                
                // Если включена двухэтапная оплата, не возвращаем payment_url
                if ($isTwoStagePay) {
                    return response()->json([
                        'success' => true,
                        'two_stage_pay' => true,
                        'message' => 'Заказ создан. Менеджер проверит наличие товаров и после одобрения вы сможете произвести оплату.',
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id,
                        'status' => 'pending'
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
                           'payed' => true, // Основной статус оплаты заказа
                           'is_active' => true,
                           'status_id' => 2, // Подтвержден
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
     * 
     * Формат уведомления от ЮKassa:
     * {
     *   "type": "notification",
     *   "event": "payment.succeeded" | "payment.canceled" | "payment.waiting_for_capture",
     *   "object": { ... объект платежа ... }
     * }
     */
    public function yookassaWebhook(Request $request): JsonResponse
    {
        try {
            // Получаем данные из тела запроса
            $data = $request->all();
            
            Log::info('YooKassa webhook received', [
                'ip' => $request->ip(),
                'data' => $data
            ]);

            // Проверка подлинности по IP-адресу (согласно документации ЮKassa)
            $clientIp = $request->ip();
            $allowedIps = [
                '185.71.76.0/27',
                '185.71.77.0/27',
                '77.75.153.0/25',
                '77.75.156.11',
                '77.75.156.35',
                '77.75.154.128/25',
                '2a02:5180::/32'
            ];
            
            // Проверяем IP только в production режиме (в тестовом режиме можем пропустить)
            $isIpAllowed = $this->isIpInRange($clientIp, $allowedIps);
            if (!$isIpAllowed && config('app.env') === 'production') {
                Log::warning('YooKassa webhook from unauthorized IP', [
                    'ip' => $clientIp,
                    'allowed_ips' => $allowedIps
                ]);
                // В production режиме отклоняем запросы с неразрешенных IP
                // В тестовом режиме пропускаем для удобства разработки
            }

            // Проверяем формат уведомления согласно документации
            $type = $data['type'] ?? null;
            $event = $data['event'] ?? null;
            $paymentObject = $data['object'] ?? null;

            if ($type !== 'notification' || !$event || !$paymentObject) {
                Log::warning('Invalid YooKassa webhook format', [
                    'type' => $type,
                    'event' => $event,
                    'has_object' => !empty($paymentObject)
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid webhook format'], 400);
            }

            // Извлекаем данные из объекта платежа
            $paymentId = $paymentObject['id'] ?? null;
            $status = $paymentObject['status'] ?? null;
            $paid = $paymentObject['paid'] ?? false;

            if (!$paymentId || !$status) {
                Log::warning('Missing payment data in webhook', [
                    'payment_id' => $paymentId,
                    'status' => $status
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid webhook data'], 400);
            }

            // Находим транзакцию по ID платежа из ЮKassa
            $transaction = ShopPaymentTransaction::where('transaction_id', $paymentId)->first();
            
            if (!$transaction) {
                // Пробуем найти заказ по yookassa_payment_id
                $order = ShopOrder::where('yookassa_payment_id', $paymentId)->first();
                if ($order) {
                    // Находим payment_method_id для ЮKassa
                    $paymentMethodIds = ShopPaymentMethod::where('type', 'yookassa')
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
                Log::warning('Transaction not found for YooKassa payment', [
                    'payment_id' => $paymentId,
                    'event' => $event,
                    'status' => $status
                ]);
                // Возвращаем 200, чтобы ЮKassa не повторяла запрос
                return response()->json(['success' => true, 'message' => 'Transaction not found']);
            }

            // Маппим статус из ЮKassa в наш формат
            $newStatus = $this->mapYooKassaStatus($status);
            
            // Обновляем транзакцию
            $transaction->update([
                'status' => $newStatus,
                'response_data' => $data,
                'processed_at' => now()
            ]);

            // Получаем заказ
            $order = ShopOrder::find($transaction->order_id);
            
            if (!$order) {
                Log::warning('Order not found for transaction', [
                    'transaction_id' => $transaction->id,
                    'order_id' => $transaction->order_id
                ]);
                return response()->json(['success' => true, 'message' => 'Order not found']);
            }

            // Обрабатываем событие в зависимости от типа
            switch ($event) {
                case 'payment.succeeded':
                    // Платеж успешно завершен
                    $this->handlePaymentSucceeded($order, $transaction, $paymentObject);
                    break;
                    
                case 'payment.canceled':
                    // Платеж отменен
                    $this->handlePaymentCanceled($order, $transaction, $paymentObject);
                    break;
                    
                case 'payment.waiting_for_capture':
                    // Платеж ожидает подтверждения (capture)
                    // Обычно это промежуточный статус, не обновляем payed
                    Log::info('Payment waiting for capture', [
                        'order_id' => $order->id,
                        'payment_id' => $paymentId
                    ]);
                    break;
                    
                default:
                    Log::info('Unhandled YooKassa event', [
                        'event' => $event,
                        'order_id' => $order->id,
                        'payment_id' => $paymentId
                    ]);
            }

            // Всегда возвращаем 200, чтобы ЮKassa знала, что уведомление получено
            return response()->json(['success' => true], 200);

        } catch (\Exception $e) {
            Log::error('YooKassa webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            // Возвращаем 200, чтобы не провоцировать повторные запросы
            // Но логируем ошибку для отладки
            return response()->json(['success' => false, 'message' => 'Webhook processing error'], 200);
        }
    }

    /**
     * Обработка успешного платежа
     */
    private function handlePaymentSucceeded(ShopOrder $order, ShopPaymentTransaction $transaction, array $paymentObject): void
    {
        try {
            $yookassaPaymentId = $paymentObject['id'] ?? null;
            
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
                        'payment_method' => 'yookassa',
                        'payment_id' => $yookassaPaymentId,
                        'paid_at' => now()->toIso8601String()
                    ]
                ))
            ];
            
            // Сохраняем ID платежа в ЮKassa, если его еще нет
            if ($yookassaPaymentId && !$order->yookassa_payment_id) {
                $updateData['yookassa_payment_id'] = $yookassaPaymentId;
            }
            
            $order->update($updateData);
            
            // Логируем оплату заказа
            $userName = $order->user ? $order->user->name : ($order->customer_name ?? 'Покупатель');
            ShopOrderLog::logOrderPaid($order->id, $userName, 'Оплата через YooKassa', null, $order->order_number);
            
            // Обновляем остатки товаров
            $this->updateStockQuantities($order);
            
            Log::info('Order payment successful via webhook', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'payment_id' => $paymentObject['id'] ?? null,
                'amount' => $paymentObject['amount']['value'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling payment succeeded: ' . $e->getMessage(), [
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
                        'payment_method' => 'yookassa',
                        'payment_id' => $paymentObject['id'] ?? null,
                        'canceled_at' => now()->toIso8601String(),
                        'cancellation_reason' => $paymentObject['cancellation_details']['reason'] ?? null
                    ]
                ))
            ];
            
            $order->update($updateData);
            
            Log::info('Order payment canceled via webhook', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->id,
                'payment_id' => $paymentObject['id'] ?? null,
                'cancellation_reason' => $paymentObject['cancellation_details']['reason'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling payment canceled: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id
            ]);
            throw $e;
        }
    }

    /**
     * Проверка, находится ли IP-адрес в разрешенном диапазоне
     */
    private function isIpInRange(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверка, находится ли IP в диапазоне (CIDR или точный адрес)
     */
    private function ipInRange(string $ip, string $range): bool
    {
        // Точное совпадение
        if ($ip === $range) {
            return true;
        }

        // Проверка CIDR
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);
            
            // Для IPv6
            if (strpos($ip, ':') !== false) {
                return $this->ipv6InRange($ip, $subnet, (int)$mask);
            }
            
            // Для IPv4
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);
            
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    /**
     * Проверка IPv6 адреса в диапазоне
     */
    private function ipv6InRange(string $ip, string $subnet, int $mask): bool
    {
        // Упрощенная проверка для IPv6
        // В реальном проекте можно использовать более точную реализацию
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        
        // Проверяем первые mask бит
        $bytes = (int)($mask / 8);
        $bits = $mask % 8;
        
        for ($i = 0; $i < $bytes; $i++) {
            if ($ipBin[$i] !== $subnetBin[$i]) {
                return false;
            }
        }
        
        if ($bits > 0 && $bytes < 16) {
            $maskByte = 0xFF << (8 - $bits);
            return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
        }
        
        return true;
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
        
        // Получаем ID пользователя из авторизации, если пользователь зарегистрирован
        $authUser = Auth::user();
        $userId = null;
        if ($authUser) {
            $userId = $authUser->id;
        } elseif (isset($orderData['customer_id'])) {
            $userId = $orderData['customer_id'];
        }
        
        // Логируем данные для отладки
        Log::info('createOrderFromPaymentData: входные данные', [
            'has_auth_user' => $authUser !== null,
            'auth_user_id' => $authUser ? $authUser->id : null,
            'order_data_customer_id' => $orderData['customer_id'] ?? null,
            'final_user_id' => $userId,
            'shipping_address' => $orderData['shipping_address'] ?? null,
            'order_data_keys' => array_keys($orderData)
        ]);
        
        // Подготавливаем данные товаров для JSON поля
        $items = $orderData['items'] ?? [];
        $totalQuantity = array_sum(array_column($items, 'quantity'));
        
        // Получаем способ оплаты для определения названия
        $paymentMethod = ShopPaymentMethod::find($data['payment_method_id']);
        $paymentMethodName = $paymentMethod ? $paymentMethod->name : 'Онлайн оплата';

        // Определяем shipping_method и shipping_method_id
        // Если передан shipping_method_id, получаем название метода из БД
        // shipping_method из orderData содержит название тарифа для СДЭК или название метода для остальных
        $shippingMethodId = $orderData['shipping_method_id'] ?? null;
        $shippingMethodName = $orderData['shipping_method'] ?? 'Самовывоз';
        
        // Логируем входящие данные для отладки
        Log::info('Shipping method data from frontend', [
            'shipping_method_id' => $shippingMethodId,
            'shipping_method' => $shippingMethodName,
            'order_data_shipping_method' => $orderData['shipping_method'] ?? 'not set',
            'order_data_keys' => array_keys($orderData)
        ]);
        
        // Если есть shipping_method_id, получаем название метода доставки из БД
        $deliveryMethod = null;
        if ($shippingMethodId) {
            $deliveryMethod = \App\Models\ShopDeliveryMethod::find($shippingMethodId);
            if ($deliveryMethod) {
                Log::info('Delivery method found', [
                    'id' => $deliveryMethod->id,
                    'name' => $deliveryMethod->name,
                    'type' => $deliveryMethod->type
                ]);
            }
        }
        
        // Для СДЭК shipping_method должен содержать название тарифа, а не название метода доставки
        // Проверяем, что если это СДЭК, то shipping_method не равен названию метода доставки
        if ($deliveryMethod && ($deliveryMethod->type === 'cdek' || stripos($deliveryMethod->name, 'сдэк') !== false)) {
            // Если shipping_method равен названию метода доставки, значит тариф не был передан
            if ($shippingMethodName === $deliveryMethod->name) {
                Log::warning('CDEK tariff not provided, using delivery method name', [
                    'shipping_method' => $shippingMethodName,
                    'delivery_method_name' => $deliveryMethod->name
                ]);
                // В этом случае оставляем как есть, но логируем предупреждение
            } else {
                Log::info('CDEK tariff name will be saved', [
                    'tariff_name' => $shippingMethodName,
                    'delivery_method_name' => $deliveryMethod->name
                ]);
            }
        }

        // Проверяем настройку two_stage_pay
        $twoStagePay = Setting::where('key', 'two_stage_pay')->first();
        $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
        
        // Определяем pay_agree в зависимости от настройки two_stage_pay
        // Если двухэтапная оплата включена, то pay_agree = false (требуется одобрение менеджера)
        // Если двухэтапная оплата выключена, то pay_agree = true (оплата разрешена сразу)
        $payAgreeStatus = !$isTwoStagePay; // true если двухэтапная оплата выключена, false если включена

        // Подготавливаем данные для создания заказа
        $orderDataForCreate = [
            'order_number' => 'TEMP-' . time(), // Временный номер
            'user_id' => $userId, // Используем ID из авторизации или order_data
            'status_id' => 1, // Статус "Ожидает обработки" (id=1)
            'customer_name' => $orderData['customer_name'] ?? 'Покупатель',
            'customer_email' => $orderData['customer_email'] ?? null,
            'customer_phone' => $orderData['customer_phone'] ?? null,
            'items' => json_encode($items), // JSON поле с товарами
            'subtotal' => $orderData['subtotal'] ?? $data['amount'],
            'discount_amount' => $orderData['total_discount_amount'] ?? 0,
            'sale_discount_amount' => $orderData['sale_discount_amount'] ?? 0,
            'registered_user_discount_amount' => $orderData['registered_user_discount_amount'] ?? 0,
            'promo_code_discount_amount' => $orderData['promo_code_discount_amount'] ?? 0,
            'total_discount_amount' => $orderData['total_discount_amount'] ?? 0,
            'promo_code' => $orderData['promo_code'] ?? null,
            'promo_code_id' => $orderData['promo_code_id'] ?? null,
            'use_bonus_points' => $orderData['use_bonus_points'] ?? false,
            'bonus_points_to_use' => $orderData['bonus_points_to_use'] ?? 0,
            'order_bonus_points' => $orderData['order_bonus_points'] ?? 0,
            'delivery_cost' => $orderData['delivery_cost'] ?? 0,
            'total_amount' => $data['amount'],
            'total_quantity' => $totalQuantity,
            'payment_method' => $paymentMethodName,
            'payment_method_id' => $data['payment_method_id'],
            'shipping_method' => $shippingMethodName, // Название тарифа для СДЭК или название метода
            'shipping_method_id' => $shippingMethodId, // ID метода доставки
            'shipping_address' => $orderData['shipping_address'] ?? $orderData['address'] ?? $orderData['delivery_address'] ?? null,
            'notes' => $orderData['notes'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            // Заказы создаются активными (is_active=true)
            // При успешной оплате будет установлено payed=true через webhook
            'is_active' => true,
            'payed' => false,
            'pay_agree' => $payAgreeStatus, // Устанавливаем pay_agree в зависимости от настройки two_stage_pay
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
        $orderDataForCreate['delivery_status_id'] = 1; // Статус "Создан" (остается неизменным)
        
        Log::info('Order data for create', [
            'order_data' => $orderDataForCreate,
            'two_stage_pay_enabled' => $isTwoStagePay,
            'pay_agree' => $payAgreeStatus,
            'user_id' => $userId,
            'shipping_address' => $orderDataForCreate['shipping_address']
        ]);

        // Создаем заказ
        $order = ShopOrder::create($orderDataForCreate);
        
        Log::info('Order created successfully', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payed' => $order->payed,
            'pay_agree' => $order->pay_agree,
            'two_stage_pay_enabled' => $isTwoStagePay
        ]);

        // Товары уже сохранены в JSON поле items

        // Генерируем правильный номер заказа на основе ID
        $orderNumber = $this->generateUniqueOrderNumber($order->id);
        $order->update(['order_number' => $orderNumber]);
        
        // Создаем запись в журнал о создании заказа
        $userName = null;
        if ($orderData['customer_id'] ?? null) {
            $customerUser = \App\Models\User::find($orderData['customer_id']);
            $userName = $customerUser ? $customerUser->name : null;
        }
        ShopOrderLog::logOrderCreated($order->id, $userName ?? ($orderData['customer_name'] ?? 'Покупатель'), ShopOrderLog::SECTION_ORDERS, $orderNumber);

        // Создаем запись об использовании промокода, если он был применен
        if (!empty($orderData['promo_code_id'])) {
            try {
                $promocode = \App\Models\Promocode::find($orderData['promo_code_id']);
                if ($promocode) {
                    $sessionId = request()->header('X-Session-ID');
                    $discountAmount = $orderData['promo_code_discount_amount'] ?? 0;
                    $appliedTo = [
                        'order_id' => $order->id,
                        'order_number' => $orderNumber,
                        'items' => $items
                    ];
                    
                    $promocode->recordUsage(
                        $orderData['customer_id'] ?? null,
                        $sessionId,
                        $order->id,
                        $discountAmount,
                        $appliedTo
                    );
                    
                    Log::info('Promocode usage recorded', [
                        'promocode_id' => $promocode->id,
                        'order_id' => $order->id,
                        'discount_amount' => $discountAmount
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Ошибка создания записи об использовании промокода: ' . $e->getMessage());
                // Не прерываем создание заказа, если ошибка с промокодом
            }
        }

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
                                'payed' => true, // Основной статус оплаты заказа
                                'is_active' => true,
                                'status_id' => 2, // Подтвержден
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

    /**
     * Отправить уведомления о создании заказа
     */
    private function sendOrderNotifications(ShopOrder $order, array $orderData = []): void
    {
        try {
            // Отправляем уведомления через систему оповещений (администраторам)
            $notificationService = app(NotificationService::class);
            $notificationService->notifyOrderCreated($order);

            // Также отправляем уведомление клиенту через Telegram (если указан chat_id)
            $customerChatId = $orderData['telegram_chat_id'] ?? null;
            if ($customerChatId) {
                $telegramService = app(TelegramService::class);
                $customerMessage = "✅ <b>Заказ #{$order->order_number} принят</b>\n\n";
                $customerMessage .= "Спасибо за ваш заказ! Мы получили вашу заявку и в ближайшее время свяжемся с вами для подтверждения.\n\n";
                $customerMessage .= "💰 <b>Сумма заказа:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
                $customerMessage .= "📦 <b>Товаров:</b> {$order->total_quantity} шт.\n\n";
                $customerMessage .= "📞 <b>Наш телефон:</b> +7 (999) 123-45-67\n";
                $customerMessage .= "📧 <b>Email:</b> info@skateandsnow.ru";

                $telegramService->notifyCustomer(
                    $customerChatId,
                    'order_created',
                    $order->id,
                    $customerMessage
                );
            }
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем создание заказа
            Log::error('Notification error in ShopPaymentController: ' . $e->getMessage());
        }

        // Отправляем email с накладной клиенту
        try {
            Log::info('Starting email notification process', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email,
                'has_email' => !empty($order->customer_email)
            ]);

            if (empty($order->customer_email)) {
                Log::warning('Customer email is empty, skipping email notification', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
                return;
            }

            $contacts = $this->getShopContacts();
            $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();

            Log::info('Contacts and site info retrieved', [
                'order_id' => $order->id,
                'has_contacts' => !empty($contacts),
                'has_site_info' => !empty($siteInfo)
            ]);

            // Обогащаем данные товаров названиями
            $enrichedOrder = $this->enrichOrderItems($order);

            // Отладочная информация о товарах в заказе
            Log::info('Order items for email (ShopPaymentController):', [
                'order_id' => $order->id,
                'items' => $enrichedOrder->items,
                'items_count' => is_array($enrichedOrder->items) ? count($enrichedOrder->items) : 'not array',
                'items_type' => gettype($enrichedOrder->items)
            ]);

            Log::info('Attempting to send email', [
                'order_id' => $order->id,
                'to' => $order->customer_email
            ]);

            Mail::to($order->customer_email)->send(new OrderInvoiceMail($enrichedOrder, $contacts, $siteInfo));
            
            Log::info('Invoice email sent successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email
            ]);
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем создание заказа
            Log::error('Email notification error in ShopPaymentController', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email ?? 'not set',
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Получить контакты магазина
     */
    private function getShopContacts()
    {
        try {
            $contact = \App\Models\Contact::with(['addresses', 'phones', 'socials'])
                ->where('is_main', 1)
                ->first();

            if (!$contact) {
                return null;
            }

            // Получаем основные данные
            $mainAddress = $contact->mainAddress();
            $mainPhone = $contact->mainPhone();

            // Формируем данные для накладной
            return [
                'name' => $contact->name,
                'short_name' => $contact->short_name,
                'legal_name' => $contact->legal_name,
                'inn' => $contact->inn,
                'ogrn' => $contact->ogrnip, // Используем ogrnip как ogrn
                'kpp' => null, // KPP не хранится в таблице
                'address' => $mainAddress ? $mainAddress->address : null,
                'phone' => $mainPhone ? $mainPhone->phone : null,
                'email' => null, // Email не хранится в таблице contacts
                'legal_address' => $contact->legal_address,
            ];

        } catch (\Exception $e) {
            Log::error('Error getting shop contacts: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Обогатить данные товаров заказа названиями
     */
    private function enrichOrderItems($order)
    {
        // Получаем items - они могут быть уже массивом (благодаря cast) или JSON строкой
        $items = $order->items;
        
        // Если items - это строка, декодируем JSON
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        
        // Если items не массив или пустой, возвращаем заказ как есть
        if (!$items || !is_array($items)) {
            Log::warning('Order items is not an array or is empty', [
                'order_id' => $order->id,
                'items_type' => gettype($order->items),
                'items_value' => $order->items
            ]);
            return $order;
        }

        $enrichedItems = [];
        foreach ($items as $item) {
            $enrichedItem = $item;

            // Получаем название товара по good_id
            if (isset($item['good_id'])) {
                try {
                    $good = ShopGood::find($item['good_id']);
                    if ($good) {
                        $enrichedItem['name'] = $good->name;
                        $enrichedItem['good_name'] = $good->name;

                        // Если есть вариация, получаем её название
                        if (isset($item['variation_id']) && $item['variation_id']) {
                            $variation = \App\Models\ShopGoodVariation::find($item['variation_id']);
                            if ($variation && $variation->name) {
                                $enrichedItem['name'] = $good->name . ' (' . $variation->name . ')';
                                $enrichedItem['good_name'] = $good->name . ' (' . $variation->name . ')';
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error enriching item: ' . $e->getMessage(), [
                        'item' => $item,
                        'order_id' => $order->id
                    ]);
                }
            }

            // Пересчитываем сумму товара
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $enrichedItem['total'] = $price * $quantity;

            $enrichedItems[] = $enrichedItem;
        }

        // Создаем копию заказа с обогащенными данными
        $enrichedOrder = clone $order;
        $enrichedOrder->items = $enrichedItems;

        Log::info('Order items enriched', [
            'order_id' => $order->id,
            'items_count' => count($enrichedItems)
        ]);

        return $enrichedOrder;
    }

}
