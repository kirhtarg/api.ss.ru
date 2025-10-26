<?php

namespace App\Services;

use App\Models\ShopCdekSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdekService
{
    private $settings;
    private $apiUrl;
    private $sslVerify;
    private $timeout;

    public function __construct()
    {
        $this->settings = ShopCdekSettings::getActive();
        $this->apiUrl = config('cdek.api_url', 'https://api.cdek.ru/v2');
        $this->sslVerify = config('cdek.ssl_verify', false);
        $this->timeout = config('cdek.timeout', 30);
    }

    /**
     * Получить список городов СДЭК
     */
    public function getCities($query = '')
    {
        try {
            if (!$this->settings) {
                return null;
            }
            
            // Получаем токен авторизации
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }
            
            // Используем правильный endpoint из документации СДЭК с параметром city
            $url = $this->apiUrl . '/location/cities?country_codes=RU&city=' . rawurlencode($query);
            
            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('CdekService: Unsuccessful response: ' . $response->status());
        return null;
        } catch (\Exception $e) {
            Log::error('Ошибка получения городов СДЭК: ' . $e->getMessage());
            Log::error('CdekService: Exception stack: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Получить пункты выдачи в городе
     */
    public function getPickupPoints($cityCode)
    {
        try {
            Log::info('CdekService: Getting pickup points for city: ' . $cityCode);
            
            if (!$this->settings) {
                Log::error('CdekService: No CDEK settings found');
                return null;
            }
            
            // Получаем токен авторизации
        $token = $this->getAccessToken();
            if (!$token) {
                Log::error('CdekService: Failed to get access token');
                return null;
            }
            
            // Проверяем, является ли $cityCode уже числовым кодом
            $numericCityCode = $cityCode;
            if (!is_numeric($cityCode)) {
                // Если это не числовой код, получаем его из API СДЭК
                $numericCityCode = $this->getNumericCityCode($cityCode, $token);
                if (!$numericCityCode) {
                    Log::error('CdekService: Could not get numeric city code for: ' . $cityCode);
                    return null;
                }
            }
            
            Log::info('CdekService: Using numeric city code: ' . $numericCityCode);
            
            // Используем реальный API СДЭК как в папке cdek
            $url = $this->apiUrl . '/deliverypoints?city_code=' . $numericCityCode;
            
            Log::info('CdekService: Making request to: ' . $url);
            
            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($url);

        if ($response->successful()) {
            $data = $response->json();
                Log::info('CdekService: Got PVZ data from API: ' . json_encode($data));
                
                // Обрабатываем ответ как в папке cdek
                $points = $data['delivery_points'] ?? $data;
                
                if (is_array($points)) {
                    // Преобразуем данные в нужный формат
                    $formattedPoints = [];
                    foreach ($points as $point) {
                        $formattedPoints[] = [
                            'code' => $point['code'] ?? $point['uuid'] ?? uniqid(),
                            'name' => $point['name'] ?? 'ПВЗ',
                            'address' => $point['address'] ?? $point['location']['address'] ?? '',
                            'work_time' => $point['work_time'] ?? $point['schedule'] ?? '',
                            'phone' => $point['phone'] ?? '',
                            'location' => [
                                'latitude' => $point['location']['latitude'] ?? $point['latitude'] ?? 0,
                                'longitude' => $point['location']['longitude'] ?? $point['longitude'] ?? 0
                            ],
                            'type' => $point['type'] ?? 'PVZ'
                        ];
                    }
                    
                    Log::info('CdekService: Formatted PVZ points: ' . json_encode($formattedPoints));
                    return $formattedPoints;
                }
                
                return $points;
            } else {
                Log::error('CdekService: API request failed: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Ошибка получения пунктов выдачи СДЭК: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получить числовой код города из API СДЭК
     */
    private function getNumericCityCode($cityName, $token)
    {
        try {
            Log::info('CdekService: Getting numeric city code for: ' . $cityName);
            
            // Маппинг наших кодов на полные названия городов
            $cityMapping = [
                'spb' => 'Санкт-Петербург',
                'moscow' => 'Москва',
                'orenburg' => 'Оренбург',
                'ekaterinburg' => 'Екатеринбург'
            ];
            
            $searchName = $cityMapping[$cityName] ?? $cityName;
            Log::info('CdekService: Searching for city: ' . $searchName);
            
            // Используем правильный endpoint из документации СДЭК с параметром city
            $url = $this->apiUrl . '/location/cities?country_codes=RU&city=' . rawurlencode($searchName);
            
            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('CdekService: Cities response: ' . json_encode($data));
                
                // Ищем точное совпадение по названию города
                foreach ($data as $city) {
                    if (isset($city['city']) && $city['city'] === $searchName) {
                        $code = $city['code'];
                        Log::info('CdekService: Found exact match city code: ' . $code . ' for ' . $searchName);
                        return $code;
                    }
                }
                
                // Если точного совпадения нет, берем первый результат
                if (isset($data[0]['code'])) {
                    $code = $data[0]['code'];
                    Log::info('CdekService: Using first city code: ' . $code . ' for ' . $searchName);
                    return $code;
                }
            }
            
            Log::error('CdekService: Failed to get city code, response: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('CdekService: Error getting city code: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Рассчитать стоимость доставки
     */
    public function calculateDelivery($fromCityCode, $toCityCode, $weight = null, $length = null, $width = null, $height = null)
    {
        if (!$this->settings) {
            Log::warning('CdekService: No active CDEK settings found, using fallback data');
            return $this->getFallbackDeliveryData($fromCityCode, $toCityCode);
        }

        try {
            $requestData = [
                'from_location' => [
                    'code' => (int)$fromCityCode
                ],
                'to_location' => [
                    'code' => (int)$toCityCode
                ],
                'packages' => [
                    [
                        'weight' => (float)($weight ?? $this->settings->default_weight ?? 1),
                        'length' => (float)($length ?? $this->settings->default_length ?? 30),
                        'width' => (float)($width ?? $this->settings->default_width ?? 20),
                        'height' => (float)($height ?? $this->settings->default_height ?? 10)
                    ]
                ],
                'tariff_codes' => $this->settings->getActiveTariffCodes()
            ];

            Log::info('CdekService: Calculate delivery request data: ' . json_encode($requestData));
            
            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => 30, // Уменьшаем таймаут для расчета доставки
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json'
            ])->post($this->apiUrl . '/calculator/tarifflist', $requestData);

            Log::info('CdekService: Calculate delivery response status: ' . $response->status());
            Log::info('CdekService: Calculate delivery response body: ' . $response->body());

            if ($response->successful()) {
                $data = $response->json();
                $result = $this->processDeliveryResponse($data);
                Log::info('CdekService: Processed delivery response: ' . json_encode($result));
                return $result;
            }

            Log::warning('CdekService: Calculate delivery failed with status: ' . $response->status());
            return null;
        } catch (\Exception $e) {
            Log::error('Ошибка расчета доставки СДЭК: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Получить токен доступа
     */
    private function getAccessToken()
    {
        if (!$this->settings || !$this->settings->client_id || !$this->settings->client_secret) {
            Log::error('CDEK API keys are not configured.');
            return null;
        }

        // Проверяем кэш токена
        $cacheKey = 'cdek_token_' . $this->settings->client_id;
        $cachedToken = cache()->get($cacheKey);
        if ($cachedToken) {
            Log::info('CdekService: Using cached token');
            return $cachedToken;
        }

        $maxRetries = 3;
        $retryDelay = 2; // секунды

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info("CdekService: Getting access token (attempt {$attempt}/{$maxRetries}) with client_id: " . $this->settings->client_id);
                
                // Используем правильный endpoint для получения токена как в папке cdek
                $params = [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->settings->client_id,
                    'client_secret' => $this->settings->client_secret
                ];
                $url = $this->apiUrl . '/oauth/token?' . http_build_query($params);
                
                $response = Http::withOptions([
                    'verify' => $this->sslVerify,
                    'timeout' => $this->timeout,
                ])->post($url);

                Log::info('CdekService: Token response status: ' . $response->status());
                Log::info('CdekService: Token response body: ' . $response->body());

                if ($response->successful()) {
                    $data = $response->json();
                    $token = $data['access_token'] ?? null;
                    if ($token) {
                        // Кэшируем токен на 50 минут (токены СДЭК живут 1 час)
                        cache()->put($cacheKey, $token, 3000);
                        Log::info('CdekService: Got access token and cached it');
                        return $token;
                    }
                }

                Log::warning("CdekService: Failed to get access token on attempt {$attempt}, status: " . $response->status());
                
                if ($attempt < $maxRetries) {
                    Log::info("CdekService: Retrying in {$retryDelay} seconds...");
                    sleep($retryDelay);
                    $retryDelay *= 2; // Увеличиваем задержку
                }
            } catch (\Exception $e) {
                Log::error("CdekService: Error getting access token on attempt {$attempt}: " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    Log::info("CdekService: Retrying in {$retryDelay} seconds...");
                    sleep($retryDelay);
                    $retryDelay *= 2;
                }
            }
        }

        Log::error('CdekService: Failed to get access token after all retries');
        return null;
    }

    /**
     * Обработать ответ расчета доставки
     */
    private function processDeliveryResponse($data)
    {
        if (!isset($data['tariff_codes']) || empty($data['tariff_codes'])) {
            return null;
        }

        $result = [];
        foreach ($data['tariff_codes'] as $tariff) {
            if (isset($tariff['delivery_sum']) && $tariff['delivery_sum'] > 0) {
                $result[] = [
                    'tariff_code' => $tariff['tariff_code'],
                    'tariff_name' => $tariff['tariff_name'] ?? 'СДЭК',
                    'delivery_sum' => $tariff['delivery_sum'],
                    'period_min' => $tariff['period_min'] ?? 1,
                    'period_max' => $tariff['period_max'] ?? 7,
                    'delivery_mode' => $tariff['delivery_mode'] ?? 1
                ];
            }
        }

        return $result;
    }

    /**
     * Получить минимальную стоимость доставки
     */
    public function getMinDeliveryCost($fromCityCode, $toCityCode, $weight = null, $length = null, $width = null, $height = null)
    {
        $deliveryOptions = $this->calculateDelivery($fromCityCode, $toCityCode, $weight, $length, $width, $height);
        
        if (!$deliveryOptions || empty($deliveryOptions)) {
            return null;
        }

        $minCost = min(array_column($deliveryOptions, 'delivery_sum'));
        return $minCost;
    }

    /**
     * Получить fallback данные для расчета доставки
     */
    private function getFallbackDeliveryData($fromCityCode, $toCityCode)
    {
        // Базовые тарифы CDEK для fallback
        $baseTariffs = [
            [
                'tariff_code' => '136',
                'tariff_name' => 'СДЭК-Экспресс',
                'delivery_sum' => 300,
                'period_min' => 1,
                'period_max' => 2
            ],
            [
                'tariff_code' => '233',
                'tariff_name' => 'СДЭК-Экономичный',
                'delivery_sum' => 200,
                'period_min' => 3,
                'period_max' => 5
            ],
            [
                'tariff_code' => '234',
                'tariff_name' => 'СДЭК-Экономичный до двери',
                'delivery_sum' => 250,
                'period_min' => 3,
                'period_max' => 5
            ]
        ];

        // Корректируем стоимость в зависимости от расстояния между городами
        $distanceMultiplier = $this->getDistanceMultiplier($fromCityCode, $toCityCode);
        
        return array_map(function($tariff) use ($distanceMultiplier) {
            $tariff['delivery_sum'] = round($tariff['delivery_sum'] * $distanceMultiplier);
            return $tariff;
        }, $baseTariffs);
    }

    /**
     * Получить множитель расстояния для расчета стоимости
     */
    private function getDistanceMultiplier($fromCityCode, $toCityCode)
    {
        // Простая логика определения расстояния по кодам городов
        $distanceMap = [
            '44' => ['2' => 1.2, '63' => 1.5, '137' => 1.8, '65' => 2.0], // Москва
            '2' => ['44' => 1.2, '63' => 1.3, '137' => 1.6, '65' => 1.9], // СПб
            '63' => ['44' => 1.5, '2' => 1.3, '137' => 1.2, '65' => 1.4], // Казань
            '137' => ['44' => 1.8, '2' => 1.6, '63' => 1.2, '65' => 1.1], // Екатеринбург
            '65' => ['44' => 2.0, '2' => 1.9, '63' => 1.4, '137' => 1.1], // Новосибирск
        ];

        $from = (string)$fromCityCode;
        $to = (string)$toCityCode;

        if (isset($distanceMap[$from][$to])) {
            return $distanceMap[$from][$to];
        }

        // Если города не найдены в карте расстояний, используем базовый множитель
        return 1.0;
    }

    /**
     * Создать заказ в СДЭК
     */
    public function createOrder($orderData)
    {
        try {
            if (!$this->settings) {
                Log::error('CdekService: No active CDEK settings found');
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ];
            }

            $token = $this->getAccessToken();
            if (!$token) {
                Log::error('CdekService: Failed to get access token for order creation');
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК'
                ];
            }

            // Формируем данные для создания заказа согласно API СДЭК
            
            // Определяем, нужен ли наложенный платеж
            $isCashOnDelivery = isset($orderData['payment_method']) && 
                (stripos($orderData['payment_method'], 'получении') !== false || 
                 stripos($orderData['payment_method'], 'наложенный') !== false);
            
            Log::info('CdekService: Payment method analysis', [
                'payment_method' => $orderData['payment_method'] ?? 'not_set',
                'is_cash_on_delivery' => $isCashOnDelivery,
                'total_amount' => $orderData['total_amount'] ?? 0,
                'subtotal' => $orderData['subtotal'] ?? 0,
                'delivery_cost' => ($orderData['total_amount'] ?? 0) - ($orderData['subtotal'] ?? 0),
                'packages_cost_sum' => array_sum(array_column($orderData['packages'] ?? [], 'cost'))
            ]);
            
            $sdekOrderData = [
                'number' => $orderData['order_number'] ?? 'ORDER_' . time(),
                'tariff_code' => $orderData['tariff_code'],
                'comment' => ($orderData['comment'] ?? '') . ($isCashOnDelivery ? ' (Наложенный платеж: ' . (($orderData['subtotal'] ?? 0) - ($orderData['delivery_cost'] ?? 0)) . ' руб.)' : ''),
                // developer_key - необязательный параметр, используется для идентификации разработчика
                'developer_key' => $this->settings->developer_key ?? '',
                'sender' => [
                    'name' => $this->settings->sender_name ?? '',
                    'company' => $this->settings->sender_company ?? '',
                    'email' => $this->settings->sender_email ?? '',
                    'phones' => [
                        [
                            'number' => $this->settings->sender_phone ?? ''
                        ]
                    ]
                ],
                'recipient' => [
                    'name' => $orderData['customer_name'],
                    'company' => $orderData['customer_company'] ?? '',
                    'email' => $orderData['customer_email'],
                    'phones' => [
                        [
                            'number' => $orderData['customer_phone']
                        ]
                    ]
                ],
                'from_location' => [
                    'address' => $this->getSenderAddress(),
                    'code' => $this->getSenderCityCode()
                ],
                'packages' => array_map(function($package) use ($orderData) {
                        // Определяем, нужен ли наложенный платеж
                        $isCashOnDelivery = isset($orderData['payment_method']) && 
                            (stripos($orderData['payment_method'], 'получении') !== false || 
                             stripos($orderData['payment_method'], 'наложенный') !== false);
                        
                        // Стоимость товара для наложенного платежа
                        $itemCost = $isCashOnDelivery ? ($package['cost'] ?? 0) : 0;
                        $paymentValue = $isCashOnDelivery ? ($package['cost'] ?? 0) : 0;
                        
                        return [
                            'number' => $package['number'] ?? 'PKG_' . time(),
                            'weight' => $package['weight'] ?? 1000,
                            'length' => $package['length'] ?? 10,
                            'width' => $package['width'] ?? 10,
                            'height' => $package['height'] ?? 10,
                            'comment' => $package['comment'] ?? '',
                            'items' => isset($package['items']) ? array_map(function($item) use ($isCashOnDelivery) {
                                return [
                                    'name' => $item['name'] ?? 'Товар',
                                    'ware_key' => $item['ware_key'] ?? 'ITEM_' . time(),
                                    'payment' => [
                                        'value' => $isCashOnDelivery ? ($item['cost'] ?? 0) : 0
                                    ],
                                    'cost' => $isCashOnDelivery ? ($item['cost'] ?? 0) : 0,
                                    'weight' => $item['weight'] ?? 1000,
                                    'amount' => $item['amount'] ?? 1
                                ];
                            }, $package['items']) : [
                                [
                                    'name' => $package['comment'] ?? 'Товар',
                                    'ware_key' => $package['number'] ?? 'ITEM_' . time(),
                                    'payment' => [
                                        'value' => $paymentValue
                                    ],
                                    'cost' => $itemCost,
                                    'weight' => $package['weight'] ?? 1000,
                                    'amount' => 1
                                ]
                            ]
                        ];
                    }, $orderData['packages'] ?? []),
                'services' => $orderData['services'] ?? []
            ];

            // Если выбран ПВЗ, добавляем delivery_point и убираем to_location
            if (isset($orderData['pvz_code']) && $orderData['pvz_code']) {
                $sdekOrderData['delivery_point'] = $orderData['pvz_code'];
                // Убираем to_location при использовании ПВЗ
                unset($sdekOrderData['to_location']);
            } else {
                // Если не ПВЗ, добавляем to_location с адресом
                $sdekOrderData['to_location'] = [
                    'address' => $orderData['delivery_address'],
                    'code' => $orderData['city_code']
                ];
            }



            
            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . '/orders', $sdekOrderData);


            if ($response->successful()) {
                $data = $response->json();
                
                // Проверяем статус заказа через 5 секунд
                $additionalServices = [];
                if (isset($data['entity']['uuid'])) {
                    sleep(5); // Ждем 5 секунд
                    $statusResult = $this->getOrderStatus($data['entity']['uuid']);
                    if ($statusResult['success']) {
                        
                        // Извлекаем информацию о дополнительных услугах
                        if (isset($statusResult['data']['services'])) {
                            Log::info('CdekService: Found services in order status:', $statusResult['data']['services']);
                            foreach ($statusResult['data']['services'] as $service) {
                                Log::info('CdekService: Processing service:', $service);
                                if ($service['code'] === 'INSURANCE') {
                                    $additionalServices[] = [
                                        'name' => 'Страховка за объявленную стоимость',
                                        'cost' => $service['total_sum'],
                                        'description' => 'Дополнительный сбор за объявленную стоимость ' . $service['parameter'] . ' руб.'
                                    ];
                                    Log::info('CdekService: Added insurance service to additional_services');
                                }
                            }
                        } else {
                        }
                    } else {
                        Log::error('CdekService: Failed to get order status:', $statusResult);
                    }
                }
                
                return [
                    'success' => true,
                    'data' => $data,
                    'additional_services' => $additionalServices,
                    'message' => 'Заказ в СДЭК успешно создан'
                ];
            } else {
                $errorData = $response->json();
                Log::error('CdekService: Order creation failed: ' . json_encode($errorData));
                
                return [
                    'success' => false,
                    'error' => $errorData,
                    'message' => $errorData['message'] ?? 'Ошибка при создании заказа в СДЭК'
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Exception during order creation: ' . $e->getMessage());
            Log::error('CdekService: Exception stack: ' . $e->getTraceAsString());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Ошибка при создании заказа в СДЭК: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить статус заказа в СДЭК
     */
    public function getOrderStatus($orderUuid)
    {
        try {
            if (!$this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ];
            }

            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК'
                ];
            }

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->get($this->apiUrl . '/orders/' . $orderUuid);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка при получении статуса заказа'
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting order status: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при получении статуса заказа: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить информацию о страховке для тарифа
     */
    public function getInsuranceInfo($tariffCode, $totalAmount)
    {
        try {
            if (!$this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ];
            }

            // Для наложенного платежа СДЭК автоматически добавляет страховку
            // Рассчитываем примерную стоимость страховки (обычно 0.75% от суммы)
            $insuranceRate = 0.0075; // 0.75%
            $insuranceCost = $totalAmount * $insuranceRate;
            
            // Минимальная стоимость страховки обычно 50 рублей
            $minInsuranceCost = 50;
            $finalInsuranceCost = max(round($insuranceCost), $minInsuranceCost);
            
            return [
                'success' => true,
                'insurance_info' => [
                    'name' => 'Страховка за объявленную стоимость',
                    'cost' => $finalInsuranceCost,
                    'description' => 'Дополнительный сбор за объявленную стоимость ' . number_format($totalAmount, 0, ',', ' ') . ' руб.'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting insurance info: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при получении информации о страховке: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Отменить заказ в СДЭК
     */
    public function cancelOrder($orderUuid)
    {
        try {
            if (!$this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ];
            }

            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК'
                ];
            }

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->post($this->apiUrl . '/orders/' . $orderUuid . '/cancel');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Заказ в СДЭК успешно отменен'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка при отмене заказа'
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Error canceling order: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при отмене заказа: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить полный адрес отправителя
     */
    private function getSenderAddress()
    {
        if (!$this->settings) {
            return '';
        }

        $addressParts = [];
        
        if ($this->settings->sender_city) {
            $addressParts[] = $this->settings->sender_city;
        }
        
        if ($this->settings->sender_street) {
            $street = $this->settings->sender_street;
            if ($this->settings->sender_house) {
                $street .= ', д. ' . $this->settings->sender_house;
            }
            if ($this->settings->sender_flat) {
                $street .= ', кв. ' . $this->settings->sender_flat;
            }
            $addressParts[] = $street;
        }
        
        if ($this->settings->sender_postal_code) {
            $addressParts[] = $this->settings->sender_postal_code;
        }
        
        return implode(', ', $addressParts);
    }

    /**
     * Получить код города отправителя
     */
    private function getSenderCityCode()
    {
        return $this->settings->sender_city_code ?? '';
    }
}