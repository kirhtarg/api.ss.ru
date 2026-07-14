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
            if (! $this->settings) {
                return null;
            }

            // Получаем токен авторизации
            $token = $this->getAccessToken();
            if (! $token) {
                return null;
            }

            // Используем правильный endpoint из документации СДЭК с параметром city
            $url = $this->apiUrl.'/location/cities?country_codes=RU&city='.rawurlencode($query);

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('CdekService: Unsuccessful response: '.$response->status());

            return null;
        } catch (\Exception $e) {
            Log::error('Ошибка получения городов СДЭК: '.$e->getMessage());
            Log::error('CdekService: Exception stack: '.$e->getTraceAsString());

            return null;
        }
    }

    /**
     * Получить пункты выдачи в городе
     */
    public function getPickupPoints($cityCode)
    {
        try {
            if (! $this->settings) {
                return null;
            }

            // Получаем токен авторизации
            $token = $this->getAccessToken();
            if (! $token) {
                return null;
            }

            // Проверяем, является ли $cityCode уже числовым кодом
            $numericCityCode = $cityCode;
            if (! is_numeric($cityCode)) {
                // Если это не числовой код, получаем его из API СДЭК
                $numericCityCode = $this->getNumericCityCode($cityCode, $token);
                if (! $numericCityCode) {
                    return null;
                }
            }

            // Используем реальный API СДЭК как в папке cdek
            $url = $this->apiUrl.'/deliverypoints?city_code='.$numericCityCode;

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();

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
                                'longitude' => $point['location']['longitude'] ?? $point['longitude'] ?? 0,
                            ],
                            'type' => $point['type'] ?? 'PVZ',
                        ];
                    }

                    return $formattedPoints;
                }

                return $points;
            } else {
                Log::error('CdekService: getPickupPoints API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => $url,
                ]);

                return null;
            }
        } catch (\Exception $e) {
            Log::error('Ошибка получения пунктов выдачи СДЭК: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Получить числовой код города из API СДЭК
     */
    private function getNumericCityCode($cityName, $token)
    {
        try {
            // Маппинг наших кодов на полные названия городов
            $cityMapping = [
                'spb' => 'Санкт-Петербург',
                'moscow' => 'Москва',
                'orenburg' => 'Оренбург',
                'ekaterinburg' => 'Екатеринбург',
            ];

            $searchName = $cityMapping[$cityName] ?? $cityName;

            // Используем правильный endpoint из документации СДЭК с параметром city
            $url = $this->apiUrl.'/location/cities?country_codes=RU&city='.rawurlencode($searchName);

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();

                // Ищем точное совпадение по названию города
                foreach ($data as $city) {
                    if (isset($city['city']) && $city['city'] === $searchName) {
                        $code = $city['code'];

                        return $code;
                    }
                }

                // Если точного совпадения нет, берем первый результат
                if (isset($data[0]['code'])) {
                    $code = $data[0]['code'];

                    return $code;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Рассчитать стоимость доставки
     */
    public function calculateDelivery($fromCityCode, $toCityCode, $weight = null, $length = null, $width = null, $height = null)
    {
        return $this->calculateDeliveryForPackages($fromCityCode, $toCityCode, [[
            'weight' => $weight ?? $this->settings?->default_weight ?? 1,
            'length' => $length ?? $this->settings?->default_length ?? 30,
            'width' => $width ?? $this->settings?->default_width ?? 20,
            'height' => $height ?? $this->settings?->default_height ?? 10,
        ]]);
    }

    public function calculateDeliveryForPackages($fromCityCode, $toCityCode, array $packages)
    {
        if (! $this->settings) {
            Log::warning('CdekService: No active CDEK settings found, using fallback data');

            return $this->getFallbackDeliveryData($fromCityCode, $toCityCode);
        }

        try {
            $requestData = [
                'from_location' => [
                    'code' => (int) $fromCityCode,
                ],
                'to_location' => [
                    'code' => (int) $toCityCode,
                ],
                'packages' => array_map(fn (array $package) => [
                    'weight' => max(1, (int) round((float) ($package['weight'] ?? $this->settings->default_weight ?? 1) * 1000)),
                    'length' => max(1, (int) ceil((float) ($package['length'] ?? $this->settings->default_length ?? 30))),
                    'width' => max(1, (int) ceil((float) ($package['width'] ?? $this->settings->default_width ?? 20))),
                    'height' => max(1, (int) ceil((float) ($package['height'] ?? $this->settings->default_height ?? 10))),
                ], $packages),
                'tariff_codes' => $this->settings->getActiveTariffCodes(),
            ];

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => 30, // Уменьшаем таймаут для расчета доставки
            ])->withHeaders([
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl.'/calculator/tarifflist', $requestData);

            if ($response->successful()) {
                $data = $response->json();
                $result = $this->processDeliveryResponse($data);

                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Ошибка расчета доставки СДЭК: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Получить токен доступа
     */
    public function getAccessToken()
    {
        if (! $this->settings || ! $this->settings->client_id || ! $this->settings->client_secret) {
            Log::error('CdekService: CDEK API keys are not configured or settings are not active.');

            return null;
        }

        // Проверяем кэш токена
        $cacheKey = 'cdek_token_'.$this->settings->client_id;
        $cachedToken = cache()->get($cacheKey);
        if ($cachedToken) {
            return $cachedToken;
        }

        $maxRetries = 3;
        $retryDelay = 2; // секунды

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Используем правильный endpoint для получения токена
                // Параметры передаем в теле запроса (form-data), что надежнее для OAuth2
                $response = Http::asForm()->withOptions([
                    'verify' => $this->sslVerify,
                    'timeout' => $this->timeout,
                ])->post(rtrim($this->apiUrl, '/').'/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => trim($this->settings->client_id),
                    'client_secret' => trim($this->settings->client_secret),
                ]);

                if (! $response->successful()) {
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                    }
                    continue;
                }

                $data = $response->json();
                $token = $data['access_token'] ?? null;
                if ($token) {
                    // Кэшируем токен на 50 минут (токены СДЭК живут 1 час)
                    cache()->put($cacheKey, $token, 3000);

                    return $token;
                }
            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
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
        if (! isset($data['tariff_codes']) || empty($data['tariff_codes'])) {
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
                    'delivery_mode' => $tariff['delivery_mode'] ?? 1,
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

        if (! $deliveryOptions || empty($deliveryOptions)) {
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
                'period_max' => 2,
            ],
            [
                'tariff_code' => '233',
                'tariff_name' => 'СДЭК-Экономичный',
                'delivery_sum' => 200,
                'period_min' => 3,
                'period_max' => 5,
            ],
            [
                'tariff_code' => '234',
                'tariff_name' => 'СДЭК-Экономичный до двери',
                'delivery_sum' => 250,
                'period_min' => 3,
                'period_max' => 5,
            ],
        ];

        // Корректируем стоимость в зависимости от расстояния между городами
        $distanceMultiplier = $this->getDistanceMultiplier($fromCityCode, $toCityCode);

        return array_map(function ($tariff) use ($distanceMultiplier) {
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

        $from = (string) $fromCityCode;
        $to = (string) $toCityCode;

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
            if (! $this->settings) {
                Log::error('CdekService: No active CDEK settings found');

                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
                Log::error('CdekService: Failed to get access token for order creation');

                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ];
            }

            // Формируем данные для создания заказа согласно API СДЭК

            // Определяем, нужен ли наложенный платеж
            $hasCodFlag = ! empty($orderData['cod_enabled']);
            $hasCodByPaymentMethod = isset($orderData['payment_method']) &&
                (stripos($orderData['payment_method'], 'получении') !== false ||
                 stripos($orderData['payment_method'], 'наложенный') !== false);
            $isCashOnDelivery = $hasCodFlag || $hasCodByPaymentMethod;

            $declaredBase = isset($orderData['declared_value'])
                ? (float) $orderData['declared_value']
                : (float) ($orderData['subtotal'] ?? 0);
            $packages = $this->allocateDeclaredValueAcrossPackages(
                $orderData['packages'] ?? [],
                $declaredBase
            );

            $codAmount = $declaredBase;
            if ($isCashOnDelivery && isset($orderData['delivery_recipient_cost']['value'])) {
                $deliveryValue = (float) $orderData['delivery_recipient_cost']['value'];
                if ($deliveryValue > 0) {
                    $codAmount += $deliveryValue;
                }
            }

            // Расчет и добавление наценки
            if (! empty($orderData['surcharge_enabled']) && $isCashOnDelivery) {
                $surchargeValue = (float) ($orderData['surcharge_value'] ?? 0);
                $surchargeType = $orderData['surcharge_type'] ?? 'fixed';
                $surchargeAmount = 0;

                if ($surchargeType === 'fixed') {
                    $surchargeAmount = $surchargeValue;
                } elseif ($surchargeType === 'percent') {
                    $surchargeAmount = ($codAmount * $surchargeValue) / 100;
                }

                $codAmount += ceil($surchargeAmount);
            }

            $sdekOrderData = [
                // Номер заказа в системе интернет-магазина (идентификатор ИМ)
                'number' => $orderData['order_number'] ?? 'ORDER_'.time(),
                'tariff_code' => $orderData['tariff_code'],
                'comment' => ($orderData['comment'] ?? '').($isCashOnDelivery ? ' (Наложенный платеж: '.$codAmount.' руб.)' : ''),
                // developer_key - необязательный параметр, используется для идентификации разработчика
                'developer_key' => $this->settings->developer_key ?? '',
                'sender' => [
                    'name' => $this->settings->sender_name ?? '',
                    'company' => $this->settings->sender_company ?? '',
                    'email' => $this->settings->sender_email ?? '',
                    'phones' => [
                        [
                            'number' => $this->settings->sender_phone ?? '',
                        ],
                    ],
                ],
                'recipient' => [
                    'name' => $orderData['customer_name'],
                    'company' => $orderData['customer_company'] ?? '',
                    'email' => $orderData['customer_email'],
                    'phones' => [
                        [
                            'number' => ! empty($orderData['customer_phone']) ? $orderData['customer_phone'] : '0000000000',
                        ],
                    ],
                ],
                'from_location' => [
                    'address' => $this->getSenderAddress(),
                    'code' => $this->getSenderCityCode(),
                    'postal_code' => $this->settings->sender_postal_code ?: null,
                    'country_code' => $this->settings->sender_country_code ?: 'RU',
                ],
                'packages' => array_map(function ($package) use ($orderData, $isCashOnDelivery, $declaredBase) {

                    $declaredPackageCost = $package['cost'] ?? 0;
                    $paymentValue = $isCashOnDelivery ? $declaredPackageCost : 0;

                    return [
                        'number' => $package['number'] ?? 'PKG_'.time(),
                        'weight' => $package['weight'] ?? 1000,
                        'length' => $package['length'] ?? 10,
                        'width' => $package['width'] ?? 10,
                        'height' => $package['height'] ?? 10,
                        'comment' => $package['comment'] ?? '',
                        'items' => isset($package['items']) ? array_map(function ($item) use ($isCashOnDelivery, $orderData, $declaredBase) {
                            $itemCost = $item['cost'] ?? 0;
                            $itemPaymentValue = $isCashOnDelivery ? $itemCost : 0;
                            if ($isCashOnDelivery && isset($orderData['delivery_recipient_cost']['value'])) {
                                $deliveryValue = (float) $orderData['delivery_recipient_cost']['value'];
                                if ($deliveryValue > 0 && $itemCost > 0 && $declaredBase > 0) {
                                    $amount = max(1, (int) ($item['amount'] ?? 1));
                                    $lineProportion = ($itemCost * $amount) / $declaredBase;
                                    $itemPaymentValue += ($deliveryValue * $lineProportion) / $amount;
                                }
                            }

                            return [
                                'name' => $item['name'] ?? 'Товар',
                                'ware_key' => $item['ware_key'] ?? 'ITEM_'.time(),
                                'payment' => [
                                    'value' => $itemPaymentValue,
                                ],
                                'cost' => $itemCost,
                                'weight' => $item['weight'] ?? 1000,
                                'amount' => $item['amount'] ?? 1,
                            ];
                        }, $package['items']) : [
                            [
                                'name' => $package['comment'] ?? 'Товар',
                                'ware_key' => $package['number'] ?? 'ITEM_'.time(),
                                'payment' => [
                                    'value' => $paymentValue,
                                ],
                                'cost' => $declaredPackageCost,
                                'weight' => $package['weight'] ?? 1000,
                                'amount' => 1,
                            ],
                        ],
                    ];
                }, $packages),
                'services' => $orderData['services'] ?? [],
            ];

            // Если указан "кто оплачивает доставку" — добавляем delivery_recipient_cost
            if (isset($orderData['delivery_recipient_cost']) && is_array($orderData['delivery_recipient_cost'])) {
                $value = isset($orderData['delivery_recipient_cost']['value']) ? (float) $orderData['delivery_recipient_cost']['value'] : null;
                if ($value !== null && $value >= 0) {
                    $sdekOrderData['delivery_recipient_cost'] = [
                        'value' => $value,
                    ];
                }
            }

            // Если выбран ПВЗ, добавляем delivery_point и убираем to_location
            if (isset($orderData['pvz_code']) && $orderData['pvz_code']) {
                $sdekOrderData['delivery_point'] = $orderData['pvz_code'];
                // Убираем to_location при использовании ПВЗ
                unset($sdekOrderData['to_location']);
            } else {
                // Если не ПВЗ, добавляем to_location с адресом
                $sdekOrderData['to_location'] = [
                    'address' => $orderData['delivery_address'],
                    'code' => $orderData['city_code'],
                ];
            }

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl.'/orders', $sdekOrderData);

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
                            foreach ($statusResult['data']['services'] as $service) {
                                if ($service['code'] === 'INSURANCE') {
                                    $additionalServices[] = [
                                        'name' => 'Страховка за объявленную стоимость',
                                        'cost' => $service['total_sum'],
                                        'description' => 'Дополнительный сбор за объявленную стоимость '.$service['parameter'].' руб.',
                                    ];
                                }
                            }
                        }
                    } else {
                        Log::error('CdekService: Failed to get order status:', $statusResult);
                    }
                }

                return [
                    'success' => true,
                    'data' => $data,
                    'additional_services' => $additionalServices,
                    'message' => 'Заказ в СДЭК успешно создан',
                ];
            } else {
                $errorData = $response->json();
                Log::error('CdekService: Order creation failed: '.json_encode($errorData));

                return [
                    'success' => false,
                    'error' => $errorData,
                    'message' => $errorData['message'] ?? 'Ошибка при создании заказа в СДЭК',
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Exception during order creation: '.$e->getMessage());
            Log::error('CdekService: Exception stack: '.$e->getTraceAsString());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Ошибка при создании заказа в СДЭК: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Получить статус заказа в СДЭК
     */
    public function getOrderStatus($orderUuid)
    {
        try {
            if (! $this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ];
            }

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($this->apiUrl.'/orders/'.$orderUuid);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            } else {
                $statusCode = $response->status();
                $responseBody = $response->body();

                // Логируем ошибку для отладки
                Log::warning('CDEK API error', [
                    'status_code' => $statusCode,
                    'response_body' => $responseBody,
                    'order_uuid' => $orderUuid,
                ]);

                // Проверяем, является ли ошибка признаком того, что заказ не найден
                // 404 - заказ не найден
                // Также проверяем текст ответа на наличие признаков "не найден"
                $isNotFound = false;
                if ($statusCode === 404) {
                    $isNotFound = true;
                } elseif ($responseBody) {
                    $lowerBody = strtolower($responseBody);
                    // Проверяем различные варианты сообщений об ошибке "не найден"
                    if (strpos($lowerBody, 'not found') !== false ||
                        strpos($lowerBody, 'не найден') !== false ||
                        strpos($lowerBody, 'not_found') !== false ||
                        strpos($lowerBody, 'order not found') !== false) {
                        $isNotFound = true;
                    }
                }

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении статуса заказа',
                    'not_found' => $isNotFound,
                    'status_code' => $statusCode,
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting order status: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Ошибка при получении статуса заказа: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Получить информацию о страховке для тарифа
     */
    public function getInsuranceInfo($tariffCode, $totalAmount)
    {
        try {
            if (! $this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
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
                    'description' => 'Дополнительный сбор за объявленную стоимость '.number_format($totalAmount, 0, ',', ' ').' руб.',
                ],
            ];

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting insurance info: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Ошибка при получении информации о страховке: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Отменить заказ в СДЭК
     */
    public function cancelOrder($orderUuid)
    {
        try {
            if (! $this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ];
            }

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->post($this->apiUrl.'/orders/'.$orderUuid.'/cancel');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Заказ в СДЭК успешно отменен',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка при отмене заказа',
                ];
            }

        } catch (\Exception $e) {
            Log::error('CdekService: Error canceling order: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Ошибка при отмене заказа: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Получить полный адрес отправителя
     */
    private function getSenderAddress()
    {
        if (! $this->settings) {
            return '';
        }

        $addressParts = [];

        if ($this->settings->sender_city) {
            $addressParts[] = $this->settings->sender_city;
        }

        if ($this->settings->sender_street) {
            $street = $this->settings->sender_street;
            if ($this->settings->sender_house) {
                $street .= ', д. '.$this->settings->sender_house;
            }
            if ($this->settings->sender_flat) {
                $street .= ', кв. '.$this->settings->sender_flat;
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
        if (! $this->settings) {
            return '';
        }

        $cityName = trim((string) ($this->settings->sender_city ?? ''));
        if ($cityName !== '') {
            $cacheKey = 'cdek_sender_city_code_'.md5(mb_strtolower($cityName));
            $cachedCode = cache()->get($cacheKey);
            if ($cachedCode) {
                return (int) $cachedCode;
            }

            try {
                $cities = $this->getCities($cityName);
                if (is_array($cities) && ! empty($cities)) {
                    $normalizedCity = mb_strtolower($cityName);
                    $city = collect($cities)->first(function ($item) use ($normalizedCity) {
                        $name = mb_strtolower((string) ($item['city'] ?? $item['name'] ?? ''));
                        return $name === $normalizedCity;
                    }) ?? $cities[0];

                    if (! empty($city['code'])) {
                        cache()->put($cacheKey, (int) $city['code'], 86400);

                        return (int) $city['code'];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('CdekService: failed to resolve sender city code', [
                    'sender_city' => $cityName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->settings->sender_city_code ? (int) $this->settings->sender_city_code : '';
    }

    /**
     * Получить штрихкод для заказа СДЭК
     *
     * @param  string  $orderUuid  UUID заказа в СДЭК
     * @param  int  $copyCount  Количество копий (по умолчанию 1)
     * @param  string  $format  Формат печати (A4, A5, A6, A7, по умолчанию A4)
     * @param  string  $lang  Язык (RUS, ENG, по умолчанию RUS)
     * @return array Результат с URL для скачивания или ошибкой
     */
    public function getBarcode($orderUuid, $copyCount = 1, $format = 'A4', $lang = 'RUS')
    {
        try {
            if (! $this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ];
            }

            // Сначала создаем запрос на генерацию штрихкода
            $requestData = [
                'orders' => [
                    [
                        'order_uuid' => $orderUuid,
                    ],
                ],
                'copy_count' => $copyCount,
                'format' => $format,
                'lang' => $lang,
            ];

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl.'/print/barcodes', $requestData);

            if ($response->status() === 202) {
                // Запрос принят, получаем UUID запроса
                $data = $response->json();
                $requestUuid = $data['entity']['uuid'] ?? null;

                if ($requestUuid) {
                    // Ждем немного и проверяем статус
                    sleep(2);

                    // Получаем статус генерации
                    $statusResponse = Http::withOptions([
                        'verify' => $this->sslVerify,
                        'timeout' => $this->timeout,
                    ])->withHeaders([
                        'Authorization' => 'Bearer '.$token,
                    ])->get($this->apiUrl.'/print/barcodes/'.$requestUuid);

                    if ($statusResponse->successful()) {
                        $statusData = $statusResponse->json();

                        // Проверяем статус
                        if (isset($statusData['entity']['statuses'])) {
                            $statuses = $statusData['entity']['statuses'];
                            $lastStatus = end($statuses);

                            if ($lastStatus && $lastStatus['code'] === 'READY') {
                                // Штрихкод готов, возвращаем URL для скачивания
                                $pdfUrl = $this->apiUrl.'/print/barcodes/'.$requestUuid.'.pdf';

                                return [
                                    'success' => true,
                                    'url' => $pdfUrl,
                                    'request_uuid' => $requestUuid,
                                    'message' => 'Штрихкод готов к скачиванию',
                                ];
                            } elseif ($lastStatus && $lastStatus['code'] === 'PROCESSING') {
                                // Еще обрабатывается
                                return [
                                    'success' => false,
                                    'message' => 'Штрихкод еще генерируется, попробуйте позже',
                                    'status' => 'processing',
                                    'request_uuid' => $requestUuid,
                                ];
                            }
                        }
                    }

                    // Если статус не готов, возвращаем URL для прямого скачивания (может работать)
                    $pdfUrl = $this->apiUrl.'/print/barcodes/'.$requestUuid.'.pdf';

                    return [
                        'success' => true,
                        'url' => $pdfUrl,
                        'request_uuid' => $requestUuid,
                        'message' => 'Ссылка на скачивание штрихкода',
                    ];
                }
            }

            // Если не получилось через POST, пробуем прямой GET запрос
            $directUrl = $this->apiUrl.'/print/orders/'.$orderUuid;
            $directResponse = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->get($directUrl);

            if ($directResponse->successful()) {
                $directData = $directResponse->json();
                if (isset($directData['entity']['url'])) {
                    return [
                        'success' => true,
                        'url' => $directData['entity']['url'],
                        'message' => 'Ссылка на скачивание штрихкода',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Не удалось получить штрихкод: '.($response->body() ?? 'Неизвестная ошибка'),
            ];

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting barcode: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Ошибка при получении штрихкода: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Получить накладную для заказа СДЭК
     *
     * @param  string  $orderUuid  UUID заказа в СДЭК
     * @param  int  $copyCount  Количество копий (по умолчанию 2)
     * @param  string  $type  Тип формы (по умолчанию tpl_russia)
     * @return array Результат с URL для скачивания или ошибкой
     */
    private function allocateDeclaredValueAcrossPackages(array $packages, float $declaredValue): array
    {
        $locations = [];
        $totalBaseCents = 0;

        foreach ($packages as $packageIndex => $package) {
            foreach (($package['items'] ?? []) as $itemIndex => $item) {
                $amount = max(1, (int) ($item['amount'] ?? 1));
                $lineBaseCents = max(0, (int) round((float) ($item['cost'] ?? 0) * $amount * 100));
                $locations[] = [$packageIndex, $itemIndex, $lineBaseCents, $amount];
                $totalBaseCents += $lineBaseCents;
            }
        }

        $remainingTarget = max(0, (int) round($declaredValue * 100));
        $remainingBase = $totalBaseCents;
        $lastIndex = array_key_last($locations);

        foreach ($locations as $position => [$packageIndex, $itemIndex, $lineBaseCents, $amount]) {
            $lineTarget = $position === $lastIndex || $remainingBase <= 0
                ? $remainingTarget
                : min($remainingTarget, (int) round($remainingTarget * ($lineBaseCents / $remainingBase)));

            $packages[$packageIndex]['items'][$itemIndex]['cost'] = round(($lineTarget / 100) / $amount, 2);
            $remainingTarget -= $lineTarget;
            $remainingBase -= $lineBaseCents;
        }

        foreach ($packages as &$package) {
            $package['cost'] = round(collect($package['items'] ?? [])->sum(function ($item) {
                return (float) ($item['cost'] ?? 0) * max(1, (int) ($item['amount'] ?? 1));
            }), 2);
        }
        unset($package);

        return $packages;
    }

    public function getWaybill($orderUuid, $copyCount = 2, $type = 'tpl_russia')
    {
        try {
            if (! $this->settings) {
                return [
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
                return [
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа СДЭК',
                ];
            }

            // Сначала получаем информацию о заказе, чтобы получить cdek_number
            $orderInfo = $this->getOrderStatus($orderUuid);

            if (! $orderInfo['success']) {
                // Если не удалось получить информацию, пробуем прямой URL
                $pdfUrl = $this->apiUrl.'/print/orders/'.$orderUuid.'.pdf';

                return [
                    'success' => true,
                    'url' => $pdfUrl,
                    'message' => 'Ссылка на скачивание накладной',
                ];
            }

            $orderData = $orderInfo['data'] ?? [];
            $cdekNumber = $orderData['entity']['cdek_number'] ?? 0;

            // Согласно документации, накладную нужно генерировать через POST запрос
            $requestData = [
                'orders' => [
                    [
                        'order_uuid' => $orderUuid,
                        'cdek_number' => $cdekNumber,
                    ],
                ],
                'copy_count' => $copyCount,
                'type' => $type,
            ];

            $response = Http::withOptions([
                'verify' => $this->sslVerify,
                'timeout' => $this->timeout,
            ])->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl.'/print/orders', $requestData);

            if ($response->status() === 202) {
                // Запрос принят, получаем UUID запроса
                $data = $response->json();
                $requestUuid = $data['entity']['uuid'] ?? null;

                if ($requestUuid) {
                    // Ждем немного и проверяем статус
                    sleep(2);

                    // Получаем статус генерации
                    $statusResponse = Http::withOptions([
                        'verify' => $this->sslVerify,
                        'timeout' => $this->timeout,
                    ])->withHeaders([
                        'Authorization' => 'Bearer '.$token,
                    ])->get($this->apiUrl.'/print/orders/'.$requestUuid);

                    if ($statusResponse->successful()) {
                        $statusData = $statusResponse->json();

                        // Проверяем статус
                        if (isset($statusData['entity']['statuses'])) {
                            $statuses = $statusData['entity']['statuses'];
                            $lastStatus = end($statuses);

                            if ($lastStatus && $lastStatus['code'] === 'READY') {
                                // Накладная готова, возвращаем URL для скачивания
                                $pdfUrl = $this->apiUrl.'/print/orders/'.$requestUuid.'.pdf';

                                return [
                                    'success' => true,
                                    'url' => $pdfUrl,
                                    'request_uuid' => $requestUuid,
                                    'message' => 'Накладная готова к скачиванию',
                                ];
                            } elseif ($lastStatus && $lastStatus['code'] === 'PROCESSING') {
                                // Еще обрабатывается
                                return [
                                    'success' => false,
                                    'message' => 'Накладная еще генерируется, попробуйте позже',
                                    'status' => 'processing',
                                    'request_uuid' => $requestUuid,
                                ];
                            }
                        }
                    }

                    // Если статус не готов, возвращаем URL для прямого скачивания (может работать)
                    $pdfUrl = $this->apiUrl.'/print/orders/'.$requestUuid.'.pdf';

                    return [
                        'success' => true,
                        'url' => $pdfUrl,
                        'request_uuid' => $requestUuid,
                        'message' => 'Ссылка на скачивание накладной',
                    ];
                }
            }

            // Если POST не сработал, пробуем прямой GET запрос
            $directUrl = $this->apiUrl.'/print/orders/'.$orderUuid.'.pdf';

            return [
                'success' => true,
                'url' => $directUrl,
                'message' => 'Ссылка на скачивание накладной',
            ];

        } catch (\Exception $e) {
            Log::error('CdekService: Error getting waybill: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Ошибка при получении накладной: '.$e->getMessage(),
            ];
        }
    }
}
