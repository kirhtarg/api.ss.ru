<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopCategoriesController extends Controller
{
    /**
     * Получить список активных категорий для публичного API
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopCategory::with('parent')
                ->where('is_active', true)
                ->ordered();

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            $categories = $query->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретную категорию по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $category = ShopCategory::with('parent')
                ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категории: ' . $e->getMessage()
            ], 500);
        }
    }
}
