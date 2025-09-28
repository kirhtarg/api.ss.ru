<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\JsonResponse;

class ShopPaymentController extends Controller
{
    /**
     * Получить список активных способов оплаты
     */
    public function index(): JsonResponse
    {
        try {
            $paymentMethods = ShopPaymentMethod::active()
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paymentMethods
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
