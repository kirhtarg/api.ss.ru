<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\CdekService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CdekController extends Controller
{
    private $cdekService;

    public function __construct(CdekService $cdekService)
    {
        $this->cdekService = $cdekService;
    }

    public function searchCities(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q', '');

            if (mb_strlen($query) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Минимум 2 символа для поиска'
                ], 400);
            }

            $cities = $this->cdekService->getCities($query);

            if (!$cities || !is_array($cities)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить список городов'
                ], 500);
            }

            $formatted = [];
            foreach ($cities as $city) {
                $formatted[] = [
                    'code' => $city['code'] ?? null,
                    'name' => $city['city'] ?? $city['name'] ?? '',
                    'region' => $city['region'] ?? ($city['region_code'] ?? ''),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Exception $e) {
            Log::error('CDEK searchCities error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения городов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить города СДЭК
     */
    public function getCities(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        
        $cities = $this->cdekService->getCities($query);
        
        if ($cities) {
            return response()->json([
                'success' => true,
                'data' => $cities
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось получить список городов'
        ], 500);
    }

    /**
     * Получить пункты выдачи в городе
     */
    public function getPickupPoints(Request $request): JsonResponse
    {
        $cityCode = $request->get('city_code');
        
        if (!$cityCode) {
            return response()->json([
                'success' => false,
                'message' => 'Не указан код города'
            ], 400);
        }

        $points = $this->cdekService->getPickupPoints($cityCode);
        
        if ($points) {
            return response()->json([
                'success' => true,
                'data' => $points
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось получить пункты выдачи'
        ], 500);
    }

    /**
     * Рассчитать стоимость доставки
     */
    public function calculateDelivery(Request $request): JsonResponse
    {
        try {
            // Поддерживаем две структуры данных
            if ($request->has('from') && $request->has('to') && $request->has('packages')) {
                // Новая структура: { from: {code, address}, to: {code, address}, packages: [{weight, length, width, height}] }
                $from = $request->input('from');
                $to = $request->input('to');
                $packages = $request->input('packages', []);
                
                if (empty($packages)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Не указаны параметры посылки'
                    ], 400);
                }
                
                $package = $packages[0]; // Берем первую посылку
                
                $fromCityCode = (int) $from['code'];
                $toCityCode = (int) $to['code'];
                $weight = $package['weight'] / 1000; // Конвертируем граммы в кг
                $length = $package['length'];
                $width = $package['width'];
                $height = $package['height'];
                
                Log::info('CDEK Calculate Request (new format):', [
                    'from' => $from,
                    'to' => $to,
                    'packages' => $packages,
                    'converted' => [
                        'from_city_code' => $fromCityCode,
                        'to_city_code' => $toCityCode,
                        'weight' => $weight,
                        'length' => $length,
                        'width' => $width,
                        'height' => $height
                    ]
                ]);
                
            } else {
                // Старая структура: { from_city_code, to_city_code, weight, length, width, height }
                $request->validate([
                    'from_city_code' => 'required|integer',
                    'to_city_code' => 'required|integer',
                    'weight' => 'nullable|numeric|min:0.1',
                    'length' => 'nullable|numeric|min:1',
                    'width' => 'nullable|numeric|min:1',
                    'height' => 'nullable|numeric|min:1'
                ]);

                $fromCityCode = $request->from_city_code;
                $toCityCode = $request->to_city_code;
                $weight = $request->weight;
                $length = $request->length;
                $width = $request->width;
                $height = $request->height;
                
                Log::info('CDEK Calculate Request (old format):', [
                    'from_city_code' => $fromCityCode,
                    'to_city_code' => $toCityCode,
                    'weight' => $weight,
                    'length' => $length,
                    'width' => $width,
                    'height' => $height
                ]);
            }

            $deliveryOptions = $this->cdekService->calculateDelivery(
                $fromCityCode,
                $toCityCode,
                $weight,
                $length,
                $width,
                $height
            );

            if ($deliveryOptions) {
                return response()->json([
                    'success' => true,
                    'data' => $deliveryOptions
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Не удалось рассчитать стоимость доставки'
            ], 500);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('CDEK Calculate Validation Error:', $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации данных',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('CDEK Calculate Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета доставки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить минимальную стоимость доставки
     */
    public function getMinDeliveryCost(Request $request): JsonResponse
    {
        // Поддерживаем две структуры данных
        if ($request->has('from') && $request->has('to') && $request->has('packages')) {
            // Новая структура: { from: {code, address}, to: {code, address}, packages: [{weight, length, width, height}] }
            $from = $request->input('from');
            $to = $request->input('to');
            $packages = $request->input('packages', []);
            
            if (empty($packages)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны параметры посылки'
                ], 400);
            }
            
            $package = $packages[0]; // Берем первую посылку
            
            $fromCityCode = (int) $from['code'];
            $toCityCode = (int) $to['code'];
            $weight = $package['weight'] / 1000; // Конвертируем граммы в кг
            $length = $package['length'];
            $width = $package['width'];
            $height = $package['height'];
            
        } else {
            // Старая структура: { from_city_code, to_city_code, weight, length, width, height }
            $request->validate([
                'from_city_code' => 'required|integer',
                'to_city_code' => 'required|integer',
                'weight' => 'nullable|numeric|min:0.1',
                'length' => 'nullable|numeric|min:1',
                'width' => 'nullable|numeric|min:1',
                'height' => 'nullable|numeric|min:1'
            ]);

            $fromCityCode = $request->from_city_code;
            $toCityCode = $request->to_city_code;
            $weight = $request->weight;
            $length = $request->length;
            $width = $request->width;
            $height = $request->height;
        }

        $minCost = $this->cdekService->getMinDeliveryCost(
            $fromCityCode,
            $toCityCode,
            $weight,
            $length,
            $width,
            $height
        );

        if ($minCost !== null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'min_cost' => $minCost
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось рассчитать минимальную стоимость доставки'
        ], 500);
    }
}
