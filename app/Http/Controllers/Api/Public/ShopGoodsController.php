<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopGoodsController extends Controller
{
    /**
     * Получить список товаров для публичного API
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopGood::with([
                'categories:id,name,slug',
                'brands:id,name,slug',
                'tags:id,name,color',
                'variations:id,good_id,name,price,sale_price,stock_quantity,is_active'
            ])
            ->where('is_active', true); // Только активные товары

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('short_description', 'like', "%{$search}%");
                });
            }

            // Фильтр по категории
            if ($request->filled('category_id')) {
                $query->whereHas('categories', function ($q) use ($request) {
                    $q->where('shop_categories.id', $request->get('category_id'));
                });
            }

            // Фильтр по множественным категориям
            if ($request->has('categories') && is_array($request->get('categories'))) {
                $query->whereHas('categories', function ($q) use ($request) {
                    $q->whereIn('shop_categories.id', $request->get('categories'));
                });
            }

            // Фильтр по бренду
            if ($request->filled('brand_id')) {
                $query->whereHas('brands', function ($q) use ($request) {
                    $q->where('shop_brands.id', $request->get('brand_id'));
                });
            }

            // Фильтр по множественным брендам
            if ($request->has('brands') && is_array($request->get('brands'))) {
                $query->whereHas('brands', function ($q) use ($request) {
                    $q->whereIn('shop_brands.id', $request->get('brands'));
                });
            }

            // Фильтр по цене
            if ($request->filled('min_price')) {
                $query->where('price', '>=', $request->get('min_price'));
            }
            if ($request->filled('max_price')) {
                $query->where('price', '<=', $request->get('max_price'));
            }

            // Фильтр по рейтингу
            if ($request->filled('min_rating')) {
                $query->where('rating', '>=', $request->get('min_rating'));
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'sort_order');
            $sortOrder = $request->get('sort_order', 'asc');
            
            $allowedSortFields = ['name', 'price', 'rating', 'created_at', 'sort_order'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('sort_order', 'asc');
            }

            // Пагинация
            $perPage = $request->get('limit', 10);
            $page = $request->get('page', 1);
            
            
            $goods = $query->paginate($perPage, ['*'], 'page', $page);

            // Форматируем данные для фронтенда
            $formattedGoods = $goods->map(function ($good) {
                return $this->formatGoodForFrontend($good);
            });

            
            return response()->json([
                'success' => true,
                'data' => $formattedGoods,
                'pagination' => [
                    'current_page' => $goods->currentPage(),
                    'last_page' => $goods->lastPage(),
                    'per_page' => $goods->perPage(),
                    'total' => $goods->total(),
                    'from' => $goods->firstItem(),
                    'to' => $goods->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товаров: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить товар по ID или slug для публичного API
     */
    public function show($id): JsonResponse
    {
        try {
            $good = ShopGood::with([
                'categories:id,name,slug',
                'brands:id,name,slug',
                'tags:id,name,color',
                'variations:id,good_id,name,price,sale_price,stock_quantity,is_active',
                'properties:id,name,slug',
                'images:id,good_id,file_path,alt_text,is_main,sort_order'
            ])
            ->where('is_active', true)
            ->where(function ($query) use ($id) {
                $query->where('id', $id)
                      ->orWhere('slug', $id);
            })
            ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatGoodForFrontend($good)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Товар не найден'
            ], 404);
        }
    }

    /**
     * Форматировать товар для фронтенда
     */
    private function formatGoodForFrontend($good)
    {
        // Формируем характеристики
        $characteristics = [];
        if ($good->relationLoaded('properties')) {
            $characteristics = $good->properties->map(function ($property) {
                return [
                    'id' => $property->id,
                    'name' => $property->name,
                    'value' => $property->pivot->value ?? ''
                ];
            })->toArray();
        }

        return [
            'id' => $good->id,
            'name' => $good->name,
            'slug' => $good->slug,
            'description' => $good->description,
            'short_description' => $good->short_description,
            'price' => (float) $good->price,
            'old_price' => $good->sale_price ? (float) $good->sale_price : null,
            'discount_percent' => $good->discount_percent,
            'image_url' => $this->getImageUrl($good->image_path),
            'images' => $good->images()->orderBy('sort_order')->get()->map(function ($image) {
                return [
                    'id' => $image->id,
                    'url' => $this->getImageUrl($image->file_path),
                    'alt_text' => $image->alt_text,
                    'is_main' => $image->is_main
                ];
            })->toArray(),
            'rating' => $good->rating ? (float) $good->rating : null,
            'reviews_count' => $good->reviews_count ?? 0,
            'characteristics' => $characteristics,
            'in_stock' => $good->stock_quantity > 0,
            'stock_quantity' => $good->stock_quantity,
            'is_new' => (bool) $good->is_new,
            'is_sale' => (bool) $good->is_sale,
            'category_id' => $good->categories->first() ? $good->categories->first()->id : null,
            'brand_id' => $good->brands->first() ? $good->brands->first()->id : null,
            'category' => $good->categories->first() ? [
                'id' => $good->categories->first()->id,
                'name' => $good->categories->first()->name,
                'slug' => $good->categories->first()->slug
            ] : null,
            'brand' => $good->brands->first() ? [
                'id' => $good->brands->first()->id,
                'name' => $good->brands->first()->name,
                'slug' => $good->brands->first()->slug
            ] : null,
            'tags' => $good->tags->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color
                ];
            })->toArray(),
            'variations' => $good->variations->map(function ($variation) {
                return [
                    'id' => $variation->id,
                    'name' => $variation->name,
                    'price' => (float) $variation->price,
                    'sale_price' => $variation->sale_price ? (float) $variation->sale_price : null,
                    'stock_quantity' => $variation->stock_quantity,
                    'is_active' => $variation->is_active
                ];
            })->toArray()
        ];
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

        // Возвращаем только путь к файлу, без домена
        return '/storage/' . ltrim($filePath, '/');
    }

    /**
     * Получить товар по slug
     */
    public function getGoodBySlug(string $slug): JsonResponse
    {
        try {
            $good = ShopGood::with([
                'categories:id,name,slug',
                'brands:id,name,slug',
                'tags:id,name,color',
                'variations:id,good_id,name,price,sale_price,stock_quantity,is_active',
                'images:id,good_id,file_path,alt_text,is_main,sort_order'
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatGoodForFrontend($good)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить изображения товара
     */
    public function getGoodImages(int $goodId): JsonResponse
    {
        try {
            $good = ShopGood::where('id', $goodId)
                ->where('is_active', true)
                ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            $images = $good->images()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'file_path' => $image->file_path,
                        'alt_text' => $image->alt_text,
                        'is_main' => $image->is_main,
                        'sort_order' => $image->sort_order,
                        'url' => $this->getImageUrl($image->file_path)
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $images
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении изображений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить категорию по slug
     */
    public function getCategoryBySlug(string $slug): JsonResponse
    {
        try {
            $category = ShopCategory::where('slug', $slug)
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
                'data' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'meta_title' => $category->meta_title,
                    'meta_description' => $category->meta_description,
                    'image' => $category->image ? $this->getImageUrl($category->image) : null,
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
     * Пакетная загрузка товаров
     */
    public function getBatch(Request $request): JsonResponse
    {
        try {
            // Логируем запрос для отладки
            \Log::info('Batch request received', [
                'method' => $request->method(),
                'headers' => $request->headers->all(),
                'data' => $request->all()
            ]);

            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer|min:1'
            ]);

            $ids = $request->get('ids');
            
            // Ограничиваем количество товаров в одном запросе
            if (count($ids) > 20) {
                return response()->json([
                    'success' => false,
                    'message' => 'Максимум 20 товаров за один запрос'
                ], 400);
            }

            $goods = ShopGood::with([
                'categories:id,name,slug',
                'brands:id,name,slug',
                'tags:id,name,color',
                'variations:id,good_id,name,price,sale_price,stock_quantity,is_active',
                'properties:id,name,slug',
                'images:id,good_id,file_path,alt_text,is_main,sort_order'
            ])
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get();

            $formattedGoods = $goods->map(function ($good) {
                return $this->formatGoodForFrontend($good);
            })->keyBy('id');

            return response()->json([
                'success' => true,
                'data' => $formattedGoods
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пакетной загрузке товаров: ' . $e->getMessage()
            ], 500);
        }
    }
}
