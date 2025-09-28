<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\CdekService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CdekController extends Controller
{
    private $cdekService;

    public function __construct(CdekService $cdekService)
    {
        $this->cdekService = $cdekService;
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
        $request->validate([
            'from_city_code' => 'required|string',
            'to_city_code' => 'required|string',
            'weight' => 'nullable|numeric|min:0.1',
            'length' => 'nullable|numeric|min:1',
            'width' => 'nullable|numeric|min:1',
            'height' => 'nullable|numeric|min:1'
        ]);

        $deliveryOptions = $this->cdekService->calculateDelivery(
            $request->from_city_code,
            $request->to_city_code,
            $request->weight,
            $request->length,
            $request->width,
            $request->height
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
    }

    /**
     * Получить минимальную стоимость доставки
     */
    public function getMinDeliveryCost(Request $request): JsonResponse
    {
        $request->validate([
            'from_city_code' => 'required|string',
            'to_city_code' => 'required|string',
            'weight' => 'nullable|numeric|min:0.1',
            'length' => 'nullable|numeric|min:1',
            'width' => 'nullable|numeric|min:1',
            'height' => 'nullable|numeric|min:1'
        ]);

        $minCost = $this->cdekService->getMinDeliveryCost(
            $request->from_city_code,
            $request->to_city_code,
            $request->weight,
            $request->length,
            $request->width,
            $request->height
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