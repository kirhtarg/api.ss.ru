<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use App\Models\ShopOrder;
use App\Models\ShopPaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TbankPaymentService
{
    protected array $settings;
    protected string $baseUrl;
    protected string $provider;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->provider = $settings['dolyame_provider'] ?? 'tbank';
        
        // Debug: Log the settings being passed
        Log::debug('TbankPaymentService constructor: settings received', [
            'settings_keys' => array_keys($settings),
            'provider' => $this->provider,
            'has_dolyame_provider' => isset($settings['dolyame_provider']),
            'has_shop_id' => isset($settings['shop_id']),
            'has_dolyame_shop_id' => isset($settings['dolyame_shop_id']),
        ]);

        if ($this->provider === 'partner') {
            $dolyameUrl = $this->settings['dolyame_api_url'] ?? $this->settings['api_url'] ?? 'https://partner.dolyame.ru/v1';
            $this->baseUrl = rtrim($dolyameUrl, '/');
            Log::debug('TbankPaymentService: Using Dolyame Partner API endpoint', ['url' => $this->baseUrl]);

            // Unset keys from the old integration to avoid confusion
            unset($this->settings['terminal_key'], $this->settings['terminal_password'], $this->settings['api_url_test'], $this->settings['api_url_live']);
        } else { // tbank
            $mode = ($settings['mode'] ?? 'test') === 'live' ? 'live' : 'test';
            if ($mode === 'live') {
                $this->baseUrl = $settings['api_url_live']
                    ?? config('services.tbank.api_url_live', 'https://securepay.tinkoff.ru/v2');
            } else {
                $this->baseUrl = $settings['api_url_test']
                    ?? config('services.tbank.api_url_test', 'https://securepay.tinkoff.ru/v2');
            }
        }
    }

    /**
     * Dispatches payment initiation to the correct provider method.
     */
    public function initiatePayment($order): array
    {
        Log::debug('TbankPaymentService: initiatePayment called', ['provider' => $this->provider]);
        if ($this->provider === 'partner') {
            Log::debug('TbankPaymentService: dispatching to initiateDolyamePartnerPayment');
            return $this->initiateDolyamePartnerPayment($order);
        }
        
        Log::debug('TbankPaymentService: dispatching to initiateTbankAcquiringPayment');
        return $this->initiateTbankAcquiringPayment($order);
    }

    /**
     * Инициирует платеж через API "Долями" (Partner).
     */
    protected function initiateDolyamePartnerPayment($order): array
    {
        Log::debug('TbankPaymentService: initiateDolyamePartnerPayment called');
        $apiUrl = $this->baseUrl . '/orders/create';
        Log::debug('Dolyame Partner: Constructed API URL', ['url' => $apiUrl]);
        
        // Debug: Log the settings being used
        Log::debug('Dolyame Partner: initiateDolyamePartnerPayment called', [
            'settings_keys' => array_keys($this->settings),
            'has_dolyame_shop_id' => isset($this->settings['dolyame_shop_id']),
            'has_shop_id' => isset($this->settings['shop_id']),
        ]);

        try {
            $get = function ($obj, string $key, $default = null) {
                if (is_array($obj)) return $obj[$key] ?? $default;
                if (is_object($obj)) return $obj->{$key} ?? $default;
                return $default;
            };

            $orderId = $get($order, 'id', null);
            $orderNumber = (string) $get($order, 'order_number', (string) $orderId);
            $totalAmount = (float) $get($order, 'total_amount', 0);

            // Prepare items for Dolyame
            $items = [];
            $orderItems = is_array($order) ? ($order['items'] ?? []) : (is_object($order) ? ($order->items ?? []) : []);
            if (is_string($orderItems)) {
                $decoded = json_decode($orderItems, true);
                $orderItems = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($orderItems)) $orderItems = [];

            foreach ($orderItems as $item) {
                $itemName = trim($item['name'] ?? $item['good_name'] ?? 'Товар');
                $itemPrice = round((float)($item['final_price'] ?? $item['price'] ?? 0), 2);
                $itemQuantity = (int)($item['quantity'] ?? 1);
                
                if (empty($itemName) || $itemPrice <= 0 || $itemQuantity <= 0) {
                    Log::warning('Dolyame Partner: Skipping invalid item', [
                        'name' => $itemName,
                        'price' => $itemPrice,
                        'quantity' => $itemQuantity,
                    ]);
                    continue;
                }
                
                $items[] = [
                    'name' => $itemName,
                    'price' => $itemPrice,
                    'quantity' => $itemQuantity,
                    'tax' => $this->settings['item_tax'] ?? 'none',
                ];
            }

            // Validate that we have at least one valid item
            if (empty($items)) {
                Log::error('Dolyame Partner: No valid items found for order', ['order_items' => $orderItems]);
                return [
                    'success' => false,
                    'message' => 'No valid order items found',
                ];
            }

            $deliveryCost = is_array($order) ? ($order['delivery_cost'] ?? 0) : (is_object($order) ? ($order->delivery_cost ?? 0) : 0);
            if ($deliveryCost > 0) {
                 $items[] = [
                    'name' => 'Доставка',
                    'price' => round((float)$deliveryCost, 2),
                    'quantity' => 1,
                    'tax' => $this->settings['delivery_tax'] ?? 'none',
                ];
            }

            // Client info
            $customerName = $get($order, 'customer_name', '');
            $customerEmail = $get($order, 'customer_email', '');
            
            Log::debug('Dolyame Partner: Client info from order', [
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $get($order, 'customer_phone', ''),
            ]);
            
            $nameParts = explode(' ', trim($customerName), 2);
            $firstName = $nameParts[0] ?: 'Покупатель';
            $lastName = $nameParts[1] ?? '';

            // Format phone number for Dolyame API (should be in format +79993334444)
            $phone = $get($order, 'customer_phone', '');
            Log::debug('Dolyame Partner: Raw phone number', ['raw_phone' => $phone]);
            
            $phone = preg_replace('/[^0-9]/', '', $phone);
            Log::debug('Dolyame Partner: Cleaned phone number', ['cleaned_phone' => $phone]);
            
            if (strlen($phone) === 10 && $phone[0] === '9') {
                $phone = '+7' . $phone;
            } elseif (strlen($phone) === 11 && $phone[0] === '8') {
                $phone = '+7' . substr($phone, 1);
            } elseif (strlen($phone) === 11 && $phone[0] === '7') {
                $phone = '+' . $phone;
            } else {
                $phone = '+' . $phone; // Default to adding + prefix
            }
            
            Log::debug('Dolyame Partner: Formatted phone number', ['formatted_phone' => $phone]);

            // Get shop settings
        Log::debug('Dolyame Partner: About to access shop_id', [
            'settings_available' => array_keys($this->settings),
            'dolyame_shop_id_exists' => isset($this->settings['dolyame_shop_id']),
            'shop_id_exists' => isset($this->settings['shop_id']),
        ]);
        
        try {
            $shopId = $this->settings['dolyame_shop_id'] ?? $this->settings['shop_id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Dolyame Partner: Exception accessing shop_id', [
                'error' => $e->getMessage(),
                'settings_keys' => array_keys($this->settings),
            ]);
            throw $e;
        }
            if (!$shopId) {
                Log::error('Dolyame Partner: shop_id is missing in settings');
                return [
                    'success' => false,
                    'message' => 'Payment gateway misconfigured: missing shop_id',
                ];
            }

            // Validate order number format (Dolyame might have specific requirements)
            if (strlen($orderNumber) < 1 || strlen($orderNumber) > 50) {
                Log::error('Dolyame Partner: Invalid order number length', ['order_number' => $orderNumber]);
                return [
                    'success' => false,
                    'message' => 'Invalid order number format',
                ];
            }

            // Prepare Dolyame-specific payload according to API documentation
            // Based on the API docs, the structure should be simpler
            $payload = [
                'order' => [
                    'id' => $orderNumber,
                    'amount' => round($totalAmount, 2),
                    'items' => $items,
                    'shop_id' => $shopId, // Moved shop_id inside order object
                    'prepayment_amount' => 0, // Required field for Dolyame
                ],
                'client_info' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $customerEmail,
                    'phone' => $phone,
                    'middle_name' => '', // Required field
                ],
                'notification_url' => url('/api/webhooks/tbank'),
                'success_url' => url('/api/public/shop/payment/return?payment_type=tbank_dolyame&status=success&order_number=' . urlencode($orderNumber)),
                'fail_url' => url('/api/public/shop/payment/return?payment_type=tbank_dolyame&status=fail&order_number=' . urlencode($orderNumber)),
            ];

            // Build HTTP client with Basic Auth and optional mTLS
            $options = [
                'connect_timeout' => 10,
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ];

            // Only apply mTLS certificates in production environment where files exist
            if (app()->environment('production')) {
                $certPath = $this->settings['dolyame_cert_path'] ?? null;
                $keyPath = $this->settings['dolyame_private_key_path'] ?? $this->settings['dolyame_cert_key_path'] ?? null;
                $keyPass = $this->settings['dolyame_private_key_password'] ?? $this->settings['dolyame_cert_key_password'] ?? null;

                Log::debug('Dolyame Partner: Certificate configuration for production', [
                    'cert_path' => $certPath,
                    'key_path' => $keyPath,
                    'cert_exists' => $certPath ? file_exists($certPath) : false,
                    'key_exists' => $keyPath ? file_exists($keyPath) : false,
                ]);

                if ($certPath && file_exists($certPath)) {
                    $options['cert'] = $certPath;
                }

                if ($keyPath && file_exists($keyPath)) {
                    $options['ssl_key'] = $keyPass ? [$keyPath, $keyPass] : $keyPath;
                }
            } else {
                Log::debug('Dolyame Partner: Skipping mTLS certificate for non-production environment');
            }

            $login = $this->settings['dolyame_login'] ?? '';
            $password = $this->settings['dolyame_password'] ?? '';
            
            Log::debug('Dolyame Partner: Authentication parameters', [
                'login' => $login,
                'has_login' => !empty($login),
                'has_password' => !empty($password),
                'login_source' => isset($this->settings['dolyame_login']) ? 'dolyame_login' : 'none',
                'password_source' => isset($this->settings['dolyame_password']) ? 'dolyame_password' : 'none',
            ]);

            // Generate X-Correlation-ID (required header)
            $correlationId = Str::uuid()->toString();

            $authHeaders = [
                'X-Correlation-ID' => $correlationId,
            ];

            // Build HTTP client with appropriate authentication
            $http = Http::timeout(30)->retry(2, 1000)
                ->withHeaders($authHeaders)
                ->withOptions($options);
            
            // Only add Basic Auth if we're not using API key
            if (!empty($login) && !empty($password)) {
                $http = $http->withBasicAuth($login, $password);
            }
            
            $response = $http->post($apiUrl, $payload);
            $responseData = $response->json();
            
            Log::info('Dolyame Partner request completed', [
                'url' => $apiUrl,
                'status' => $response->status(),
                'response_body' => $responseData,
                'response_headers' => $response->headers(),
                'correlation_id' => $correlationId,
            ]);

            if ($response->successful() && isset($responseData['link'])) {
                 return [
                    'success' => true,
                    'payment_url' => $responseData['link'],
                    'transaction_id' => $responseData['id'] ?? null,
                    'request_data' => $payload,
                    'response_data' => $responseData,
                ];
            } else {
                 $errorMessage = $responseData['message'] ?? ($responseData['detail'] ?? 'Unknown Dolyame Partner API error');
                 $errorCode = $responseData['code'] ?? $response->status();
                 
                 // Enhanced logging for 422 errors to capture validation details
                 if ($response->status() === 422) {
                     Log::error('Dolyame Partner API Validation Error (422) for order ' . $orderNumber, [
                        'response_status' => $response->status(),
                        'response_body' => $responseData,
                        'response_errors' => $responseData['errors'] ?? null,
                        'response_fields' => $responseData['fields'] ?? null,
                        'request_payload' => $payload,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'correlation_id' => $correlationId,
                     ]);
                 } else {
                     Log::error('Dolyame Partner API Error for order ' . $orderNumber, [
                        'response_status' => $response->status(),
                        'response_body' => $responseData,
                        'request_payload' => $payload,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'correlation_id' => $correlationId,
                     ]);
                 }
                
                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'response_data' => $responseData,
                    'error_code' => $errorCode,
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('Exception during Dolyame Partner payment initiation for order ' . ($orderNumber ?? 'N/A') . ': ' . $e->getMessage(), [
                'exception' => $e,
                'settings' => $this->settings,
            ]);
            return [
                'success' => false,
                'message' => 'Exception during payment processing: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Инициирует платеж через API Т-Банка (Acquiring).
     */
    public function initiateTbankAcquiringPayment($order): array
    {
        Log::debug('TbankPaymentService: initiateTbankAcquiringPayment called');
        $apiUrl = $this->baseUrl . '/Init'; 

        try {
            $get = function ($obj, string $key, $default = null) {
                if (is_array($obj)) return $obj[$key] ?? $default;
                if (is_object($obj)) return $obj->{$key} ?? $default;
                return $default;
            };
            $orderId = $get($order, 'id', null);
            $orderNumber = (string) $get($order, 'order_number', (string) $orderId);
            $totalAmount = (float) $get($order, 'total_amount', 0);
            $customerEmail = $get($order, 'customer_email', null);
            $customerPhone = $get($order, 'customer_phone', null);
            $userId = $get($order, 'user_id', null);

            $orderIdForGateway = $orderId ?: $orderNumber;
            $isDolyami = strtoupper($this->settings['pay_type'] ?? '') === 'DOLYAMI';
            $paymentTypeParam = $isDolyami ? 'tbank_dolyame' : 'tbank_eacq';
            $successUrl = url('/api/public/shop/payment/return?payment_type=' . $paymentTypeParam . '&status=success&order_number=' . urlencode($orderNumber));
            $failUrl = url('/api/public/shop/payment/return?payment_type=' . $paymentTypeParam . '&status=fail&order_number=' . urlencode($orderNumber));

            $payload = [
                'TerminalKey' => $this->settings['terminal_key'] ?? '',
                'Amount' => (int) round($totalAmount * 100),
                'OrderId' => $orderIdForGateway,
                'Description' => 'Оплата заказа №' . $orderNumber,
                'NotificationURL' => url('/api/webhooks/tbank'),
                'SuccessURL' => $successUrl,
                'FailURL' => $failUrl,
                'Receipt' => [
                    'Email' => $customerEmail,
                    'Phone' => $customerPhone,
                    'Taxation' => $this->settings['taxation'] ?? 'usn_income_outcome',
                    'Items' => $this->prepareItemsForReceipt($order),
                ],
                'DATA' => [
                    'Email' => $customerEmail,
                ],
            ];

            // Ensure FfdVersion is set, default to 1.2 if empty or not present
            $ffdVersion = !empty($this->settings['ffd_version']) ? $this->settings['ffd_version'] : '1.2';
            $payload['Receipt']['FfdVersion'] = $ffdVersion;
            if ($isDolyami) {
                $payload['PayType'] = 'DOLYAMI';
                $payload['DATA']['Context'] = '7';
            }
            if (!empty($userId)) {
                $payload['CustomerKey'] = (string)$userId;
            }

            // Пересчитываем сумму чека в копейках и синхронизируем с Amount
            $itemsSumKopecks = 0;
            if (!empty($payload['Receipt']['Items']) && is_array($payload['Receipt']['Items'])) {
                foreach ($payload['Receipt']['Items'] as $it) {
                    $itemsSumKopecks += (int) ($it['Amount'] ?? 0);
                }
            }
            if ($itemsSumKopecks > 0) {
                $payload['Amount'] = $itemsSumKopecks;
            }

            // Удаляем поля со значением null/'' из payload для一致ного расчета токена
            $payload = $this->pruneNulls($payload);
            
            // Добавляем токен подписи
            $payload['Token'] = $this->generateToken($payload);

            $verify = $this->settings['verify_ssl'] ?? config('services.tbank.verify_ssl', true);
            $caBundle = $this->settings['ca_bundle_path'] ?? config('services.tbank.ca_bundle_path');
            $disableVerify = app()->environment('production') ? false : true;
            $mode = ($this->settings['mode'] ?? 'test') !== 'live';
            if ($mode) {
                $disableVerify = true;
            }
            $http = Http::timeout(30)->retry(2, 1000)->withOptions([
                'verify' => $disableVerify ? false : ($caBundle ?: $verify),
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                'connect_timeout' => 10,
            ]);

            Log::info('T-Bank Acquiring: Sending payment request.', ['url' => $apiUrl, 'payload' => $payload]);

            $response = $http->post($apiUrl, $payload);
            $responseData = $response->json();
            $rawBody = $response->body();

            Log::info('T-Bank initiatePayment response received', ['status' => $response->status(), 'body' => $responseData]);

            if ($response->successful() && ($responseData['Success'] ?? false) === true) {
                return [
                    'success' => true,
                    'payment_url' => $responseData['PaymentURL'] ?? null,
                    'transaction_id' => $responseData['PaymentId'] ?? null,
                    'request_data' => $payload,
                    'response_data' => $responseData,
                ];
            } else {
                $errorMessage = $responseData['Message'] ?? 'Unknown T-Bank API error';
                Log::error('T-Bank API Error during payment initiation for order ' . $order->id . ': ' . $errorMessage, [
                    'response_status' => $response->status(),
                    'response_body' => $responseData,
                    'response_body_raw' => $rawBody,
                    'settings' => $this->settings,
                ]);
                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'response_data' => $responseData,
                ];
            }
        } catch (ConnectionException $e) {
            Log::error('T-Bank connection timeout for order ' . $order->id . ': ' . $e->getMessage(), [
                'exception' => $e,
                'settings' => $this->settings,
            ]);
            return [
                'success' => false,
                'error' => 'connection_timeout',
                'message' => 'Платежный шлюз недоступен (таймаут соединения)',
            ];
        } catch (\Exception $e) {
            $rawBody = null;
            if ($e instanceof RequestException && $e->response) {
                $rawBody = $e->response->body();
            }
            Log::error('Exception during T-Bank payment initiation for order ' . $order->id . ': ' . $e->getMessage(), [
                'exception' => $e,
                'settings' => $this->settings,
                'response_body_raw' => $rawBody,
            ]);
            return [
                'success' => false,
                'message' => 'Exception during payment processing',
            ];
        }
    }

    /**
     * Готовит массив товаров для чека.
     */
    protected function prepareItemsForReceipt($order): array
    {
        $items = [];
        $orderItems = is_array($order) ? ($order['items'] ?? []) : (is_object($order) ? ($order->items ?? []) : []);
        if (is_string($orderItems)) {
            $decoded = json_decode($orderItems, true);
            $orderItems = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($orderItems)) {
            $orderItems = [];
        }
        foreach ($orderItems as $item) {
            $unitPrice = (float) ($item['final_price'] ?? $item['price'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 1);
            $priceK = (int) round($unitPrice * 100);
            $amountK = (int) round($priceK * $quantity);
            $items[] = [
                'Name' => $item['name'] ?? $item['good_name'] ?? 'Товар',
                'Price' => $priceK,
                'Quantity' => $quantity,
                'Amount' => $amountK,
                'Tax' => $this->settings['item_tax'] ?? 'none',
                'PaymentMethod' => $this->settings['item_payment_method'] ?? 'full_prepayment',
                'PaymentObject' => $this->settings['item_payment_object'] ?? 'commodity',
                'MeasurementUnit' => 'шт',
            ];
        }
        
        // Добавляем доставку как отдельный товар в чеке, если она есть
        $deliveryCost = is_array($order) ? ($order['delivery_cost'] ?? 0) : (is_object($order) ? ($order->delivery_cost ?? 0) : 0);
        if ($deliveryCost > 0) {
            $deliveryK = (int) round($deliveryCost * 100);
            $items[] = [
                'Name' => 'Доставка',
                'Price' => $deliveryK,
                'Quantity' => 1.0,
                'Amount' => $deliveryK,
                'Tax' => $this->settings['delivery_tax'] ?? 'none',
                'PaymentMethod' => $this->settings['delivery_payment_method'] ?? 'full_prepayment',
                'PaymentObject' => $this->settings['delivery_payment_object'] ?? 'service',
                'MeasurementUnit' => 'шт',
            ];
        }

        return $items;
    }

    /**
     * Генерирует токен подписи для запроса.
     */
    protected function generateToken(array $payload): string
    {
        $tokenPayload = $this->pruneNulls($payload);
        $tokenPayload['Password'] = $this->settings['terminal_password'];
        unset($tokenPayload['Receipt'], $tokenPayload['Token'], $tokenPayload['DATA']);
        ksort($tokenPayload);
        $concatenated = '';
        foreach ($tokenPayload as $value) {
            $concatenated .= $this->concatValuesRecursively($value);
        }
        return hash('sha256', $concatenated);
    }
    
    protected function pruneNulls($data) {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $k => $v) {
                if ($v === null || $v === '') continue;
                $clean = $this->pruneNulls($v);
                if ($clean === null || $clean === '') continue;
                $result[$k] = $clean;
            }
            return $result;
        }
        if (is_object($data)) {
            $vars = get_object_vars($data);
            $cleanObj = [];
            foreach ($vars as $k => $v) {
                if ($v === null || $v === '') continue;
                $clean = $this->pruneNulls($v);
                if ($clean === null || $clean === '') continue;
                $cleanObj[$k] = $clean;
            }
            return (object)$cleanObj;
        }
        return $data;
    }
    
    protected function concatValuesRecursively($value): string
    {
        if (is_array($value)) {
            ksort($value);
            $str = '';
            foreach ($value as $v) {
                $str .= $this->concatValuesRecursively($v);
            }
            return $str;
        }
        if (is_object($value)) {
            $vars = get_object_vars($value);
            ksort($vars);
            $str = '';
            foreach ($vars as $v) {
                $str .= $this->concatValuesRecursively($v);
            }
            return $str;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }
    
    /**
     * Проверяет подлинность webhook-запроса от Т-Банка или Долями.
     */
    public function verifyWebhook(array $webhookData, Request $request): bool
    {
        if ($this->provider === 'partner') {
            $signatureHeader = $request->header('Signature');
            if (!$signatureHeader) {
                Log::warning('Dolyame Partner Webhook: Signature header not found.');
                return false;
            }
            $secret = $this->settings['dolyame_password'] ?? $this->settings['dolyame_password1'] ?? '';
            $expectedSignature = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

            if (!hash_equals($expectedSignature, $signatureHeader)) {
                 Log::warning('Dolyame Partner Webhook: Signature mismatch.', ['expected' => $expectedSignature, 'received' => $signatureHeader]);
                return false;
            }
            return true;

        } else { // 'tbank'
            if (!isset($webhookData['Token'])) {
                Log::warning('T-Bank Webhook: Token not found.');
                return false;
            }

            $receivedToken = $webhookData['Token'];
            $payloadForToken = $webhookData;
            unset($payloadForToken['Token']); // Удаляем токен из данных для проверки

            $expectedToken = $this->generateToken($payloadForToken);

            if (!hash_equals($expectedToken, $receivedToken)) {
                Log::warning('T-Bank Webhook: Token mismatch.', ['expected' => $expectedToken, 'received' => $receivedToken]);
                return false;
            }

            return true;
        }
    }

    /**
     * Определяет статус платежа на основе данных из webhook.
     */
    public function processWebhookStatus(array $webhookData): string
    {
        $status = $webhookData['status'] ?? ($webhookData['Status'] ?? null);

        if ($this->provider === 'partner') {
             switch (strtolower($status)) {
                case 'approved':
                case 'committed':
                case 'completed':
                    return 'success';
                case 'rejected':
                case 'canceled':
                    return 'cancelled'; // Используем 'cancelled' для единообразия
                case 'wait_for_commit':
                    return 'pending'; // Это состояние ожидания
                default:
                    Log::warning('Dolyame Partner Webhook: Unhandled status.', ['status' => $status]);
                    return 'failed';
            }
        } 
        
        // 'tbank' provider logic
        if ($status) {
            switch (strtoupper($status)) {
                case 'CONFIRMED':
                case 'AUTHORIZED':
                case 'SETTLED':
                    return 'success';
                case 'REJECTED':
                case 'DEADLINE_EXPIRED':
                    return 'failed';
                case 'CANCELED':
                    return 'cancelled';
                default:
                    Log::warning('T-Bank Webhook: Unhandled status.', ['status' => $status]);
                    return 'failed';
            }
        }
        
        Log::warning('T-Bank Webhook: Status not found in webhook data.');
        return 'failed';
    }

    /**
     * Ищет транзакцию по данным из webhook.
     */
    public function findTransactionByWebhookData(array $webhookData): ?ShopPaymentTransaction
    {
        $transactionId = null;
        if ($this->provider === 'partner') {
            // В Partner API ID вебхука - это ID операции, который мы сохраняем как transaction_id
            $transactionId = $webhookData['id'] ?? null;
        } else { // tbank
            $transactionId = $webhookData['PaymentId'] ?? null;
        }

        if ($transactionId) {
            $transaction = ShopPaymentTransaction::where('transaction_id', $transactionId)->first();
            if ($transaction) {
                return $transaction;
            }
        }

        // Fallback для старого API Т-Банка по OrderId (ID заказа в системе)
        if ($this->provider === 'tbank' && isset($webhookData['OrderId'])) {
            $order = ShopOrder::find($webhookData['OrderId']);
            if ($order) {
                // Ищем последнюю транзакцию в статусе pending для этого заказа
                return $order->paymentTransactions()->where('status', 'pending')->latest()->first();
            }
        }

        // Fallback для Partner API по OrderId (номер заказа)
        if ($this->provider === 'partner' && isset($webhookData['order']['id'])) {
             $orderNumber = $webhookData['order']['id'];
             $order = ShopOrder::where('order_number', $orderNumber)->first();
             if ($order) {
                 return $order->paymentTransactions()->where('status', 'pending')->latest()->first();
             }
        }
        
        return null;
    }
}
