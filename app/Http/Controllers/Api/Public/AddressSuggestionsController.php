<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AddressSuggestionsController extends Controller
{
    private $dadataApiKey;
    private $baseUrl = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs';

    public function __construct()
    {
        $this->dadataApiKey = env('DADATA_API_KEY');
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
                    'data' => []
                ]);
            }

            // Если API ключ не настроен, возвращаем тестовые данные
            if (empty($this->dadataApiKey) || $this->dadataApiKey === 'your_dadata_api_key_here') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'value' => $query . ' (тестовый город)',
                            'unrestricted_value' => $query . ' (тестовый город)',
                            'data' => [
                                'city' => $query,
                                'region' => 'Тестовая область',
                                'country' => 'Россия',
                                'postal_code' => '123456',
                            ]
                        ]
                    ]
                ]);
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 15,
                'connect_timeout' => 10
            ])->withHeaders([
                'Authorization' => 'Token ' . $this->dadataApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'country' => 'Россия'
                    ]
                ],
                'from_bound' => ['value' => 'city'],
                'to_bound' => ['value' => 'city']
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
                            'postal_code' => $suggestion['data']['postal_code'] ?? '',
                        ]
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $cities
                ]);
            }

            Log::error('DaData API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка API DaData: ' . $response->status()
            ], 500);

        } catch (\Exception $e) {
            Log::error('AddressSuggestions Controller error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $query
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: ' . $e->getMessage()
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
                    'data' => []
                ]);
            }

            // Если API ключ не настроен, возвращаем тестовые данные
            if (empty($this->dadataApiKey) || $this->dadataApiKey === 'your_dadata_api_key_here') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'value' => $query . ' (тестовая улица)',
                            'unrestricted_value' => $query . ' (тестовая улица)',
                            'data' => [
                                'street' => $query,
                                'street_type' => 'улица',
                                'city' => $city,
                            ]
                        ]
                    ]
                ]);
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 15,
                'connect_timeout' => 10
            ])->withHeaders([
                'Authorization' => 'Token ' . $this->dadataApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'city' => $city
                    ]
                ],
                'from_bound' => ['value' => 'street'],
                'to_bound' => ['value' => 'street']
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
                        ]
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $streets
                ]);
            }

            Log::error('DaData Streets API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
                'city' => $city
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка API DaData: ' . $response->status()
            ], 500);

        } catch (\Exception $e) {
            Log::error('AddressSuggestions Streets Controller error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $query,
                'city' => $city
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: ' . $e->getMessage()
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
            
            if (empty($query) || empty($city) || empty($street)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Если API ключ не настроен, возвращаем тестовые данные
            if (empty($this->dadataApiKey) || $this->dadataApiKey === 'your_dadata_api_key_here') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'value' => $query . ' (тестовый дом)',
                            'unrestricted_value' => $query . ' (тестовый дом)',
                            'data' => [
                                'house' => $query,
                                'street' => $street,
                                'city' => $city,
                            ]
                        ]
                    ]
                ]);
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 15,
                'connect_timeout' => 10
            ])->withHeaders([
                'Authorization' => 'Token ' . $this->dadataApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/suggest/address', [
                'query' => $query,
                'count' => 10,
                'locations' => [
                    [
                        'city' => $city,
                        'street' => $street
                    ]
                ],
                'from_bound' => ['value' => 'house'],
                'to_bound' => ['value' => 'house']
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
                            'street' => $suggestion['data']['street'] ?? '',
                            'city' => $suggestion['data']['city'] ?? '',
                        ]
                    ];
                }, $suggestions);

                return response()->json([
                    'success' => true,
                    'data' => $houses
                ]);
            }

            Log::error('DaData Houses API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
                'city' => $city,
                'street' => $street
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка API DaData: ' . $response->status()
            ], 500);

        } catch (\Exception $e) {
            Log::error('AddressSuggestions Houses Controller error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $query,
                'city' => $city,
                'street' => $street
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }
}
