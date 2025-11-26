<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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

        // Получаем настройки для включения в ключ кеша
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $showGoodMode = $shopShowGoodMode ? (int)$shopShowGoodMode->value : 2;
        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int)$shopRemoteQ->value : 1;
        
        // Кэшируем результаты на 5 минут (ключ включает настройки фильтрации)
        $cacheKey = 'search_' . md5($query . '_' . $limit . '_' . $showGoodMode . '_' . $remoteQ);
        
        $results = Cache::remember($cacheKey, 300, function () use ($query, $limit) {
            $products = $this->searchProducts($query, $limit);
            $categories = $this->searchCategories($query, $limit);
            $brands = $this->searchBrands($query, $limit);
            
            // Получаем общее количество результатов для каждого типа
            $totalProducts = $this->getTotalProductsCount($query);
            $totalCategories = $this->getTotalCategoriesCount($query);
            $totalBrands = $this->getTotalBrandsCount($query);
            
            Log::info('Search results', [
                'products_count' => count($products),
                'categories_count' => count($categories),
                'brands_count' => count($brands),
                'total_products' => $totalProducts,
                'total_categories' => $totalCategories,
                'total_brands' => $totalBrands
            ]);
            
            return [
                'products' => $products,
                'categories' => $categories,
                'brands' => $brands,
                'total' => [
                    'products' => $totalProducts,
                    'categories' => $totalCategories,
                    'brands' => $totalBrands
                ]
            ];
        });

        return response()->json(['data' => $results]);
    }

    private function searchProducts($query, $limit)
    {
        try {
            $productsQuery = DB::table('shop_goods')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('sku', 'LIKE', "%{$query}%")
                      ->orWhereExists(function ($subQuery) use ($query) {
                          $subQuery->select(DB::raw(1))
                                   ->from('shop_good_variations')
                                   ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                   ->where('shop_good_variations.is_active', true)
                                   ->where('shop_good_variations.sku', 'LIKE', "%{$query}%");
                      });
                });
            
            // Применяем фильтрацию по остаткам
            $this->applyStockFilter($productsQuery);
            
            $products = $productsQuery
                ->select('id', 'name', 'price', 'sale_price', 'sku', 'slug', 'description')
                ->limit($limit)
                ->get()
                ->map(function ($product) use ($query) {
                    // Получаем первое изображение для каждого товара
                    $firstImage = DB::table('shop_good_images')
                        ->where('good_id', $product->id)
                        ->orderBy('id')
                        ->first();
                    
                    // Проверяем, был ли поиск по артикулу вариации
                    $foundVariationId = null;
                    if ($product->sku !== $query) {
                        // Если поиск не по основному артикулу, ищем в вариациях
                        $variation = DB::table('shop_good_variations')
                            ->where('good_id', $product->id)
                            ->where('is_active', true)
                            ->where('sku', 'LIKE', "%{$query}%")
                            ->first();
                        
                        if ($variation) {
                            $foundVariationId = $variation->id;
                        }
                    }
                    
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->sale_price ? $product->sale_price : $product->price,
                        'original_price' => $product->sale_price ? $product->price : null,
                        'sku' => $product->sku,
                        'image' => $firstImage ? $this->getImageUrl($firstImage->file_path) : null,
                        'slug' => $product->slug,
                        'description' => $product->description,
                        'found_variation_id' => $foundVariationId
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

    /**
     * Применить фильтрацию по остаткам к запросу товаров (адаптировано для Query Builder)
     */
    private function applyStockFilter($query) {
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $showGoodMode = $shopShowGoodMode ? (int)$shopShowGoodMode->value : 2;

        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int)$shopRemoteQ->value : 1;

        // Фильтрация применяется только если shop_show_good_mode === 1
        // При значениях 2, 3, 4 фильтрация не применяется (показываются все товары)
        if ($showGoodMode === 1) {
            // Фильтрация по остаткам: показывать только товары с остатком
            // Все условия фильтрации обернуты в одну группу where(), чтобы не конфликтовать с другими условиями
            $query->where(function($mainQuery) use ($remoteQ) {
                // Условие 1: остаток на локальном складе
                $mainQuery->where('stock_quantity', '>', 0);

                if ($remoteQ === 2 || $remoteQ === 3) {
                    // Условие 2: остаток на удаленном складе (не null, не пустая строка, не "0")
                    // Проверяем что значение существует и может быть преобразовано в число больше 0
                    $mainQuery->orWhere(function($remoteCondition) {
                        $remoteCondition->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    });
                }

                // Условие 3: есть вариации с остатком
                $mainQuery->orWhereExists(function($varQ) use ($remoteQ) {
                    $varQ->select(DB::raw(1))
                         ->from('shop_good_variations')
                         ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                         ->where('shop_good_variations.is_active', true)
                         ->where(function($subVarQ) use ($remoteQ) {
                             $subVarQ->where('shop_good_variations.stock_quantity', '>', 0);

                             // Если учитываем удаленный склад, проверяем и там остатки
                             if ($remoteQ === 2 || $remoteQ === 3) {
                                 $subVarQ->orWhere(function($remoteVarQ) {
                                     $remoteVarQ->whereNotNull('shop_good_variations.remote_stock_quantity')
                                         ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                         ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                                 });
                             }
                         });
                });
            });
        }
        // При showGoodMode === 2, 3, 4 фильтрация не применяется - показываются все товары
    }

    /**
     * Получить общее количество товаров по запросу
     */
    private function getTotalProductsCount($query)
    {
        try {
            $countQuery = DB::table('shop_goods')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('sku', 'LIKE', "%{$query}%")
                      ->orWhereExists(function ($subQuery) use ($query) {
                          $subQuery->select(DB::raw(1))
                                   ->from('shop_good_variations')
                                   ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                   ->where('shop_good_variations.is_active', true)
                                   ->where('shop_good_variations.sku', 'LIKE', "%{$query}%");
                      });
                });
            
            // Применяем фильтрацию по остаткам
            $this->applyStockFilter($countQuery);
            
            return $countQuery->count();
        } catch (\Exception $e) {
            Log::error('Get total products count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Получить общее количество категорий по запросу
     */
    private function getTotalCategoriesCount($query)
    {
        try {
            return DB::table('shop_categories')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('description', 'LIKE', "%{$query}%");
                })
                ->count();
        } catch (\Exception $e) {
            Log::error('Get total categories count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Получить общее количество брендов по запросу
     */
    private function getTotalBrandsCount($query)
    {
        try {
            return DB::table('shop_brands')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('description', 'LIKE', "%{$query}%");
                })
                ->count();
        } catch (\Exception $e) {
            Log::error('Get total brands count error: ' . $e->getMessage());
            return 0;
        }
    }
}
