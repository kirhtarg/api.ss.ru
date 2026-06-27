<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use App\Models\ShopCategoryExtraMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $cacheKey = 'public_shop_categories_index_'.md5(json_encode($request->query()));
            if (Cache::has($cacheKey)) {
                return response()->json([
                    'success' => true,
                    'data' => Cache::get($cacheKey),
                ]);
            }

            // Загружаем категории
            $query = ShopCategory::with(['parent', 'children' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('sort_order', 'asc')
                    ->orderBy('name', 'asc')
                    ->select('id', 'name', 'slug', 'image', 'mobile_image', 'icon', 'description', 'parent_id', 'in_catalog');
            }])
                ->where('is_active', true)
                ->ordered();

            // Фильтр по in_catalog
            if ($request->filled('in_catalog')) {
                $inCatalog = $request->get('in_catalog');
                if ($inCatalog == '1' || $inCatalog === 1 || $inCatalog === true) {
                    $query->where('in_catalog', 1);
                }
            }

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

            $categoryIds = $categories->pluck('id')
                ->merge($categories->flatMap(fn ($category) => $category->children->pluck('id')))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $productsCounts = $this->getProductsCounts($categoryIds);

            // Вычисляем количество товаров для каждой категории и подкатегории
            // И обрабатываем изображения
            foreach ($categories as $category) {
                // Обрабатываем изображение категории
                if ($category->image) {
                    $category->image = $this->getImageUrl($category->image);
                }
                if ($category->mobile_image) {
                    $category->mobile_image = $this->getImageUrl($category->mobile_image);
                }

                // Обрабатываем изображение для фигуры
                if ($category->in_figure_img) {
                    $category->in_figure_img = $this->getImageUrl($category->in_figure_img);
                }

                // Количество товаров в главной категории
                $category->products_count = $productsCounts[$category->id] ?? 0;

                // Количество товаров в подкатегориях
                foreach ($category->children as $child) {
                    if ($child->image) {
                        $child->image = $this->getImageUrl($child->image);
                    }
                    if ($child->mobile_image) {
                        $child->mobile_image = $this->getImageUrl($child->mobile_image);
                    }
                    $child->products_count = $productsCounts[$child->id] ?? 0;
                }
            }

            Cache::put($cacheKey, $categories, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категорий: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить конкретную категорию по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $cacheKey = 'public_shop_category_show_'.(int) $id;
            if (Cache::has($cacheKey)) {
                $cachedCategory = Cache::get($cacheKey);
                if (! $cachedCategory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Категория не найдена',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'data' => $cachedCategory,
                ]);
            }

            $category = ShopCategory::with('parent')
                ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (! $category) {
                Cache::put($cacheKey, null, now()->addMinutes(5));

                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            if ($category->image) {
                $category->image = $this->getImageUrl($category->image);
            }
            if ($category->mobile_image) {
                $category->mobile_image = $this->getImageUrl($category->mobile_image);
            }

            Cache::put($cacheKey, $category, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $category,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категории: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить категорию по slug с подкатегориями и родительской категорией
     */
    public function getCategoryBySlugWithRelations(string $slug): JsonResponse
    {
        try {
            $cacheKey = 'public_shop_category_slug_relations_'.md5(mb_strtolower($slug));
            if (Cache::has($cacheKey)) {
                $cachedData = Cache::get($cacheKey);
                if (! $cachedData) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Категория не найдена',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                ]);
            }

            // Получаем основную категорию
            $category = ShopCategory::where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if (! $category) {
                Cache::put($cacheKey, null, now()->addMinutes(5));

                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            // Получаем подкатегории
            $subcategories = ShopCategory::where('parent_id', $category->id)
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'image', 'mobile_image', 'icon', 'description', 'parent_id', 'in_catalog']);

            // Получаем родительскую категорию, если есть
            $parentCategory = null;
            if ($category->parent_id) {
                $parentCategory = ShopCategory::where('id', $category->parent_id)
                    ->where('is_active', true)
                    ->first(['id', 'name', 'slug', 'image', 'mobile_image', 'parent_id', 'in_catalog']);
            }

            $countIds = collect([$category->id])
                ->merge($subcategories->pluck('id'))
                ->merge($parentCategory ? [$parentCategory->id] : [])
                ->filter()
                ->unique()
                ->values()
                ->all();
            $productsCounts = $this->getProductsCounts($countIds);

            // Вычисляем количество товаров для основной категории
            $category->products_count = $productsCounts[$category->id] ?? 0;

            // Вычисляем количество товаров для подкатегорий
            foreach ($subcategories as $subcategory) {
                $subcategory->products_count = $productsCounts[$subcategory->id] ?? 0;
            }

            // Вычисляем количество товаров для родительской категории
            if ($parentCategory) {
                $parentCategory->products_count = $productsCounts[$parentCategory->id] ?? 0;
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
                'mobile_image' => $category->mobile_image ? $this->getImageUrl($category->mobile_image) : null,
                'icon' => $category->icon,
                'parent_id' => $category->parent_id,
                'in_catalog' => $category->in_catalog,
                'products_count' => $category->products_count,
            ];

            $subcategoriesData = $subcategories->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'name' => $sub->name,
                    'slug' => $sub->slug,
                    'image' => $sub->image ? $this->getImageUrl($sub->image) : null,
                    'mobile_image' => $sub->mobile_image ? $this->getImageUrl($sub->mobile_image) : null,
                    'icon' => $sub->icon,
                    'description' => $sub->description,
                    'parent_id' => $sub->parent_id,
                    'in_catalog' => $sub->in_catalog,
                    'products_count' => $sub->products_count,
                ];
            });

            $parentCategoryData = null;
            if ($parentCategory) {
                $parentCategoryData = [
                    'id' => $parentCategory->id,
                    'name' => $parentCategory->name,
                    'slug' => $parentCategory->slug,
                    'image' => $parentCategory->image ? $this->getImageUrl($parentCategory->image) : null,
                    'mobile_image' => $parentCategory->mobile_image ? $this->getImageUrl($parentCategory->mobile_image) : null,
                    'parent_id' => $parentCategory->parent_id,
                    'in_catalog' => $parentCategory->in_catalog,
                    'products_count' => $parentCategory->products_count,
                ];
            }

            $responseData = [
                'category' => $categoryData,
                'subcategories' => $subcategoriesData,
                'parent_category' => $parentCategoryData,
            ];
            Cache::put($cacheKey, $responseData, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $responseData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категории: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить подкатегории для конкретной категории
     */
    public function getChildren($id): JsonResponse
    {
        try {
            $cacheKey = 'public_shop_category_children_'.(int) $id;
            if (Cache::has($cacheKey)) {
                $cachedChildren = Cache::get($cacheKey);

                return response()->json([
                    'success' => true,
                    'data' => $cachedChildren,
                ]);
            }

            $parentCategory = ShopCategory::where('id', $id)
                ->where('is_active', true)
                ->first();

            if (! $parentCategory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская категория не найдена',
                ], 404);
            }

            $children = ShopCategory::where('parent_id', $id)
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'image', 'mobile_image', 'icon', 'description', 'parent_id', 'in_catalog']);

            $productsCounts = $this->getProductsCounts($children->pluck('id')->all());

            // Вычисляем количество товаров для подкатегорий
            foreach ($children as $child) {
                if ($child->image) {
                    $child->image = $this->getImageUrl($child->image);
                }
                if ($child->mobile_image) {
                    $child->mobile_image = $this->getImageUrl($child->mobile_image);
                }
                $child->products_count = $productsCounts[$child->id] ?? 0;
            }

            Cache::put($cacheKey, $children, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $children,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подкатегорий: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить подкатегории для нескольких категорий одним запросом (batch)
     */
    public function getChildrenBatch(Request $request): JsonResponse
    {
        try {
            $categoryIds = $request->input('category_ids', []);

            if (empty($categoryIds) || ! is_array($categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходимо передать массив category_ids',
                ], 400);
            }

            // Преобразуем в массив целых чисел
            $categoryIds = array_map('intval', $categoryIds);
            $categoryIds = array_filter($categoryIds);

            if (empty($categoryIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Получаем все подкатегории для переданных категорий одним запросом
            $children = ShopCategory::whereIn('parent_id', $categoryIds)
                ->where('is_active', true)
                ->orderBy('parent_id', 'asc')
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'image', 'mobile_image', 'icon', 'description', 'parent_id', 'in_catalog']);

            // Вычисляем количество товаров для подкатегорий
            foreach ($children as $child) {
                if ($child->image) {
                    $child->image = $this->getImageUrl($child->image);
                }
                if ($child->mobile_image) {
                    $child->mobile_image = $this->getImageUrl($child->mobile_image);
                }
                $child->products_count = DB::table('shop_good_categories')
                    ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_categories.category_id', $child->id)
                    ->where('shop_goods.is_active', true)
                    ->count();
            }

            // Группируем по parent_id для удобства на фронтенде
            $grouped = [];
            foreach ($children as $child) {
                $parentId = $child->parent_id;
                if (! isset($grouped[$parentId])) {
                    $grouped[$parentId] = [];
                }
                $grouped[$parentId][] = $child;
            }

            return response()->json([
                'success' => true,
                'data' => $grouped,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подкатегорий: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить путь к изображению (относительный, без домена)
     */
    private function getImageUrl($filePath)
    {
        if (! $filePath) {
            return null;
        }

        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $filePath = $matches[1];
        }

        // Убираем лишний слэш в начале
        $filePath = ltrim($filePath, '/');

        // Если путь начинается с images/, возвращаем с ведущим слэшем
        if (str_starts_with($filePath, 'images/')) {
            return '/'.$filePath;
        }

        // Возвращаем относительный путь к файлу в папке public/images/
        return '/images/'.$filePath;
    }

    /**
     * Получить главные категории (без родительских)
     */
    public function main(): JsonResponse
    {
        try {
            $cacheKey = 'public_shop_categories_main';
            if (Cache::has($cacheKey)) {
                return response()->json([
                    'success' => true,
                    'data' => Cache::get($cacheKey),
                ]);
            }

            $categories = ShopCategory::where('is_active', true)
                ->whereNull('parent_id')
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            $categories = $categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'image' => $category->image ? $this->getImageUrl($category->image) : null,
                    'mobile_image' => $category->mobile_image ? $this->getImageUrl($category->mobile_image) : null,
                    'sort_order' => $category->sort_order,
                    'is_active' => $category->is_active,
                    'children_count' => $category->children()->where('is_active', true)->count(),
                ];
            });

            Cache::put($cacheKey, $categories, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении главных категорий: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить экстра-меню категории
     */
    public function getExtraMenu($categoryId): JsonResponse
    {
        try {
            $cacheKey = 'public_shop_category_extra_menu_'.(int) $categoryId;
            if (Cache::has($cacheKey)) {
                return response()->json([
                    'success' => true,
                    'data' => Cache::get($cacheKey),
                ]);
            }

            $category = ShopCategory::find($categoryId);

            if (! $category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            $extraMenu = ShopCategoryExtraMenu::with([
                'filters' => function ($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'sections' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'sections.items' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'sections.items.category' => function ($query) {
                    $query->where('is_active', true);
                },
            ])
                ->where('category_id', $categoryId)
                ->where('is_active', true)
                ->first();

            if (! $extraMenu) {
                Cache::put($cacheKey, null, now()->addMinutes(10));

                return response()->json([
                    'success' => true,
                    'data' => null,
                ]);
            }

            Cache::put($cacheKey, $extraMenu, now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => $extraMenu,
            ]);
        } catch (\Exception $e) {
            Log::error('getExtraMenu: ошибка', [
                'categoryId' => $categoryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении экстра-меню: '.$e->getMessage(),
            ], 500);
        }
    }

    private function getProductsCounts(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (empty($categoryIds)) {
            return [];
        }

        $cacheKey = 'public_shop_category_product_counts_'.md5(implode(',', $categoryIds));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($categoryIds) {
            return DB::table('shop_good_categories')
                ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                ->whereIn('shop_good_categories.category_id', $categoryIds)
                ->where('shop_goods.is_active', true)
                ->select('shop_good_categories.category_id', DB::raw('COUNT(*) as products_count'))
                ->groupBy('shop_good_categories.category_id')
                ->pluck('products_count', 'category_id')
                ->map(fn ($count) => (int) $count)
                ->toArray();
        });
    }
}
