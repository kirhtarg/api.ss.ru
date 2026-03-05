<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopBrandsController extends Controller
{
    /**
     * Получить список брендов для публичного API
     * Оптимизированная версия с учетом параметров остатков и быстрой загрузкой
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Получаем настройки сайта
            $settings = $this->getStockSettings();

            // Получаем ID категорий из параметров
            $categoryIds = $this->getCategoryIds($request);

            // Если нужно включить подкатегории и есть категории для обработки
            $includeSubcategories = $request->filled('include_subcategories')
                && ($request->get('include_subcategories') == '1' || $request->get('include_subcategories') === true);

            if ($includeSubcategories && ! empty($categoryIds)) {
                // Получаем все подкатегории рекурсивно (включая исходные категории)
                $originalCategoryIds = $categoryIds;
                $categoryIds = ShopCategory::getAllDescendantIds($categoryIds);

                // Убеждаемся, что исходные категории включены в результат
                $categoryIds = array_values(array_unique(array_merge($originalCategoryIds, $categoryIds)));
            }

            // Используем оптимизированный запрос через DB::table для лучшей производительности
            $brandsQuery = DB::table('shop_brands')
                ->select('shop_brands.id', 'shop_brands.name')
                ->where('shop_brands.is_active', true)
                ->distinct();

            // Применяем фильтр по товарам через join
            $brandsQuery->join('shop_good_brands', 'shop_brands.id', '=', 'shop_good_brands.brand_id')
                ->join('shop_goods', 'shop_good_brands.good_id', '=', 'shop_goods.id')
                ->where('shop_goods.is_active', true);

            // Фильтр по категориям (только если указаны категории)
            if (! empty($categoryIds)) {
                // Используем whereExists для более точной фильтрации по категориям
                $brandsQuery->whereExists(function ($query) use ($categoryIds) {
                    $query->select(DB::raw(1))
                        ->from('shop_good_categories')
                        ->whereColumn('shop_good_categories.good_id', 'shop_goods.id')
                        ->whereIn('shop_good_categories.category_id', $categoryIds);
                });
            }

            // Применяем фильтры по остаткам и цене
            $this->applyStockFilterToQuery($brandsQuery, $settings);
            $this->applyPriceFilterToQuery($brandsQuery, $settings);

            // Поиск по названию бренда
            if ($request->filled('search')) {
                $search = $request->get('search');
                $brandsQuery->where('shop_brands.name', 'like', "%{$search}%");
            }

            // Лимит (если указан)
            $limit = $request->filled('limit') ? min((int) $request->get('limit'), 500) : null;
            if ($limit) {
                $brandsQuery->limit($limit);
            }

            // Сортируем по названию
            $brandsQuery->orderBy('shop_brands.name');

            // Выполняем запрос
            $brands = $brandsQuery->get();

            // Форматируем данные - только id и name, как требуется для фильтров
            $formattedBrands = $brands->map(function ($brand) {
                return [
                    'id' => (int) $brand->id,
                    'name' => $brand->name,
                ];
            })->unique('id')->values();

            return response()->json([
                'success' => true,
                'data' => $formattedBrands->toArray(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки брендов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить ID категорий из запроса
     */
    private function getCategoryIds(Request $request): array
    {
        $categoryIds = [];

        // Проверяем массив categories[] (множественные категории)
        if ($request->has('categories')) {
            $categories = $request->get('categories');
            if (is_array($categories)) {
                $categoryIds = array_filter(array_map('intval', $categories));
            } elseif (is_numeric($categories)) {
                // Если передан один ID как число
                $categoryIds = [(int) $categories];
            }
        }

        // Проверяем category_id (для обратной совместимости)
        if (empty($categoryIds) && $request->filled('category_id')) {
            $categoryIds = [(int) $request->get('category_id')];
        }

        return array_values(array_unique($categoryIds));
    }

    /**
     * Получить настройки остатков
     */
    private function getStockSettings(): array
    {
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $hidden0PriceSetting = Setting::where('key', 'hidden_0_price')->first();

        return [
            'showGoodMode' => $shopShowGoodMode ? (int) $shopShowGoodMode->value : 2,
            'remoteQ' => $shopRemoteQ ? (int) $shopRemoteQ->value : 1,
            'hidden0Price' => $hidden0PriceSetting ? (int) $hidden0PriceSetting->value : 0,
        ];
    }

    /**
     * Применить фильтр по остаткам к запросу (оптимизированная версия с прямыми условиями)
     */
    private function applyStockFilterToQuery($query, array $settings)
    {
        $showGoodMode = $settings['showGoodMode'];
        $remoteQ = $settings['remoteQ'];

        // Фильтрация по остаткам применяется ТОЛЬКО при shop_show_good_mode = 1
        if ($showGoodMode === 1) {
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Вариант 1: Товары БЕЗ вариаций с остатком основного товара
                $mainQuery->where(function ($noVariationsQuery) use ($remoteQ) {
                    // Проверяем что у товара нет вариаций
                    $noVariationsQuery->whereNotExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('shop_good_variations')
                            ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                            ->where('shop_good_variations.is_active', true);
                    });

                    // Проверяем остаток основного товара
                    $noVariationsQuery->where(function ($stockQuery) use ($remoteQ) {
                        $stockQuery->where('shop_goods.stock_quantity', '>', 0);

                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $stockQuery->orWhere(function ($remoteCondition) {
                                $remoteCondition->whereNotNull('shop_goods.remote_stock_quantity')
                                    ->where('shop_goods.remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(shop_goods.remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteCondition) {
                                    $fastRemoteCondition->whereNotNull('shop_goods.fast_remote_stock_quantity')
                                        ->where('shop_goods.fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(shop_goods.fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                });

                // Вариант 2: Товары С вариациями, у которых сумма остатков всех вариаций > 0
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    // Проверяем что у товара есть активные вариации
                    $hasVariationsQuery->whereExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('shop_good_variations')
                            ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                            ->where('shop_good_variations.is_active', true);
                    });

                    // Проверяем что сумма остатков вариаций > 0
                    if ($remoteQ === 2 || $remoteQ === 3) {
                        $hasVariationsQuery->whereRaw('(
                            SELECT COALESCE(SUM(
                                COALESCE(stock_quantity, 0) + 
                                CASE 
                                    WHEN remote_stock_quantity IS NOT NULL 
                                         AND remote_stock_quantity != "0" 
                                         AND LENGTH(TRIM(remote_stock_quantity)) > 0
                                         AND remote_stock_quantity REGEXP "^[0-9]+$"
                                         AND CAST(remote_stock_quantity AS UNSIGNED) > 0
                                    THEN CAST(remote_stock_quantity AS UNSIGNED)
                                    ELSE 0
                                END
                            ), 0)
                            FROM shop_good_variations
                            WHERE shop_good_variations.good_id = shop_goods.id 
                            AND shop_good_variations.is_active = 1
                        ) > 0');
                    } else {
                        $hasVariationsQuery->whereRaw('(
                            SELECT COALESCE(SUM(stock_quantity), 0)
                            FROM shop_good_variations
                            WHERE shop_good_variations.good_id = shop_goods.id 
                            AND shop_good_variations.is_active = 1
                            AND stock_quantity > 0
                        ) > 0');
                    }
                });
            });
        }
    }

    /**
     * Применить фильтр по цене к запросу (скрывать товары с ценой 0 если hidden_0_price = 1)
     */
    private function applyPriceFilterToQuery($query, array $settings)
    {
        $hidden0Price = $settings['hidden0Price'];

        // Если hidden_0_price = 1, не показываем товары с ценой 0
        if ($hidden0Price === 1) {
            $query->where(function ($q) {
                // Показываем товары, у которых цена > 0 (проверяем и price, и sale_price)
                $q->where(function ($priceQ) {
                    $priceQ->where('shop_goods.price', '>', 0)
                        ->orWhere('shop_goods.sale_price', '>', 0);
                })
                // ИЛИ есть вариации с ценой > 0
                    ->orWhereExists(function ($varQ) {
                        $varQ->select(DB::raw(1))
                            ->from('shop_good_variations')
                            ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                            ->where('shop_good_variations.is_active', true)
                            ->where(function ($varPriceQ) {
                                $varPriceQ->where('shop_good_variations.price', '>', 0)
                                    ->orWhere('shop_good_variations.sale_price', '>', 0);
                            });
                    });
            });
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

            if (! $brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Бренд не найден',
                ], 404);
            }

            $formattedBrand = [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'description' => $brand->description,
                'image_url' => $brand->logo ? $this->getImageUrl($brand->logo) : null,
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedBrand,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Бренд не найден',
            ], 404);
        }
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (! $filePath) {
            return null;
        }

        // Если это уже полный URL, возвращаем как есть
        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($filePath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            return '/'.$cleanPath;
        }

        // Возвращаем путь к файлу в папке public/images/
        return '/images/'.$cleanPath;
    }
}
