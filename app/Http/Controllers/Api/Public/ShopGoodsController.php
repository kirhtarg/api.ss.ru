<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopGoodsController extends Controller
{
    /**
     * Получить список товаров
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Временное логирование для диагностики
            
            $query = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with(['properties' => function($q) {
                        $q->with('property');
                    }]);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.value');
                },
                'categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug');
                },
                'brands' => function($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug');
                }
            ])
            ->where('is_active', true);

            // Фильтрация по категории
            if ($request->has('category_id')) {
                $query->whereHas('categories', function($q) use ($request) {
                    $q->where('shop_categories.id', $request->input('category_id'));
                });
            }

                // Фильтрация по множественным категориям
                if ($request->has('categories')) {
                    $categoryIds = $request->input('categories');
                    
                    // Если передан строкой через запятую, преобразуем в массив
                    if (is_string($categoryIds)) {
                        $categoryIds = array_filter(explode(',', $categoryIds));
                    }
                    
                    if (is_array($categoryIds) && !empty($categoryIds)) {
                        $query->whereHas('categories', function($q) use ($categoryIds) {
                            $q->whereIn('shop_categories.id', $categoryIds);
                        });
                    }
                }
                
                // Фильтрация по множественным категориям (альтернативный формат categories[])
                if ($request->has('categories[]')) {
                    $categoryIds = $request->input('categories[]');
                    if (is_array($categoryIds) && !empty($categoryIds)) {
                        $query->whereHas('categories', function($q) use ($categoryIds) {
                            $q->whereIn('shop_categories.id', $categoryIds);
                        });
                    }
                }

            // Фильтрация по бренду
            if ($request->has('brand_id')) {
                $query->whereHas('brands', function($q) use ($request) {
                    $q->where('shop_brands.id', $request->input('brand_id'));
                });
            }

                // Фильтрация по множественным брендам
                if ($request->has('brands')) {
                    $brandIds = $request->input('brands');
                    if (is_array($brandIds) && !empty($brandIds)) {
                        $query->whereHas('brands', function($q) use ($brandIds) {
                            $q->whereIn('shop_brands.id', $brandIds);
                        });
                    }
                }
                
                // Фильтрация по множественным брендам (альтернативный формат brands[])
                if ($request->has('brands[]')) {
                    $brandIds = $request->input('brands[]');
                    if (is_array($brandIds) && !empty($brandIds)) {
                        $query->whereHas('brands', function($q) use ($brandIds) {
                            $q->whereIn('shop_brands.id', $brandIds);
                        });
                    }
                }

            // Поиск по названию
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%");
            }

            // Фильтрация по цене
            if ($request->has('min_price')) {
                $query->where('price', '>=', $request->input('min_price'));
            }
            if ($request->has('max_price')) {
                $query->where('price', '<=', $request->input('max_price'));
            }

            // Фильтрация по свойствам
            if ($request->has('properties')) {
                $properties = $request->input('properties');
                if (is_array($properties) && !empty($properties)) {
                    foreach ($properties as $propertyId => $values) {
                        if (is_array($values) && !empty($values)) {
                            $query->whereHas('properties', function($q) use ($propertyId, $values) {
                                $q->where('shop_properties.id', $propertyId)
                                  ->whereIn('shop_good_properties.value', $values);
                            });
                        }
                    }
                }
            }

            // Исключение товара по ID
            if ($request->has('exclude_id')) {
                $excludeId = $request->input('exclude_id');
                if ($excludeId) {
                    $query->where('id', '!=', $excludeId);
                }
            }

            // Сортировка
            if ($request->has('random') && $request->input('random')) {
                $query->inRandomOrder();
            } else {
                $sortBy = $request->input('sort_by', 'created_at');
                $sortOrder = $request->input('sort_order', 'desc');
                $query->orderBy($sortBy, $sortOrder);
            }

            // Пагинация
            $perPage = $request->input('limit', 20);
            $goods = $query->paginate($perPage);
            
            // Добавляем image_url для обратной совместимости
            $goods->getCollection()->transform(function ($good) {
                if ($good->images && $good->images->count() > 0) {
                    // Ищем главное изображение
                    $mainImage = $good->images->where('is_main', true)->first();
                    if (!$mainImage) {
                        // Если главного нет, берем первое
                        $mainImage = $good->images->first();
                    }
                    if ($mainImage) {
                        // Добавляем ведущий слэш если его нет
                        $imagePath = $mainImage->file_path;
                        if ($imagePath && !str_starts_with($imagePath, '/')) {
                            $imagePath = '/' . $imagePath;
                        }
                        $good->image_url = $imagePath;
                    }
                }
                return $good;
            });
            
            return response()->json([
                'success' => true,
                'data' => $goods->items(),
                    'pagination' => [
                        'current_page' => $goods->currentPage(),
                        'last_page' => $goods->lastPage(),
                        'per_page' => $goods->perPage(),
                    'total' => $goods->total()
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка товаров'
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о товарах по их ID
     */
    public function getGoodsDetails(Request $request): JsonResponse
    {
        try {
            $goodIds = $request->input('good_ids', []);
            $variationIds = $request->input('variation_ids', []);

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID товаров'
                ], 400);
            }

            // Загружаем товары с вариациями, изображениями, видео и свойствами
            $goods = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with(['properties' => function($q) {
                        $q->with('property');
                    }]);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.value');
                },
                'categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug');
                },
                'brands' => function($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug');
                }
            ])
            ->whereIn('id', $goodIds)
            ->where('is_active', true)
            ->get();

            $result = [];

            foreach ($goods as $good) {
                // Получаем главное изображение из связанной таблицы
                $mainImage = null;
                if ($good->images && $good->images->count() > 0) {
                    $mainImg = $good->images->where('is_main', true)->first();
                    if (!$mainImg) {
                        $mainImg = $good->images->first();
                    }
                    if ($mainImg) {
                        $mainImage = $mainImg->file_path;
                    }
                }

                $goodData = [
                    'id' => $good->id,
                    'name' => $good->name,
                    'sku' => $good->sku,
                    'slug' => $good->slug,
                    'price' => $good->price,
                    'sale_price' => $good->sale_price,
                    'old_price' => $good->old_price,
                    'image_url' => $mainImage ?: $good->image_url,
                    'images' => $good->images ? $good->images->toArray() : [],
                    'videos' => $good->videos ? $good->videos->toArray() : [],
                    'properties' => $good->properties ? $good->properties->toArray() : [],
                    'categories' => $good->categories ? $good->categories->toArray() : [],
                    'brands' => $good->brands ? $good->brands->toArray() : [],
                    'variations' => []
                ];

                // Добавляем вариации
                foreach ($good->variations as $variation) {
                    $variationProperties = [];
                    if ($variation->properties) {
                        foreach ($variation->properties as $property) {
                            
                            $variationProperties[] = [
                                'id' => $property->property_id,
                                'name' => $property->property->name ?? 'Unknown',
                                'slug' => $property->property->slug ?? '',
                                'value' => $property->value
                            ];
                        }
                    }
                    
                    $goodData['variations'][] = [
                        'id' => $variation->id,
                        'name' => $variation->name,
                        'sku' => $variation->sku,
                        'price' => $variation->price,
                        'sale_price' => $variation->sale_price,
                        'old_price' => $variation->old_price,
                        'final_price' => $variation->final_price,
                        'properties' => $variationProperties,
                        'is_active' => $variation->is_active
                    ];
                }

                $result[] = $goodData;
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения информации о товарах'
            ], 500);
        }
    }

    /**
     * Получить главные блоки товаров
     */
    public function getMainBlocks(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            
            // Получаем хиты продаж (featured)
            $featured = ShopGood::with(['images', 'variations', 'categories', 'brands'])
                ->where('is_featured', 1)
                ->where('is_active', 1)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            // Получаем товары со скидками (sale)
            $sale = ShopGood::with(['images', 'variations', 'categories', 'brands'])
                ->where('is_sale', 1)
                ->where('is_active', 1)
                ->whereNotNull('sale_price')
                ->where('sale_price', '>', 0)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            // Получаем новинки (new)
            $new = ShopGood::with(['images', 'variations', 'categories', 'brands'])
                ->where('is_new', 1)
                ->where('is_active', 1)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'featured' => $featured,
                    'sale' => $sale,
                    'new' => $new
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка получения главных блоков: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения главных блоков'
            ], 500);
        }
    }

    /**
     * Получить товар по ID
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $variationId = $request->input('variation_id');
            $variationId = $variationId ? (int)$variationId : null;
            
            
            
            $good = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with([
                        'properties' => function($q) {
                            $q->with('property');
                        },
                        'images' => function($q) {
                            $q->orderBy('sort_order');
                        },
                        'videos' => function($q) {
                            $q->orderBy('sort_order');
                        }
                    ]);
                },
                'images' => function($query) {
                    $query->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.value');
                },
                'categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug');
                },
                'brands' => function($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug');
                }
            ])
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            // Если передан variation_id, используем медиа вариации
            if ($variationId) {
                // Находим вариацию
                $variation = $good->variations->where('id', $variationId)->first();
                
                if ($variation) {
                    // Заменяем основные медиа товара на медиа вариации
                    if ($variation->images && $variation->images->count() > 0) {
                        $good->setRelation('images', $variation->images);
                    } else {
                        // Если у вариации нет изображений, не показываем изображения вообще
                        $good->setRelation('images', collect([]));
                    }
                    
                    if ($variation->videos && $variation->videos->count() > 0) {
                        $good->setRelation('videos', $variation->videos);
                    } else {
                        // Если у вариации нет видео, не показываем видео вообще
                        $good->setRelation('videos', collect([]));
                    }
                } else {
                    // Если вариация не найдена, используем медиа основного товара
                    $good->setRelation('images', $good->images->whereNull('variation_id'));
                    $good->setRelation('videos', $good->videos->whereNull('variation_id'));
                }
            }


            return response()->json([
                'success' => true,
                'data' => $good
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товара'
            ], 500);
        }
    }

    /**
     * Получить товар по slug
     */
    public function getGoodBySlug($slug): JsonResponse
    {
        try {
            $good = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with(['properties' => function($q) {
                        $q->with('property');
                    }]);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.value');
                },
                'categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug');
                },
                'brands' => function($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug');
                }
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
                'data' => $good
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товара'
            ], 500);
        }
    }

    /**
     * Получить изображения товара
     */
    public function getGoodImages($id): JsonResponse
    {
        try {
            $good = ShopGood::with(['images' => function($query) {
                $query->whereNull('variation_id')->orderBy('sort_order');
            }])
            ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            $images = $good->images ? $good->images->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $images
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений товара'
            ], 500);
        }
    }

    /**
     * Получить товары пакетом
     */
    public function getBatch(Request $request): JsonResponse
    {
        try {
            $goodIds = $request->input('good_ids', []);
            
            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID товаров'
                ], 400);
            }

            $goods = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with(['properties' => function($q) {
                        $q->with('property');
                    }]);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.value');
                },
                'categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug');
                },
                'brands' => function($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug');
                }
            ])
            ->whereIn('id', $goodIds)
            ->where('is_active', true)
            ->get();

            // Добавляем image_url для обратной совместимости
            $goods->transform(function ($good) {
                if ($good->images && $good->images->count() > 0) {
                    // Ищем главное изображение
                    $mainImage = $good->images->where('is_main', true)->first();
                    if (!$mainImage) {
                        // Если главного нет, берем первое
                        $mainImage = $good->images->first();
                    }
                    if ($mainImage) {
                        // Добавляем ведущий слэш если его нет
                        $imagePath = $mainImage->file_path;
                        if ($imagePath && !str_starts_with($imagePath, '/')) {
                            $imagePath = '/' . $imagePath;
                        }
                        $good->image_url = $imagePath;
                    }
                }
                return $good;
            });

            return response()->json([
                'success' => true,
                'data' => $goods
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товаров'
            ], 500);
        }
    }

    /**
     * Получить категорию по slug
     */
    public function getCategoryBySlug($slug): JsonResponse
    {
        try {
            // Здесь нужно будет добавить модель Category, если её нет
            // Пока возвращаем заглушку
            return response()->json([
                'success' => true,
                'data' => null
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения категории'
            ], 500);
        }
    }

    /**
     * Получить изображения вариации
     */
    public function getVariationImages($variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::with(['images' => function($query) {
                $query->orderBy('sort_order');
            }])
            ->where('id', $variationId)
            ->where('is_active', true)
            ->first();

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вариация не найдена'
                ], 404);
            }

            $images = $variation->images ? $variation->images->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $images
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variation images: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений вариации'
            ], 500);
        }
    }

    /**
     * Получить видео вариации
     */
    public function getVariationVideos($variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::with(['videos' => function($query) {
                $query->orderBy('sort_order');
            }])
            ->where('id', $variationId)
            ->where('is_active', true)
            ->first();

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вариация не найдена'
                ], 404);
            }

            $videos = $variation->videos ? $variation->videos->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $videos
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variation videos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения видео вариации'
            ], 500);
        }
    }

    /**
     * Получить изображения для нескольких вариаций одним запросом
     */
    public function getVariationsImages(Request $request): JsonResponse
    {
        try {
            $variationIds = $request->input('variation_ids', []);
            
            if (empty($variationIds) || !is_array($variationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID вариаций'
                ], 400);
            }

            $variations = \App\Models\ShopGoodVariation::with(['images' => function($query) {
                $query->orderBy('sort_order');
            }])
            ->whereIn('id', $variationIds)
            ->where('is_active', true)
            ->get();

            $result = [];
            
            foreach ($variations as $variation) {
                $images = $variation->images ? $variation->images->toArray() : [];
                $result[$variation->id] = $images;
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variations images: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений вариаций'
            ], 500);
        }
    }
}