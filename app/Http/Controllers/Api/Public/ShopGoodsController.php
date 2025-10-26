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
     * Переключить избранное для товара
     */
    public function toggleFavorite(Request $request): JsonResponse
    {
        try {
            $goodId = $request->input('good_id');
            
            if (!$goodId) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID товара не указан'
                ], 400);
            }

            // Получаем пользователя из токена
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Проверяем, существует ли товар
            $good = \App\Models\ShopGood::find($goodId);
            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            // Проверяем, есть ли уже товар в избранном
            $existingFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if ($existingFavorite) {
                // Удаляем из избранного
                $existingFavorite->delete();
                return response()->json([
                    'success' => true,
                    'is_favorite' => false,
                    'good_name' => $good->name,
                    'message' => 'Товар удален из избранного'
                ]);
            } else {
                // Добавляем в избранное
                \App\Models\ShopFavorite::create([
                    'user_id' => $user->id,
                    'good_id' => $goodId
                ]);
                return response()->json([
                    'success' => true,
                    'is_favorite' => true,
                    'good_name' => $good->name,
                    'message' => 'Товар добавлен в избранное'
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Ошибка переключения избранного: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список товаров
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Временное логирование для диагностики
            
            $query = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    // Поддержка обеих схем pivot: shop_property_value_id и/или value
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }
                            return $fields;
                        })());
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
                                  ->whereHas('values', function($pv) use ($values) {
                                      $pv->whereIn('value', $values);
                                  });
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
            
            // Получаем информацию о пользователе для проверки избранного
            $token = request()->bearerToken();
            $user = null;
            
            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (!$user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }
            }
            
            // Добавляем image_url, is_favorite и обрабатываем характеристики для обратной совместимости
            $goods->getCollection()->transform(function ($good) use ($user) {
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
                
                
                // Проверяем, находится ли товар в избранном у текущего пользователя
                $isFavorite = false;
                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }
                $good->is_favorite = $isFavorite;
                
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
     * Получить значения характеристик по их ID
     */
    public function getPropertyValues(Request $request): JsonResponse
    {
        try {
            $ids = $request->input('ids', '');
            
            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID значений характеристик'
                ], 400);
            }
            
            // Преобразуем строку в массив
            $idsArray = is_string($ids) ? explode(',', $ids) : $ids;
            $idsArray = array_filter(array_map('intval', $idsArray));
            
            if (empty($idsArray)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некорректные ID значений характеристик'
                ], 400);
            }
            
            // Получаем значения характеристик
            $propertyValues = \App\Models\Shop\PropertyValue::whereIn('id', $idsArray)
                ->where('is_active', true)
                ->get(['id', 'value'])
                ->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $propertyValues
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения значений характеристик'
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о товарах по их ID
     */
    public function getGoodsDetails(Request $request): JsonResponse
    {
        try {
            // Простое логирование для проверки
            Log::info('=== API getGoodsDetails ВЫЗВАН ===');
            
            $goodIds = $request->input('good_ids', []);
            $variationIds = $request->input('variation_ids', []);

            // Отладочная информация о запросе
            Log::info('getGoodsDetails вызван', [
                'good_ids' => $goodIds,
                'variation_ids' => $variationIds,
                'request_data' => $request->all()
            ]);

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID товаров'
                ], 400);
            }

            // Загружаем товары с вариациями, изображениями, видео и свойствами
            $goods = ShopGood::with([
                'variations' => function($query) {
                    $query->where('is_active', true)->with([
                        'images' => function($q) {
                            $q->orderBy('sort_order');
                        },
                        'videos' => function($q) {
                            $q->orderBy('sort_order');
                        }
                    ]);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }
                            return $fields;
                        })());
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

            // Получаем информацию о пользователе для проверки избранного
            $isFavorite = false;
            $token = request()->bearerToken();
            $user = null;
            
            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (!$user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }
            }

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

                // Проверяем, находится ли товар в избранном у текущего пользователя
                $isFavorite = false;
                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }


                // Отладочная информация о размерах и весе товара
                Log::info('Товар ' . $good->name . ' размеры и вес:', [
                    'weight' => $good->weight,
                    'width' => $good->width,
                    'height' => $good->height,
                    'depth' => $good->depth,
                    'raw_weight' => $good->getRawOriginal('weight'),
                    'raw_width' => $good->getRawOriginal('width'),
                    'raw_height' => $good->getRawOriginal('height'),
                    'raw_depth' => $good->getRawOriginal('depth')
                ]);

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
                    'variations' => [],
                    'is_favorite' => $isFavorite,
                    // Добавляем поля размеров и веса
                    'weight' => $good->weight,
                    'length' => $good->depth, // В базе данных поле называется depth, но в API возвращаем как length
                    'width' => $good->width,
                    'height' => $good->height
                ];

                // Добавляем вариации с атрибутами
                foreach ($good->variations as $variation) {
                    // Загружаем атрибуты вариации из новой схемы
                    $variationAttributes = [];
                    $variationIds = [$variation->id];
                    
                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();
                    
                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value
                        ];
                    }
                    
                    // Отладочная информация о размерах и весе вариации
                    Log::info('Вариация ' . $variation->name . ' размеры и вес:', [
                        'weight' => $variation->weight,
                        'length' => $variation->length,
                        'width' => $variation->width,
                        'height' => $variation->height,
                        'raw_weight' => $variation->getRawOriginal('weight'),
                        'raw_length' => $variation->getRawOriginal('length'),
                        'raw_width' => $variation->getRawOriginal('width'),
                        'raw_height' => $variation->getRawOriginal('height')
                    ]);

                    $goodData['variations'][] = [
                        'id' => $variation->id,
                        'name' => $variation->name,
                        'sku' => $variation->sku,
                        'price' => $variation->price,
                        'sale_price' => $variation->sale_price,
                        'old_price' => $variation->old_price,
                        'final_price' => $variation->final_price,
                        'attributes' => $variationAttributes,
                        'is_active' => $variation->is_active,
                        'images' => $variation->images ? $variation->images->toArray() : [],
                        'videos' => $variation->videos ? $variation->videos->toArray() : [],
                        // Добавляем поля размеров и веса для вариаций
                        'weight' => $variation->weight,
                        'length' => $variation->length,
                        'width' => $variation->width,
                        'height' => $variation->height
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
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }
                            return $fields;
                        })());
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

            // Добавляем атрибуты к вариациям
            $goodData = $good->toArray();
            if (isset($goodData['variations'])) {
                foreach ($goodData['variations'] as &$variation) {
                    $variationAttributes = [];
                    $variationIds = [$variation['id']];
                    
                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();
                    
                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value
                        ];
                    }
                    
                    $variation['attributes'] = $variationAttributes;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $goodData
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
                    $query->where('is_active', true)
                          ->select('*'); // Включаем все поля, включая remote_stock_quantity
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }
                            return $fields;
                        })());
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

            // Проверяем, находится ли товар в избранном у текущего пользователя
            $isFavorite = false;
            $token = request()->bearerToken();
            
            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (!$user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }
                
                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }
            }

            // Добавляем поле is_favorite к товару и нормализуем свойства
            $goodData = $good->toArray();
            $goodData['is_favorite'] = $isFavorite;
            
            // Нормализуем свойства до {id, name, value}
            if (isset($goodData['properties'])) {
                $goodData['properties'] = collect($goodData['properties'])->toArray();
            }

            // Добавляем атрибуты к вариациям
            if (isset($goodData['variations'])) {
                foreach ($goodData['variations'] as &$variation) {
                    $variationAttributes = [];
                    $variationIds = [$variation['id']];
                    
                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();
                    
                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value
                        ];
                    }
                    
                    $variation['attributes'] = $variationAttributes;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $goodData
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
                    $query->where('is_active', true);
                },
                'images' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.shop_property_value_id');
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

    // DEPRECATED: These methods are no longer used - replaced by getVariationsMedia
    // public function getVariationsImages(Request $request): JsonResponse { ... }
    // public function getVariationsVideos(Request $request): JsonResponse { ... }

    /**
     * Получить все медиа (изображения + видео) для нескольких вариаций одним запросом
     */
    public function getVariationsMedia(Request $request): JsonResponse
    {
        try {
            $variationIds = $request->input('variation_ids', []);
            
            if (empty($variationIds) || !is_array($variationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID вариаций'
                ], 400);
            }

            $variations = \App\Models\ShopGoodVariation::with([
                'images' => function($query) {
                    $query->orderBy('sort_order');
                },
                'videos' => function($query) {
                    $query->orderBy('sort_order');
                }
            ])
            ->whereIn('id', $variationIds)
            ->where('is_active', true)
            ->get();

            $result = [];
            
            foreach ($variations as $variation) {
                $images = $variation->images ? $variation->images->toArray() : [];
                $videos = $variation->videos ? $variation->videos->toArray() : [];
                
                $result[$variation->id] = [
                    'images' => $images,
                    'videos' => $videos
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variations media: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения медиа вариаций'
            ], 500);
        }
    }
}