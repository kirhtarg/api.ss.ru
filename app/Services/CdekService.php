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
            return null;
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
}