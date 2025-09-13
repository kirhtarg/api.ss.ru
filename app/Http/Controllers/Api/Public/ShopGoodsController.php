<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
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
                'images:id,good_id,file_path,alt_text,is_main,sort_order',
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

            // Фильтр по бренду
            if ($request->filled('brand_id')) {
                $query->whereHas('brands', function ($q) use ($request) {
                    $q->where('shop_brands.id', $request->get('brand_id'));
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
            $perPage = $request->get('limit', 12);
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
                'images:id,good_id,file_path,alt_text,is_main,sort_order',
                'variations:id,good_id,name,price,sale_price,stock_quantity,is_active',
                'properties:id,name,slug'
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
        // Получаем главное изображение
        $mainImage = $good->images->where('is_main', true)->first() 
                    ?? $good->images->sortBy('sort_order')->first();

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
            'image_url' => $mainImage ? $this->getImageUrl($mainImage->file_path) : null,
            'images' => $good->images->map(function ($image) {
                return $this->getImageUrl($image->file_path);
            })->toArray(),
            'rating' => $good->rating ? (float) $good->rating : null,
            'reviews_count' => $good->reviews_count ?? 0,
            'characteristics' => $characteristics,
            'in_stock' => $good->stock_quantity > 0,
            'stock_quantity' => $good->stock_quantity,
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

        // Формируем URL относительно storage
        return url('storage/' . $filePath);
    }
}
