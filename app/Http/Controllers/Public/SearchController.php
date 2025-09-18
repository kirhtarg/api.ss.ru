<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $limit = min($request->get('limit', 10), 50); // Максимум 50 результатов

        Log::info('Search request', ['query' => $query, 'limit' => $limit]);

        if (strlen($query) < 3) {
            return response()->json([
                'data' => [
                    'products' => [],
                    'categories' => [],
                    'brands' => []
                ]
            ]);
        }

        // Кэшируем результаты на 5 минут
        $cacheKey = 'search_' . md5($query . '_' . $limit);
        
        $results = Cache::remember($cacheKey, 300, function () use ($query, $limit) {
            $products = $this->searchProducts($query, $limit);
            $categories = $this->searchCategories($query, $limit);
            $brands = $this->searchBrands($query, $limit);
            
            Log::info('Search results', [
                'products_count' => count($products),
                'categories_count' => count($categories),
                'brands_count' => count($brands)
            ]);
            
            return [
                'products' => $products,
                'categories' => $categories,
                'brands' => $brands
            ];
        });

        return response()->json(['data' => $results]);
    }

    private function searchProducts($query, $limit)
    {
        try {
            $products = DB::table('shop_goods')
                ->where('is_active', true)
                ->where('name', 'LIKE', "%{$query}%")
                ->select('id', 'name', 'price', 'sale_price', 'sku', 'slug', 'description')
                ->limit($limit)
                ->get()
                ->map(function ($product) {
                    // Получаем первое изображение для каждого товара
                    $firstImage = DB::table('shop_good_images')
                        ->where('good_id', $product->id)
                        ->orderBy('id')
                        ->first();
                    
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->sale_price ? $product->sale_price : $product->price,
                        'original_price' => $product->sale_price ? $product->price : null,
                        'sku' => $product->sku,
                        'image' => $firstImage ? $this->getImageUrl($firstImage->file_path) : null,
                        'slug' => $product->slug,
                        'description' => $product->description
                    ];
                });

            return $products->toArray();
        } catch (\Exception $e) {
            Log::error('Search products error: ' . $e->getMessage());
            return [];
        }
    }

    private function searchCategories($query, $limit)
    {
        try {
            $categories = DB::table('shop_categories')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('description', 'LIKE', "%{$query}%");
                })
                ->select('id', 'name', 'description', 'image', 'slug')
                ->limit($limit)
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                        'image' => $category->image ? $this->getImageUrl($category->image) : null,
                        'slug' => $category->slug
                    ];
                });

            return $categories->toArray();
        } catch (\Exception $e) {
            Log::error('Search categories error: ' . $e->getMessage());
            return [];
        }
    }

    private function searchBrands($query, $limit)
    {
        try {
            $brands = DB::table('shop_brands')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('description', 'LIKE', "%{$query}%");
                })
                ->select('id', 'name', 'description', 'logo', 'slug')
                ->limit($limit)
                ->get()
                ->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'description' => $brand->description,
                        'logo' => $brand->logo ? $this->getImageUrl($brand->logo) : null,
                        'slug' => $brand->slug
                    ];
                });

            return $brands->toArray();
        } catch (\Exception $e) {
            Log::error('Search brands error: ' . $e->getMessage());
            return [];
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
}
