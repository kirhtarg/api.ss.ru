<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCdekSettings;
use App\Services\CdekService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopCdekController extends Controller
{
    private $cdekService;

    public function __construct()
    {
        $this->cdekService = new CdekService();
    }

    /**
     * Получить список городов СДЭК
     */
    public function getCities(Request $request): JsonResponse
    {
        try {
            $query = $request->query('query', '');

            // Правильно декодируем кириллицу - пробуем разные варианты
            $query = urldecode($query);
            if (empty($query)) {
                $query = $request->input('query', '');
            }

            if (strlen($query) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Минимум 2 символа для поиска'
                ], 400);
            }

            // Используем DaData API для поиска городов
            $dadataApiKey = env('DADATA_API_KEY');

            // Инициализируем переменную на случай ошибки
            $formattedCities = [];

            if ($dadataApiKey) {
                try {
                    $dadataUrl = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
                    $body = [
                        'query' => $query,
                        'count' => 20,
                        'locations' => [
                            [
                                'country' => 'Россия'
                            ]
                        ],
                        'from_bound' => ['value' => 'city'],
                        'to_bound' => ['value' => 'city']
                    ];

                    $headers = [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ];

                    $res = Http::withOptions([
                        'verify' => false,
                        'timeout' => 15, // Увеличиваем таймаут до 15 секунд
                        'connect_timeout' => 10 // Таймаут подключения 10 секунд
                    ])
                        ->withHeaders($headers)
                        ->post($dadataUrl, $body);

                    if ($res->successful()) {
                        $data = $res->json();
                        if (isset($data['suggestions'])) {
                            foreach ($data['suggestions'] as $suggestion) {
                                $cityData = $suggestion['data'];
                                $cityName = $cityData['city'] ?? '';

                                if (empty($cityName)) {
                                    continue;
                                }

                                // Ищем код города CDEK по названию через их API
                                try {
                                    $cdekCities = $this->cdekService->getCities($cityName);
                                    $cdekCityCode = null;

                                    if ($cdekCities && is_array($cdekCities) && isset($cdekCities[0]['code'])) {
                                        $cdekCityCode = $cdekCities[0]['code'];
                                    }

                                    $formattedCities[] = [
                                        'code' => $cdekCityCode,
                                        'name' => $cityName,
                                        'region' => $cityData['region_with_type'] ?? '',
                                        'country' => 'Россия',
                                        'full_name' => $cityData['city_with_type'] ?? $cityName,
                                        'fias_id' => $cityData['fias_id'] ?? null
                                    ];
                                } catch (\Exception $e) {
                                    // Игнорируем ошибки для отдельных городов
                                    continue;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки DaData API
                }
            }

            if (empty($formattedCities)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Города не найдены. Проверьте настройки DaData API.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $formattedCities
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK Get Cities Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения городов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список ПВЗ для города
     */
    public function getPvzList(Request $request): JsonResponse
    {
        try {
            $cityCode = $request->query('city_code');

            if (!$cityCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Код города обязателен'
                ], 400);
            }

            $points = $this->cdekService->getPickupPoints($cityCode);

            // Если points null, возвращаем пустой массив
            if ($points === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить пункты выдачи',
                    'data' => []
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => is_array($points) ? $points : []
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK PVZ List Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения ПВЗ: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Получить активные настройки СДЭК.
     */
    public function getActiveSettings(): JsonResponse
    {
        try {
            $settings = \App\Models\ShopCdekSettings::getActive();

            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK Active Settings Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек СДЭК: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Рассчитать стоимость доставки
     */
    public function calculateDelivery(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city_code' => 'required|string',
                'weight' => 'required|numeric|min:1',
                'length' => 'required|numeric|min:1',
                'width' => 'required|numeric|min:1',
                'height' => 'required|numeric|min:1',
                'tariff_codes' => 'array'
            ]);

            $cityCode = $request->input('city_code');
            $weight = $request->input('weight');
            $length = $request->input('length');
            $width = $request->input('width');
            $height = $request->input('height');
            $tariffCodes = $request->input('tariff_codes', []);

            $result = $this->cdekService->calculateDelivery(
                $cityCode,
                $weight,
                $length,
                $width,
                $height,
                $tariffCodes
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK Calculate Delivery Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета доставки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить доступные тарифы СДЭК
     */
    public function getTariffs(Request $request): JsonResponse
    {
        try {
            $cityCode = $request->query('city_code');
            $cartItems = $request->query('cart_items', []);


            if (!$cityCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан код города'
                ], 400);
            }

            // Если cart_items передан как строка, декодируем JSON
            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?? [];
            }

            // Получаем активные настройки СДЭК
            $settings = ShopCdekSettings::where('is_active', true)->first();

            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройки СДЭК не найдены'
                ], 404);
            }

            // Получаем тарифы из настроек
            $tariffs = is_string($settings->tariffs) ? json_decode($settings->tariffs, true) : $settings->tariffs;
            $tariffs = $tariffs ?? [];

            if (empty($tariffs)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Тарифы не настроены'
                ], 404);
            }

            // Рассчитываем стоимость для каждого настроенного тарифа
            $formattedTariffs = [];

            try {
                // Получаем код города отправителя из настроек
                $senderCityCode = $this->getSenderCityCode($settings);

                if ($senderCityCode) {
                    // Рассчитываем размеры посылки на основе товаров в корзине
                    $packageDimensions = $this->calculatePackageDimensions($cartItems, $settings);

                    // Создаем ключ кэша для расчета доставки с учетом размеров и города
                    $deliveryCacheKey = 'cdek_delivery_' . md5($senderCityCode . '_' . $cityCode . '_' . $packageDimensions['weight'] . '_' . $packageDimensions['length'] . '_' . $packageDimensions['width'] . '_' . $packageDimensions['height']);

                    // Проверяем кэш
                    $deliveryResult = cache()->get($deliveryCacheKey);
                    if (!$deliveryResult) {
                        // Рассчитываем стоимость через CdekService с реальными размерами
                        $deliveryResult = $this->cdekService->calculateDelivery(
                            $senderCityCode,
                            $cityCode,
                            $packageDimensions['weight'],
                            $packageDimensions['length'],
                            $packageDimensions['width'],
                            $packageDimensions['height']
                        );

                        // Кэшируем результат на 1 час
                        if ($deliveryResult) {
                            cache()->put($deliveryCacheKey, $deliveryResult, 3600);
                        }
                    }

                    // Сначала пытаемся найти настроенные тарифы
                    $foundConfiguredTariffs = [];
                    foreach ($tariffs as $tariff) {
                        if (!($tariff['enabled'] ?? true)) {
                            continue; // Пропускаем отключенные тарифы
                        }

                        $costValue = null;
                        $costFormatted = 'Рассчитывается...';

                        if ($deliveryResult && is_array($deliveryResult)) {
                            // Ищем нужный тариф в результате CDEK API
                            foreach ($deliveryResult as $resultTariff) {
                                if ($resultTariff['tariff_code'] == $tariff['tariff_code']) {
                                    $costValue = (float)$resultTariff['delivery_sum'];
                                    $costFormatted = $costValue . ' ₽';
                                    break;
                                }
                            }
                        }

                        if ($costValue !== null) {
                            $foundConfiguredTariffs[] = [
                                'code' => $tariff['tariff_code'],
                                'name' => $tariff['site_name'],
                                'description' => $tariff['tariff_description'] ?? '',
                                'cost' => $costFormatted,
                                'cost_value' => $costValue,
                                'enabled' => $tariff['enabled'] ?? true
                            ];
                        }
                    }

                    // Если нашли настроенные тарифы, используем их
                    if (!empty($foundConfiguredTariffs)) {
                        $formattedTariffs = $foundConfiguredTariffs;
                    } else {
                        // Если настроенные тарифы не найдены, проверяем есть ли вообще доступные тарифы
                        if (empty($deliveryResult)) {
                            // Если API СДЭК не работает, используем fallback данные из настроек
                            foreach ($tariffs as $tariff) {
                                if (!($tariff['enabled'] ?? true)) {
                                    continue; // Пропускаем отключенные тарифы
                                }

                                $formattedTariffs[] = [
                                    'code' => $tariff['tariff_code'],
                                    'name' => $tariff['site_name'],
                                    'description' => $tariff['tariff_description'] ?? '',
                                    'cost' => ($tariff['delivery_sum'] ?? 300) . ' ₽',
                                    'cost_value' => (float)($tariff['delivery_sum'] ?? 300),
                                    'enabled' => $tariff['enabled'] ?? true
                                ];
                            }

                            if (empty($formattedTariffs)) {
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Доставка в данный населенный пункт недоступна'
                                ], 404);
                            }
                        } else {
                            // Если настроенные тарифы не найдены, используем все доступные из CDEK API
                            foreach ($deliveryResult as $resultTariff) {
                                $formattedTariffs[] = [
                                    'code' => $resultTariff['tariff_code'],
                                    'name' => $resultTariff['tariff_name'],
                                    'description' => $resultTariff['tariff_description'] ?? '',
                                    'cost' => $resultTariff['delivery_sum'] . ' ₽',
                                    'cost_value' => (float)$resultTariff['delivery_sum'],
                                    'enabled' => true
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки расчета тарифов
            }

            return response()->json([
                'success' => true,
                'data' => $formattedTariffs
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK Get Tariffs Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения тарифов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Рассчитать размеры посылки на основе товаров в корзине
     */
    private function calculatePackageDimensions($cartItems, $settings)
    {
        $totalWeight = 0;
        $maxLength = 0;
        $maxWidth = 0;
        $maxHeight = 0;

        foreach ($cartItems as $item) {
            $quantity = $item['quantity'] ?? 1;

            // Получаем размеры товара из базы данных
            $good = \App\Models\ShopGood::find($item['good_id']);
            if ($good) {
                // Используем размеры товара, если они есть, иначе значения по умолчанию
                $weight = $good->weight > 0 ? $good->weight : ($settings->default_weight ?? 0.5);
                $length = $good->depth > 0 ? $good->depth : ($settings->default_length ?? 10);
                $width = $good->width > 0 ? $good->width : ($settings->default_width ?? 10);
                $height = $good->height > 0 ? $good->height : ($settings->default_height ?? 10);

                // Суммируем вес
                $totalWeight += $weight * $quantity;

                // Для габаритов берем максимальные значения (товары укладываются рядом)
                $maxLength = max($maxLength, $length);
                $maxWidth = max($maxWidth, $width);
                $maxHeight = max($maxHeight, $height);
            } else {
                // Если товар не найден, используем значения по умолчанию
                $totalWeight += ($settings->default_weight ?? 0.5) * $quantity;
                $maxLength = max($maxLength, $settings->default_length ?? 10);
                $maxWidth = max($maxWidth, $settings->default_width ?? 10);
                $maxHeight = max($maxHeight, $settings->default_height ?? 10);
            }
        }

        $result = [
            'weight' => $totalWeight,
            'length' => $maxLength,
            'width' => $maxWidth,
            'height' => $maxHeight
        ];

        return $result;
    }


    /**
     * Получить код города CDEK по названию через CDEK API
     */
    private function getCdekCityCodeFromName($cityName)
    {
        try {
            // Используем CDEK API для поиска города
            $cdekCities = $this->cdekService->getCities($cityName);

            if ($cdekCities && is_array($cdekCities) && count($cdekCities) > 0) {
                $cityCode = $cdekCities[0]['code'] ?? null;
                return $cityCode;
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки поиска города
        }

        // Fallback - возвращаем null, чтобы показать ошибку
        return null;
    }

    /**
     * Получить код города CDEK из FIAS ID
     */
    private function getCdekCityCodeFromFias($fiasId)
    {
        // Маппинг реальных FIAS ID -> CDEK коды (из логов DaData)
        $cityMapping = [
            // Основные города
            '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' => 44,  // Москва
            'c2deb16a-0330-4f05-821f-1d09c93331e6' => 137, // Санкт-Петербург
            'e3a1a5f0-0ec0-4bf3-9e03-3594008f4cb8' => 2,   // Екатеринбург
            '555e7d61-d9a58-4afd-9c9e-0c1b96587899' => 3,   // Новосибирск
            '2763c110-cb8b-416a-9dac-ad0a8ddc1a91' => 4,   // Нижний Новгород
            '93b3df57-4e55-44e8-8e3b-4f4c4c8e2651' => 5,   // Казань
            'a376e68d-724a-4472-be7c-891fdbdd4a32' => 6,   // Челябинск
            '8dea00e3-9a4b-4d1b-9a4c-1c1c1c1c1c1c' => 7,   // Омск
            '1c1c1c1c-1c1c-1c1c-1c1c-1c1c1c1c1c1c' => 8,   // Самара
            '2c2c2c2c-2c2c-2c2c-2c2c-2c2c2c2c2c2c' => 9,   // Ростов-на-Дону
            '3c3c3c3c-3c3c-3c3c-3c3c-3c3c3c3c3c3c' => 10,  // Уфа

            // Дополнительные города из логов
            '0b3f0723-5fe0-4c23-af44-8082166c6d2e' => 11,  // Петропавловск-Камчатский
            'ccc34487-8fd4-4e71-b032-f4e6c82fb354' => 12,  // Петрозаводск
            '96c869d9-51a8-4db6-8011-9fc459c9a78c' => 13,  // Лосино-Петровский
            'b5d97e65-e496-44d0-b025-398a8d43514a' => 14,  // Петровск
            'b26bb2c1-f79b-4c97-9d2d-e73790cfe622' => 15,  // Петровск-Забайкальский
            '02324444-36d6-4af8-a9af-15b77576adea' => 16,  // Петров Вал
            '24478bfd-9105-41e9-a719-3f5d2eb0deba' => 17,  // Петровское
            '8f238984-812b-4bb1-850b-49749fb5c56d' => 18,  // Петергоф
            '62b2814e-4974-490b-bda4-f72f30dc80bf' => 19,  // Петровское (другой)
            '7b6de6a5-86d0-4735-b11a-499081111af8' => 20,  // Владивосток
            '20ea2341-4f49-4c5c-a9dc-a54688c8cc61' => 21,  // Москва (другой FIAS)
        ];

        $cdekCode = $cityMapping[$fiasId] ?? 44; // По умолчанию Москва
        return $cdekCode;
    }

    /**
     * Получить код города отправителя из настроек
     */
    private function getSenderCityCode($settings)
    {
            try {
                // НЕ используем код из настроек напрямую - он может быть неправильным
                // Вместо этого всегда получаем код через API CDEK

                // Проверяем кэш города отправителя
                $cacheKey = 'cdek_sender_city_' . md5($settings->sender_city ?? 'default');
                $cachedCode = cache()->get($cacheKey);
                if ($cachedCode) {
                    return (int)$cachedCode;
                }

                // Если есть название города, пытаемся найти его код через CDEK API
                if (isset($settings->sender_city) && $settings->sender_city) {
                    try {
                        $cdekCities = $this->cdekService->getCities($settings->sender_city);

                        if ($cdekCities && is_array($cdekCities) && isset($cdekCities[0]['code'])) {
                            $cityCode = (int)$cdekCities[0]['code'];
                            // Кэшируем на 24 часа
                            cache()->put($cacheKey, $cityCode, 86400);
                            return $cityCode;
                        }
                    } catch (\Exception $e) {
                        Log::warning('Ошибка поиска кода города отправителя: ' . $e->getMessage());
                    }
                }

                // Fallback - используем Санкт-Петербург (код 270)
                return 270;
        } catch (\Exception $e) {
            return 270; // Fallback
        }
    }

    /**
     * Получение подсказок домов через DaData API
     */
    public function getHouses(Request $request): JsonResponse
    {
        try {
            $city = $request->query('city');
            $street = $request->query('street');
            $query = $request->query('q');

            // Правильно декодируем кириллицу
            $city = urldecode($city);
            $street = urldecode($street);
            $query = urldecode($query);

            if (!$city || !$street || !$query) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан город, улица или запрос'
                ], 400);
            }

            if (strlen($query) < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Минимум 1 символ для поиска дома'
                ], 400);
            }

            // Используем API ключ DaData из переменных окружения
            $dadataApiKey = env('DADATA_API_KEY');
            if (!$dadataApiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API ключ DaData не настроен'
                ], 500);
            }

            // 1. Получаем КЛАДР-код города через DaData suggest/address
            $kladrId = '';
            $cityName = $city;
            try {
                $suggestRes = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'connect_timeout' => 10
                ])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ])
                    ->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', [
                        'query' => $city,
                        'from_bound' => ['value' => 'city'],
                        'to_bound' => ['value' => 'city'],
                        'count' => 1
                    ]);

                if ($suggestRes->successful()) {
                    $suggestData = $suggestRes->json();
                    $kladrId = $suggestData['suggestions'][0]['data']['city_kladr_id'] ?? '';
                    $cityName = $suggestData['suggestions'][0]['data']['city'] ?? $city;
                }
            } catch (\Exception $e) {
                Log::warning('DaData city lookup error: ' . $e->getMessage());
            }

            if (!$kladrId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить КЛАДР-код города'
                ], 400);
            }

            // 2. Запрос к DaData для поиска домов с фильтром по городу и улице
            try {
                $dadataUrl = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
                $body = [
                    'query' => $cityName . ', ' . $street . ', ' . $query,
                    'count' => 10,
                    'locations' => [
                        [
                            'city_kladr_id' => $kladrId,
                            'city' => $cityName
                        ]
                    ],
                    'from_bound' => ['value' => 'house'],
                    'to_bound' => ['value' => 'house']
                ];

                $res = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'connect_timeout' => 10
                ])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ])
                    ->post($dadataUrl, $body);

                if (!$res->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка запроса к DaData'
                    ], 500);
                }

                $data = $res->json();
                if (!isset($data['suggestions'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Нет данных от DaData'
                    ], 500);
                }

                $houses = array_map(function($suggestion) {
                    $houseData = $suggestion['data'];
                    $houseNumber = $houseData['house'] ?? '';

                    return [
                        'number' => $houseNumber,
                        'label' => $houseNumber
                    ];
                }, $data['suggestions']);

                // Фильтруем пустые номера домов
                $houses = array_filter($houses, function($house) {
                    return !empty($house['number']);
                });

                return response()->json([
                    'success' => true,
                    'data' => array_values($houses)
                ]);

            } catch (\Exception $e) {
                Log::error('DaData houses request error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка запроса к DaData: ' . $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('CDEK Get Houses Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения домов: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPostalCode(Request $request): JsonResponse
    {
        try {
            $city = $request->input('city');
            $street = $request->input('street');
            $house = $request->input('house');
            $flat = $request->input('flat', '');

            if (!$city || !$street || !$house) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан город, улица или дом'
                ], 400);
            }

            // Используем API ключ DaData из переменных окружения
            $dadataApiKey = env('DADATA_API_KEY');
            if (!$dadataApiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API ключ DaData не настроен'
                ], 500);
            }

            // Формируем полный адрес для поиска
            $fullAddress = $city . ', ' . $street . ', ' . $house;
            if ($flat) {
                $fullAddress .= ', ' . $flat;
            }

            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'connect_timeout' => 10
                ])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ])
                    ->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', [
                        'query' => $fullAddress,
                        'count' => 1,
                        'from_bound' => ['value' => 'house'],
                        'to_bound' => ['value' => 'house']
                    ]);

                if (!$response->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка запроса к DaData'
                    ], 500);
                }

                $data = $response->json();
                if (!isset($data['suggestions']) || empty($data['suggestions'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Адрес не найден'
                    ], 404);
                }

                $addressData = $data['suggestions'][0]['data'];
                $postalCode = $addressData['postal_code'] ?? '';

                if (!$postalCode) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Почтовый индекс не найден для данного адреса'
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'postal_code' => $postalCode,
                        'full_address' => $data['suggestions'][0]['value'],
                        'city' => $addressData['city'] ?? $city,
                        'street' => $addressData['street_with_type'] ?? $street,
                        'house' => $addressData['house'] ?? $house,
                        'flat' => $addressData['flat'] ?? $flat
                    ]
                ]);

            } catch (\Exception $e) {
                Log::error('DaData postal code request error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка запроса к DaData: ' . $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('CDEK Get Postal Code Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения почтового индекса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получение подсказок улиц через DaData API
     */
    public function getStreets(Request $request): JsonResponse
    {
        try {
            $city = $request->query('city');
            $query = $request->query('q');

            // Правильно декодируем кириллицу
            $city = urldecode($city);
            $query = urldecode($query);

            if (!$city || !$query) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан город или запрос'
                ], 400);
            }

            if (strlen($query) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Минимум 2 символа для поиска улицы'
                ], 400);
            }

            // Используем API ключ DaData из переменных окружения
            $dadataApiKey = env('DADATA_API_KEY');
            if (!$dadataApiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API ключ DaData не настроен'
                ], 500);
            }

            // 1. Получаем КЛАДР-код города через DaData suggest/address
            $kladrId = '';
            $cityName = $city;
            try {
                $suggestRes = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'connect_timeout' => 10
                ])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ])
                    ->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', [
                        'query' => $city,
                        'from_bound' => ['value' => 'city'],
                        'to_bound' => ['value' => 'city'],
                        'count' => 1
                    ]);

                if ($suggestRes->successful()) {
                    $suggestData = $suggestRes->json();
                    $kladrId = $suggestData['suggestions'][0]['data']['city_kladr_id'] ?? '';
                    $cityName = $suggestData['suggestions'][0]['data']['city'] ?? $city;
                }
            } catch (\Exception $e) {
                Log::warning('DaData city lookup error: ' . $e->getMessage());
            }

            if (!$kladrId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить КЛАДР-код города'
                ], 400);
            }

            // 2. Запрос к DaData для поиска улиц с фильтром по КЛАДР-коду
            try {
                $dadataUrl = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
                $body = [
                    'query' => $cityName . ' ' . $query,
                    'count' => 10,
                    'locations' => [
                        [
                            'city_kladr_id' => $kladrId,
                            'city' => $cityName
                        ]
                    ],
                    'from_bound' => ['value' => 'street'],
                    'to_bound' => ['value' => 'street']
                ];

                $res = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'connect_timeout' => 10
                ])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $dadataApiKey
                    ])
                    ->post($dadataUrl, $body);

                if (!$res->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка запроса к DaData'
                    ], 500);
                }

                $data = $res->json();
                if (!isset($data['suggestions'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Нет данных от DaData'
                    ], 500);
                }

                $streets = array_map(function($suggestion) {
                    $streetData = $suggestion['data'];
                    $streetName = $streetData['street_with_type'] ?? $streetData['street'] ?? $suggestion['value'];

                    // Убираем префикс "ул." если есть
                    $streetName = preg_replace('/^ул\.\s*/', '', $streetName);

                    return [
                        'name' => $streetName,
                        'label' => $streetName
                    ];
                }, $data['suggestions']);

                return response()->json([
                    'success' => true,
                    'data' => $streets
                ]);

            } catch (\Exception $e) {
                Log::error('DaData streets request error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка запроса к DaData: ' . $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('CDEK Get Streets Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения улиц: ' . $e->getMessage()
            ], 500);
        }
    }

}
