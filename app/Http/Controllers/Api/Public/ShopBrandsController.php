<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopBrandsController extends Controller
{
    /**
     * Получить список брендов для публичного API
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopBrand::where('is_active', true)
                ->select('id', 'name', 'slug', 'description', 'logo')
                ->orderBy('name');

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Пагинация
            $perPage = min($request->get('limit', 20), 100);
            $brands = $query->paginate($perPage);

            // Форматируем данные для фронтенда
            $formattedBrands = $brands->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'description' => $brand->description,
                    'image_url' => $brand->logo ? $this->getImageUrl($brand->logo) : null,
                    'products_count' => $this->getProductsCount($brand->id)
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedBrands->toArray(),
                'pagination' => [
                    'current_page' => $brands->currentPage(),
                    'last_page' => $brands->lastPage(),
                    'per_page' => $brands->perPage(),
                    'total' => $brands->total(),
                    'from' => $brands->firstItem(),
                    'to' => $brands->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка загрузки брендов: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки брендов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить бренд по slug
     */
    public function getBySlug(string $slug): JsonResponse
    {
        try {
            $brand = ShopBrand::where('is_active', true)
                ->where('slug', $slug)
                ->select('id', 'name', 'slug', 'description', 'logo')
                ->first();

            if (!$brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Бренд не найден'
                ], 404);
            }

            $formattedBrand = [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'description' => $brand->description,
                'image_url' => $brand->logo ? $this->getImageUrl($brand->logo) : null,
                'products_count' => $this->getProductsCount($brand->id)
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedBrand
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Бренд не найден'
            ], 404);
        }
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Если это уже полный URL, возвращаем как есть
        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }

        // Формируем URL относительно storage
        return url('storage/' . $filePath);
    }

    /**
     * Получить количество товаров бренда
     */
    private function getProductsCount($brandId)
    {
        try {
            return \App\Models\ShopGood::whereHas('brands', function ($query) use ($brandId) {
                $query->where('shop_brands.id', $brandId);
            })->where('is_active', true)->count();
        } catch (\Exception $e) {
            // Если есть ошибка с таблицей связей, возвращаем 0
            return 0;
        }
    }
}