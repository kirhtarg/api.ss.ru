<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopCategoriesController extends Controller
{
    /**
     * Получить список активных категорий для публичного API
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Загружаем категории
            $query = ShopCategory::with(['parent', 'children' => function($query) {
                $query->where('is_active', true)
                      ->orderBy('sort_order', 'asc')
                      ->orderBy('name', 'asc')
                      ->select('id', 'name', 'slug', 'icon', 'description', 'parent_id');
            }])
            ->where('is_active', true)
            ->ordered();

            // Фильтр по бренду - показываем только категории, у которых есть товары данного бренда
            if ($request->filled('brand_id')) {
                $brandId = $request->get('brand_id');
                
                // Сначала проверим, есть ли товары у этого бренда
                $brandGoodsCount = \DB::table('shop_goods')
                    ->join('shop_good_brands', 'shop_goods.id', '=', 'shop_good_brands.good_id')
                    ->where('shop_good_brands.brand_id', $brandId)
                    ->where('shop_goods.is_active', true)
                    ->count();
                
                
                // Проверим, в каких категориях есть товары этого бренда
                $brandCategoriesCount = \DB::table('shop_categories')
                    ->join('shop_good_categories', 'shop_categories.id', '=', 'shop_good_categories.category_id')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->join('shop_good_brands', 'shop_goods.id', '=', 'shop_good_brands.good_id')
                    ->where('shop_good_brands.brand_id', $brandId)
                    ->where('shop_goods.is_active', true)
                    ->where('shop_categories.is_active', true)
                    ->distinct('shop_categories.id')
                    ->count();
                
                
                $query->whereHas('goods', function ($q) use ($brandId) {
                    $q->whereHas('brands', function ($brandQuery) use ($brandId) {
                        $brandQuery->where('shop_brands.id', $brandId);
                    })->where('is_active', true);
                });
            }

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            $categories = $query->get();


            // Проверяем все категории в базе
            $allCategoriesCount = ShopCategory::where('is_active', true)->count();
            $mainCategoriesCount = ShopCategory::where('is_active', true)
                ->where(function($query) {
                    $query->whereNull('parent_id')
                          ->orWhere('parent_id', 0);
                })->count();
            


            // Вычисляем количество товаров для каждой категории и подкатегории
            foreach ($categories as $category) {
                // Количество товаров в главной категории
                $category->products_count = \DB::table('shop_good_categories')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_categories.category_id', $category->id)
                    ->where('shop_goods.is_active', true)
                    ->count();

                // Количество товаров в подкатегориях
                foreach ($category->children as $child) {
                    $child->products_count = \DB::table('shop_good_categories')
                        ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                        ->where('shop_good_categories.category_id', $child->id)
                        ->where('shop_goods.is_active', true)
                        ->count();
                }
            }


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

    /**
     * Получить категорию по slug с подкатегориями и родительской категорией
     */
    public function getCategoryBySlugWithRelations(string $slug): JsonResponse
    {
        try {
            // Получаем основную категорию
            $category = ShopCategory::where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Получаем подкатегории
            $subcategories = ShopCategory::where('parent_id', $category->id)
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'icon', 'description', 'parent_id']);

            // Получаем родительскую категорию, если есть
            $parentCategory = null;
            if ($category->parent_id) {
                $parentCategory = ShopCategory::where('id', $category->parent_id)
                    ->where('is_active', true)
                    ->first(['id', 'name', 'slug', 'parent_id']);
            }

            // Вычисляем количество товаров для основной категории
            $category->products_count = DB::table('shop_good_categories')
                ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                ->where('shop_good_categories.category_id', $category->id)
                ->where('shop_goods.is_active', true)
                ->count();

            // Вычисляем количество товаров для подкатегорий
            foreach ($subcategories as $subcategory) {
                $subcategory->products_count = DB::table('shop_good_categories')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_categories.category_id', $subcategory->id)
                    ->where('shop_goods.is_active', true)
                    ->count();
            }

            // Вычисляем количество товаров для родительской категории
            if ($parentCategory) {
                $parentCategory->products_count = DB::table('shop_good_categories')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_categories.category_id', $parentCategory->id)
                    ->where('shop_goods.is_active', true)
                    ->count();
            }

            // Формируем данные для ответа
            $categoryData = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'meta_title' => $category->meta_title,
                'meta_description' => $category->meta_description,
                'image' => $category->image ? $this->getImageUrl($category->image) : null,
                'icon' => $category->icon,
                'parent_id' => $category->parent_id,
                'products_count' => $category->products_count
            ];

            $subcategoriesData = $subcategories->map(function($sub) {
                return [
                    'id' => $sub->id,
                    'name' => $sub->name,
                    'slug' => $sub->slug,
                    'icon' => $sub->icon,
                    'description' => $sub->description,
                    'parent_id' => $sub->parent_id,
                    'products_count' => $sub->products_count
                ];
            });

            $parentCategoryData = null;
            if ($parentCategory) {
                $parentCategoryData = [
                    'id' => $parentCategory->id,
                    'name' => $parentCategory->name,
                    'slug' => $parentCategory->slug,
                    'parent_id' => $parentCategory->parent_id,
                    'products_count' => $parentCategory->products_count
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $categoryData,
                    'subcategories' => $subcategoriesData,
                    'parent_category' => $parentCategoryData
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить подкатегории для конкретной категории
     */
    public function getChildren($id): JsonResponse
    {
        try {
            $parentCategory = ShopCategory::where('id', $id)
                ->where('is_active', true)
                ->first();

            if (!$parentCategory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская категория не найдена'
                ], 404);
            }

            $children = ShopCategory::where('parent_id', $id)
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'icon', 'description', 'parent_id']);

            // Вычисляем количество товаров для подкатегорий
            foreach ($children as $child) {
                $child->products_count = DB::table('shop_good_categories')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_categories.category_id', $child->id)
                    ->where('shop_goods.is_active', true)
                    ->count();
            }

            return response()->json([
                'success' => true,
                'data' => $children
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подкатегорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Убираем возможные префиксы API сервера
        $cleanPath = $filePath;
        
        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $cleanPath = $matches[1];
        }
        
        // Если это уже полный URL, проверяем домен
        if (str_starts_with($cleanPath, 'http')) {
            // Заменяем старый домен на новый фронтенд домен
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $oldDomains = [
                'https://ss75.kirhtarg.ru',
                'https://api.ss.ru',
                'https://ss75-api.kirhtarg.ru'
            ];
            
            foreach ($oldDomains as $oldDomain) {
                if (str_starts_with($cleanPath, $oldDomain)) {
                    return str_replace($oldDomain, $frontendUrl, $cleanPath);
                }
            }
            
            // Если это другой домен, возвращаем как есть
            return $cleanPath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($cleanPath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            // Возвращаем полный URL с фронтенда
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            return $frontendUrl . '/' . $cleanPath;
        }

        // Возвращаем полный URL к файлу в папке public/images/ на фронтенде
        $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
        return $frontendUrl . '/images/' . $cleanPath;
    }
}
