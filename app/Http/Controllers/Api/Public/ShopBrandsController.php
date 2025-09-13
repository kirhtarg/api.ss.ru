<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopBrandsController extends Controller
{
    /**
     * Получить список активных брендов для публичного API
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopBrand::where('is_active', true)
                ->orderBy('name', 'asc');

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            $brands = $query->get();

            return response()->json([
                'success' => true,
                'data' => $brands
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении брендов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный бренд по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $brand = ShopBrand::where('id', $id)
                ->where('is_active', true)
                ->first();

            if (!$brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Бренд не найден'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $brand
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении бренда: ' . $e->getMessage()
            ], 500);
        }
    }
}
