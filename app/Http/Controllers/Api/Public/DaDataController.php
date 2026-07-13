<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DaDataController extends Controller
{
    private $apiKey;

    private $secretKey;

    private $baseUrl = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs';

    public function __construct()
    {
        $this->apiKey = env('DADATA_API_KEY');
        $this->secretKey = env('DADATA_SECRET_KEY');

        // Если ключи не настроены, используем заглушку
        if (empty($this->apiKey)) {
            $this->apiKey = 'test_key';
        }
    }

    /**
     * Поиск городов
     */
    public function suggestCities(Request $request): JsonResponse
    {
        try {
            $query = $request->get('query', '');

            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Если API ключ не настроен, возвращаем тестовые данные
            if ($this->apiKey === 'test_key') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'value' => $query.' (тестовый город)',
                            'unrestricted_value' => $query.' (тестовый город)',
                            'data' => [
                                'city' => $query,
                                'region' => 'Тестовая область',
                                'country' => 'Россия',
                                'postal_code' => '123456',
                            ],
                        ],
                    ],
                ]);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'country' => '*',
                    ],
                ],
                'from_bound' => ['value' => 'city'],
                'to_bound' => ['value' => 'city'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $suggestions = $data['suggestions'] ?? [];

                $cities = array_map(function ($suggestion) {
                    return [
                        'value' => $suggestion['value'] ?? '',
                        'unrestricted_value' => $suggestion['unrestricted_value'] ?? '',
                        'data' => [
                            'city' => $suggestion['data']['city'] ?? '',
                            'region' => $suggestion['data']['region'] ?? '',
                            'country' => $suggestion['data']['country'] ?? '',
                            'geo_lat' => $suggestion['data']['geo_lat'] ?? null,
                            'geo_lon' => $suggestion['data']['geo_lon'] ?? null,
                            'postal_code' => $suggestion['data']['postal_code'] ?? '',
                        ],
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $cities,
                ]);
            }

            \Log::error('DaData API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка API DaData: '.$response->status(),
            ], 500);

        } catch (\Exception $e) {
            \Log::error('DaData Controller error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $query,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Поиск улиц в городе
     */
    public function suggestStreets(Request $request): JsonResponse
    {
        try {
            $query = $request->get('query', '');
            $city = $request->get('city', '');

            if (empty($query) || strlen($query) < 2 || empty($city)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Если API ключ не настроен, возвращаем тестовые данные
            if ($this->apiKey === 'test_key') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'value' => $query.' (тестовая улица)',
                            'unrestricted_value' => $query.' (тестовая улица)',
                            'data' => [
                                'street' => $query,
                                'street_type' => 'улица',
                                'city' => $city,
                            ],
                        ],
                    ],
                ]);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'city' => $city,
                    ],
                ],
                'from_bound' => ['value' => 'street'],
                'to_bound' => ['value' => 'street'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $suggestions = $data['suggestions'] ?? [];

                $streets = array_map(function ($suggestion) {
                    return [
                        'value' => $suggestion['value'] ?? '',
                        'unrestricted_value' => $suggestion['unrestricted_value'] ?? '',
                        'data' => [
                            'street' => $suggestion['data']['street'] ?? '',
                            'street_type' => $suggestion['data']['street_type'] ?? '',
                            'city' => $suggestion['data']['city'] ?? '',
                        ],
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $streets,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок улиц',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок улиц: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Поиск домов на улице
     */
    public function suggestHouses(Request $request): JsonResponse
    {
        try {
            $query = $request->get('query', '');
            $city = $request->get('city', '');
            $street = $request->get('street', '');

            if (empty($query) || strlen($query) < 1 || empty($city) || empty($street)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'city' => $city,
                        'street' => $street,
                    ],
                ],
                'from_bound' => ['value' => 'house'],
                'to_bound' => ['value' => 'house'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $suggestions = $data['suggestions'] ?? [];

                $houses = array_map(function ($suggestion) {
                    return [
                        'value' => $suggestion['value'] ?? '',
                        'unrestricted_value' => $suggestion['unrestricted_value'] ?? '',
                        'data' => [
                            'house' => $suggestion['data']['house'] ?? '',
                            'block' => $suggestion['data']['block'] ?? '',
                            'street' => $suggestion['data']['street'] ?? '',
                            'city' => $suggestion['data']['city'] ?? '',
                        ],
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $houses,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок домов',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок домов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Полная подсказка адреса
     */
    public function suggestAddress(Request $request): JsonResponse
    {
        try {
            $query = $request->get('query', '');

            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'country' => '*',
                    ],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $suggestions = $data['suggestions'] ?? [];

                $addresses = array_map(function ($suggestion) {
                    return [
                        'value' => $suggestion['value'] ?? '',
                        'unrestricted_value' => $suggestion['unrestricted_value'] ?? '',
                        'data' => [
                            'city' => $suggestion['data']['city'] ?? '',
                            'street' => $suggestion['data']['street'] ?? '',
                            'house' => $suggestion['data']['house'] ?? '',
                            'apartment' => $suggestion['data']['flat'] ?? '',
                            'postal_code' => $suggestion['data']['postal_code'] ?? '',
                            'region' => $suggestion['data']['region'] ?? '',
                            'country' => $suggestion['data']['country'] ?? '',
                            'geo_lat' => $suggestion['data']['geo_lat'] ?? null,
                            'geo_lon' => $suggestion['data']['geo_lon'] ?? null,
                        ],
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $addresses,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок адресов',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок адресов: '.$e->getMessage(),
            ], 500);
        }
    }
}


