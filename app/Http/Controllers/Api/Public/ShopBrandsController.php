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

            // Фильтр по категории - показываем только бренды, у которых есть товары в данной категории
            if ($request->filled('category_id')) {
                $categoryId = $request->get('category_id');
                
                // Проверяем, есть ли товары в категории
                $goodsInCategory = \App\Models\ShopGood::whereHas('categories', function ($q) use ($categoryId) {
                    $q->where('shop_categories.id', $categoryId);
                })->where('is_active', true)->count();
                
                // Проверяем, есть ли бренды у товаров в категории
                $brandsInCategory = \App\Models\ShopBrand::whereHas('goods', function ($q) use ($categoryId) {
                    $q->whereHas('categories', function ($catQuery) use ($categoryId) {
                        $catQuery->where('shop_categories.id', $categoryId);
                    })->where('is_active', true);
                })->count();
                
                $query->whereHas('goods', function ($q) use ($categoryId) {
                    $q->whereHas('categories', function ($catQuery) use ($categoryId) {
                        $catQuery->where('shop_categories.id', $categoryId);
                    })->where('is_active', true);
                });
            }

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Пагинация (временно отключена для отладки)
            $perPage = min($request->get('limit', 20), 100);
            $brands = $query->get(); // Временно используем get() вместо paginate()
            

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
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $brands->count(),
                    'total' => $brands->count(),
                    'from' => 1,
                    'to' => $brands->count()
                ]
            ]);

        } catch (\Exception $e) {
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

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($filePath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            return '/' . $cleanPath;
        }

        // Возвращаем путь к файлу в папке public/images/
        return '/images/' . $cleanPath;
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