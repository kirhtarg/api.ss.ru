<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopDeliveryMethod;
use Illuminate\Http\JsonResponse;

class ShopDeliveryController extends Controller
{
    /**
     * Получить список активных способов доставки
     */
    public function index(): JsonResponse
    {
        try {
            $deliveryMethods = ShopDeliveryMethod::active()
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $deliveryMethods
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов доставки',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
