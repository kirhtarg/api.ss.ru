<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopBrand;
use App\Models\ShopTag;
use App\Models\ShopLabel;
use App\Models\ShopProperty;
use App\Models\ShopPropertyValue;
use App\Models\ShopCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ShopGoodsController extends Controller
{
    /**
     * Получить список товаров с фильтрацией и пагинацией
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopGood::select([
            'id', 'name', 'slug', 'sku', 'description', 'short_description', 
            'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
            'stock_quantity', 'remote_stock_quantity', 'rating', 'reviews_count',
            'width', 'height', 'depth', 'weight',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'sort_order', 
            'created_at', 'updated_at'
        ])->with([
            'categories:id,name',
            'brands:id,name',
            'tags:id,name,color',
            'label:id,name,color',
            'properties:id,name,slug',
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
            'variations:id,good_id,name,sku,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,is_active'
        ])->withCount('variations');

        // Загружаем pivot данные для свойств (поддерживаем разные схемы: value или shop_property_value_id)
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $query->with(['properties' => function($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Фильтр по массиву ID (для массовой загрузки) - должен быть первым
        // Проверяем оба варианта: ids[] и ids
        $ids = null;
        if ($request->has('ids')) {
            $ids = $request->input('ids');
        } elseif ($request->has('ids[]')) {
            $ids = $request->input('ids[]');
        }
        
        if ($ids !== null) {
            // Если это не массив, пытаемся преобразовать
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            
            if (!empty($ids)) {
                $ids = array_map('intval', $ids);
                $ids = array_filter($ids, function($id) {
                    return $id > 0;
                });
                
                if (!empty($ids)) {
                    $query->whereIn('id', $ids);
                }
            }
        }

        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->search($search);
        }

        // Фильтр по категории
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Фильтр по множественным категориям
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function($q) use ($categoryIds) {
                    $q->whereIn('shop_categories.id', $categoryIds);
                });
            }
        }

        // Фильтр по бренду
        if ($request->filled('brand_id')) {
            $query->byBrand($request->get('brand_id'));
        }

        // Фильтр по множественным брендам
        if ($request->has('brands')) {
            $brandIds = $request->input('brands');
            if (is_array($brandIds) && !empty($brandIds)) {
                $query->whereHas('brands', function($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Фильтр по тегу
        if ($request->filled('tag_id')) {
            $query->byTag($request->get('tag_id'));
        }

        // Фильтр по множественным тегам
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && !empty($tagIds)) {
                $query->whereHas('tags', function($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Фильтр по лейблам
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && !empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Фильтр по демпингу (по полю show_demping)
        if ($request->filled('has_demping')) {
            $hasDemping = $request->get('has_demping');
            if ($hasDemping === 'true') {
                // Товары с демпингом в основном товаре
                $query->where('show_demping', true);
            } elseif ($hasDemping === 'false') {
                $query->where(function($q) {
                    $q->where('show_demping', false)
                      ->orWhereNull('show_demping');
                });
            } elseif ($hasDemping === 'variations') {
                // Товары с демпингом в вариациях
                $query->whereHas('variations', function($q) {
                    $q->where('show_demping', true);
                });
            } elseif ($hasDemping === 'both') {
                // Товары с демпингом в основном товаре ИЛИ в вариациях (или в обоих)
                $query->where(function($q) {
                    $q->where('show_demping', true)
                      ->orWhereHas('variations', function($subQ) {
                          $subQ->where('show_demping', true);
                      });
                });
            }
        }

        // Фильтр по наличию тегов
        if ($request->filled('has_tags')) {
            $hasTags = $request->get('has_tags');
            if ($hasTags === 'true') {
                $query->whereHas('tags');
            } elseif ($hasTags === 'false') {
                $query->whereDoesntHave('tags');
            }
        }

        // Фильтр по демпинговой цене (пустое/не пустое)
        if ($request->filled('has_demping_price')) {
            $hasDempingPrice = $request->get('has_demping_price');
            if ($hasDempingPrice === 'true') {
                $query->whereNotNull('demping_price')
                      ->where('demping_price', '>', 0);
            } elseif ($hasDempingPrice === 'false') {
                $query->where(function($q) {
                    $q->whereNull('demping_price')
                      ->orWhere('demping_price', '=', 0);
                });
            }
        }

        // Фильтр по наличию лейбла
        if ($request->filled('has_label')) {
            $hasLabel = $request->get('has_label');
            if ($hasLabel === 'true') {
                $query->whereNotNull('label_id');
            } elseif ($hasLabel === 'false') {
                $query->whereNull('label_id');
            }
        }

        // Фильтр по цене
        $minPrice = $request->has('min_price') ? $request->get('min_price') : null;
        $maxPrice = $request->has('max_price') ? $request->get('max_price') : null;
        
        if ($minPrice !== null || $maxPrice !== null) {
            $query->priceRange($minPrice, $maxPrice);
        }

        // Фильтр по акционной цене
        $minSalePrice = $request->has('min_sale_price') ? $request->get('min_sale_price') : null;
        $maxSalePrice = $request->has('max_sale_price') ? $request->get('max_sale_price') : null;
        
        if ($minSalePrice !== null || $maxSalePrice !== null) {
            // Точное значение (когда min и max равны)
            if ($minSalePrice !== null && $maxSalePrice !== null && $minSalePrice == $maxSalePrice) {
                $query->where('sale_price', '=', $minSalePrice);
            } else {
                // Обрабатываем min и max отдельно
                if ($minSalePrice !== null) {
                    // Для min_sale_price > 0 (not_zero) - исключаем null и 0
                    if ($minSalePrice > 0) {
                        $query->whereNotNull('sale_price')
                              ->where('sale_price', '>=', $minSalePrice);
                    } else {
                        $query->where(function($q) use ($minSalePrice) {
                            $q->whereNotNull('sale_price')
                              ->where('sale_price', '>=', $minSalePrice);
                        });
                    }
                }
                if ($maxSalePrice !== null) {
                    if ($maxSalePrice == 0) {
                        // Фильтр "равна 0" - включаем null и 0
                        $query->where(function($q) {
                            $q->whereNull('sale_price')
                              ->orWhere('sale_price', '=', 0);
                        });
                    } else {
                        // Для max_sale_price > 0
                        if ($minSalePrice !== null && $minSalePrice > 0) {
                            // Если есть min > 0, то null уже исключен
                            $query->where('sale_price', '<=', $maxSalePrice);
                        } else {
                            // Если нет min или min <= 0, то включаем null в результат
                            $query->where(function($q) use ($maxSalePrice) {
                                $q->whereNull('sale_price')
                                  ->orWhere('sale_price', '<=', $maxSalePrice);
                            });
                        }
                    }
                }
            }
        }

        // Фильтр по рейтингу
        if ($request->filled('min_rating')) {
            $query->rating($request->get('min_rating'));
        }

        // Фильтр по наличию
        if ($request->filled('in_stock')) {
            $inStock = $request->get('in_stock');
            if ($inStock === 'true') {
                $query->where('stock_quantity', '>', 0);
            } elseif ($inStock === 'false') {
                $query->where('stock_quantity', '=', 0);
            } elseif ($inStock === 'low') {
                $query->where('stock_quantity', '>', 0)
                      ->where('stock_quantity', '<', 3);
            } elseif (strpos($inStock, 'exact:') === 0) {
                // Точное значение остатка в формате exact:value
                $exactValue = (int)substr($inStock, 6);
                $query->where('stock_quantity', '=', $exactValue);
            }
        }

        // Фильтр по остатку (stock_quantity_min и stock_quantity_max для точного значения)
        if ($request->filled('stock_quantity_min') || $request->filled('stock_quantity_max')) {
            if ($request->filled('stock_quantity_min') && $request->filled('stock_quantity_max')) {
                $min = (int)$request->get('stock_quantity_min');
                $max = (int)$request->get('stock_quantity_max');
                if ($min === $max) {
                    // Точное значение
                    $query->where('stock_quantity', '=', $min);
                } else {
                    // Диапазон
                    $query->whereBetween('stock_quantity', [$min, $max]);
                }
            } elseif ($request->filled('stock_quantity_min')) {
                $query->where('stock_quantity', '>=', (int)$request->get('stock_quantity_min'));
            } elseif ($request->filled('stock_quantity_max')) {
                $query->where('stock_quantity', '<=', (int)$request->get('stock_quantity_max'));
            }
        }

        // Фильтр по вариациям
        if ($request->filled('has_variations')) {
            $hasVariations = $request->get('has_variations');
            if ($hasVariations === 'true') {
                $query->whereHas('variations');
            } elseif ($hasVariations === 'false') {
                $query->whereDoesntHave('variations');
            }
        }

        // Фильтр по категориям
        if ($request->filled('has_categories')) {
            $hasCategories = $request->get('has_categories');
            if ($hasCategories === 'true') {
                $query->whereHas('categories');
            } elseif ($hasCategories === 'false') {
                $query->whereDoesntHave('categories');
            }
        }

        // Фильтр по брендам
        if ($request->filled('has_brands')) {
            $hasBrands = $request->get('has_brands');
            if ($hasBrands === 'true') {
                $query->whereHas('brands');
            } elseif ($hasBrands === 'false') {
                $query->whereDoesntHave('brands');
            }
        }

        // Фильтр по исключению категорий (товары БЕЗ выбранных категорий)
        if ($request->has('exclude_categories')) {
            $excludeCategoryIds = $request->input('exclude_categories');
            if (is_array($excludeCategoryIds) && !empty($excludeCategoryIds)) {
                $query->whereDoesntHave('categories', function($q) use ($excludeCategoryIds) {
                    $q->whereIn('shop_categories.id', $excludeCategoryIds);
                });
            }
        }

        // Фильтр по исключению брендов (товары БЕЗ выбранных брендов)
        if ($request->has('exclude_brands')) {
            $excludeBrandIds = $request->input('exclude_brands');
            if (is_array($excludeBrandIds) && !empty($excludeBrandIds)) {
                $query->whereDoesntHave('brands', function($q) use ($excludeBrandIds) {
                    $q->whereIn('shop_brands.id', $excludeBrandIds);
                });
            }
        }

        // Фильтр по исключению характеристик (товары БЕЗ выбранных характеристик)
        if ($request->has('exclude_properties')) {
            $excludeProperties = $request->input('exclude_properties');
            if (is_array($excludeProperties) && !empty($excludeProperties)) {
                $query->where(function($q) use ($excludeProperties) {
                    foreach ($excludeProperties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && !empty($valueIds)) {
                            $q->whereDoesntHave('properties', function($propQuery) use ($propertyId, $valueIds) {
                                $propQuery->where('shop_properties.id', $propertyId)
                                          ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                            });
                        }
                    }
                });
            }
        }

        // Фильтр по исключению лейблов (товары БЕЗ выбранных лейблов)
        if ($request->has('exclude_labels')) {
            $excludeLabelIds = $request->input('exclude_labels');
            if (is_array($excludeLabelIds) && !empty($excludeLabelIds)) {
                $query->where(function($q) use ($excludeLabelIds) {
                    $q->whereNull('label_id')
                      ->orWhereNotIn('label_id', $excludeLabelIds);
                });
            }
        }

        // Фильтр по исключению тегов (товары БЕЗ выбранных тегов)
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && !empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Фильтр по наличию описания
        if ($request->filled('has_description')) {
            $hasDescription = $request->get('has_description');
            if ($hasDescription === '1' || $hasDescription === 'true') {
                $query->whereNotNull('description')
                      ->where('description', '!=', '');
            }
        }

        // Фильтр по статусу
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Фильтр по is_new
        if ($request->filled('is_new')) {
            $query->where('is_new', $request->boolean('is_new'));
        }

        // Фильтр по is_featured
        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Фильтр по is_sale
        if ($request->filled('is_sale')) {
            $query->where('is_sale', $request->boolean('is_sale'));
        }

        // Фильтр по is_preorder
        if ($request->filled('is_preorder')) {
            $query->where('is_preorder', $request->boolean('is_preorder'));
        }

        // Фильтр по характеристикам (properties[property_id][])
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties) && !empty($properties)) {
                // Логика ИЛИ - товар должен иметь хотя бы одну из выбранных характеристик
                $query->where(function($q) use ($properties) {
                    foreach ($properties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && !empty($valueIds)) {
                            $q->orWhereHas('properties', function($propQuery) use ($propertyId, $valueIds) {
                                $propQuery->where('shop_properties.id', $propertyId)
                                          ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                            });
                        }
                    }
                });
            }
        }

        // Фильтр по количеству характеристик
        if ($request->filled('properties_count_type')) {
            $countType = $request->get('properties_count_type');
            if ($countType === 'none') {
                $query->whereDoesntHave('properties');
            } elseif ($countType === 'with') {
                $query->whereHas('properties');
            } elseif ($countType === 'exact' && $request->filled('properties_count')) {
                $exactCount = (int)$request->get('properties_count');
                $query->has('properties', '=', $exactCount);
            }
        }

        // Фильтр по артикулу
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function($q) {
                    $q->whereNull('sku')
                      ->orWhere('sku', '=', '');
                });
            } elseif ($skuFilterType === 'not_empty') {
                $query->whereNotNull('sku')
                      ->where('sku', '!=', '');
            }
        }

        // Фильтр по остатку у/с (remote_stock_quantity)
        if ($request->filled('remote_stock_quantity_not_empty')) {
            $query->where(function($q) {
                $q->whereNotNull('remote_stock_quantity')
                  ->where('remote_stock_quantity', '!=', '')
                  ->where('remote_stock_quantity', '!=', '0');
            });
        } elseif ($request->filled('remote_stock_quantity_empty')) {
            $query->where(function($q) {
                $q->whereNull('remote_stock_quantity')
                  ->orWhere('remote_stock_quantity', '=', '')
                  ->orWhere('remote_stock_quantity', '=', '0');
            });
        } elseif ($request->filled('remote_stock_quantity')) {
            // Точное значение
            $exactValue = $request->get('remote_stock_quantity');
            $query->where('remote_stock_quantity', '=', $exactValue);
        }

        // Фильтр по общему остатку (total_stock)
        if ($request->filled('total_stock_not_empty')) {
            // Остаток > 0 ИЛИ остаток на у/с не пустой/не "0"
            $query->where(function($q) {
                $q->where('stock_quantity', '>', 0)
                  ->orWhere(function($remoteQ) {
                      $remoteQ->whereNotNull('remote_stock_quantity')
                          ->where('remote_stock_quantity', '!=', '')
                          ->where('remote_stock_quantity', '!=', '0')
                          ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                  });
            });
        } elseif ($request->filled('total_stock_both_empty')) {
            // Оба остатка пустые: stock_quantity = 0 И remote_stock_quantity пустой/равен "0"
            $query->where(function($q) {
                $q->where('stock_quantity', '=', 0)
                  ->where(function($remoteQ) {
                      $remoteQ->whereNull('remote_stock_quantity')
                          ->orWhere('remote_stock_quantity', '=', '')
                          ->orWhere('remote_stock_quantity', '=', '0')
                          ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                  });
            });
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortBy, ['name', 'sku', 'price', 'rating', 'stock_quantity', 'created_at', 'sort_order'])) {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Пагинация (если не запрашиваются конкретные ID, используем пагинацию)
        if (!$request->has('ids')) {
            $perPage = $request->get('per_page', 20);
            $perPage = in_array($perPage, [10, 20, 50, 100]) ? $perPage : 20;
            $goods = $query->paginate($perPage);
            
            // Добавляем вычисления для вариаций
            foreach ($goods->items() as $good) {
                if ($good->variations_count > 0 && $good->variations) {
                    // Сумма остатков вариаций
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');
                    
                    // Проверка наличия непустых remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function($variation) {
                        $remoteStock = $variation->remote_stock_quantity;
                        return $remoteStock !== null 
                            && $remoteStock !== '' 
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';
                    })->count() > 0;
                    
                    $good->variations_has_remote_stock = $hasRemoteStock;
                    
                    // Проверка наличия активного демпинга
                    $hasDemping = $good->variations->filter(function($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;
                    
                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $goods->items(),
                'pagination' => [
                    'current_page' => $goods->currentPage(),
                    'last_page' => $goods->lastPage(),
                    'per_page' => $goods->perPage(),
                    'total' => $goods->total(),
                    'from' => $goods->firstItem(),
                    'to' => $goods->lastItem()
                ]
            ]);
        } else {
            // Если запрашиваются конкретные ID, возвращаем все без пагинации
            $goods = $query->get();
            
            // Загружаем значения свойств для всех товаров
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Если свойство использует value напрямую
                        $property->property_value = (object)[
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id
                        ];
                    }
                }
                
                // Добавляем вычисления для вариаций
                if ($good->variations_count > 0 && $good->variations) {
                    // Сумма остатков вариаций
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');
                    
                    // Проверка наличия непустых remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function($variation) {
                        $remoteStock = $variation->remote_stock_quantity;
                        return $remoteStock !== null 
                            && $remoteStock !== '' 
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';
                    })->count() > 0;
                    
                    $good->variations_has_remote_stock = $hasRemoteStock;
                    
                    // Проверка наличия активного демпинга
                    $hasDemping = $good->variations->filter(function($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;
                    
                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $goods->toArray()
            ]);
        }
    }

    /**
     * Получить товар по ID
     */
    public function show($id): JsonResponse
    {
        $good = ShopGood::with([
            'categories:id,name,slug',
            'brands:id,name,slug',
            'tags:id,name,color,slug',
            'properties:id,name,slug',
            'images:id,good_id,variation_id,file_path,alt_text,is_main,sort_order',
            'videos:id,good_id,variation_id,video_path,external_url,title,sort_order',
            'variations:id,good_id,name,description,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,sku,is_active',
            'stock:id,good_id,warehouse_id,quantity,reserved_quantity,min_quantity',
            'stock.warehouse:id,name',
            'prices:id,good_id,price_type_id,price,sale_price',
            'prices.priceType:id,name,multiplier'
        ])->findOrFail($id);

        // Загружаем дополнительные данные для свойств товара
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $good->load(['properties' => function($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Загружаем значения свойств отдельно, если используем справочник
        foreach ($good->properties as $property) {
            if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                $property->property_value = $propertyValue;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $good
        ]);
    }

    /**
     * Создать новый товар
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_goods,slug',
            'sku' => 'nullable|string|max:255|unique:shop_goods,sku',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_sale' => 'boolean',
            'sort_order' => 'integer',
            'category_ids' => 'array',
            'category_ids.*' => 'exists:shop_categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.value' => 'nullable|string|max:255',
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $good = ShopGood::create($request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
                'stock_quantity', 'remote_stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'sort_order'
            ]));

            // Привязка категорий
            if ($request->filled('category_ids')) {
                $good->categories()->attach($request->get('category_ids'));
            }

            // Привязка брендов
            if ($request->filled('brand_ids')) {
                $good->brands()->attach($request->get('brand_ids'));
            }

            // Привязка тегов
            if ($request->filled('tag_ids')) {
                $good->tags()->attach($request->get('tag_ids'));
            }

            // Привязка свойств
            if ($request->filled('properties')) {
                foreach ($request->get('properties') as $property) {
                    // Проверяем обязательные поля
                    if (empty($property['property_id'])) {
                        continue;
                    }
                    
                    $propertyValueId = null;
                    
                    // Если есть shop_property_value_id, используем его
                    if (!empty($property['shop_property_value_id'])) {
                        $propertyValueId = $property['shop_property_value_id'];
                    }
                    // Если есть value, ищем или создаем запись в shop_property_values
                    elseif (!empty($property['value'])) {
                        $propertyValue = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $property['property_id'],
                            'value' => trim($property['value'])
                        ], [
                            'is_active' => true,
                            'sort_order' => 0
                        ]);
                        $propertyValueId = $propertyValue->id;
                    }
                    
                    // Привязываем свойство только если есть propertyValueId
                    if ($propertyValueId) {
                        $good->properties()->attach($property['property_id'], [
                            'shop_property_value_id' => $propertyValueId
                        ]);
                    }
                }
            }

            // Аудит
            $this->logAudit($good, 'created', null, $good->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно создан',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить товар
     */
    public function update(Request $request, $id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);
        $oldValues = $good->toArray();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_goods', 'slug')->ignore($id)],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('shop_goods', 'sku')->ignore($id)],
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'demping_price' => 'nullable|numeric|min:0',
            'show_demping' => 'boolean',
            'label_id' => 'nullable|exists:shop_labels,id',
            'stock_quantity' => 'integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_sale' => 'boolean',
            'is_preorder' => 'boolean',
            'sort_order' => 'integer',
            'category_ids' => 'array',
            'category_ids.*' => 'exists:shop_categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.value' => 'nullable|string|max:255',
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Подготавливаем данные для обновления
            $updateData = $request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
                'stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'sort_order'
            ]);
            
            // Явно обрабатываем remote_stock_quantity - всегда обновляем, даже если null
            // Используем прямой доступ к полю из JSON тела запроса
            $allRequestData = $request->all();
            if (isset($allRequestData['remote_stock_quantity'])) {
                $remoteStockValue = $allRequestData['remote_stock_quantity'];
                $updateData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string)$remoteStockValue;
            }
            
            // Обновляем товар
            $good->update($updateData);

            // Обновление категорий
            if ($request->has('category_ids')) {
                $good->categories()->sync($request->get('category_ids', []));
            }

            // Обновление брендов
            if ($request->has('brand_ids')) {
                $good->brands()->sync($request->get('brand_ids', []));
            }

            // Обновление тегов
            if ($request->has('tag_ids')) {
                $good->tags()->sync($request->get('tag_ids', []));
            }

            // Обновление свойств (поддержка двух схем: колонка value либо shop_property_value_id)
            $lastSyncData = [];
            if ($request->has('properties')) {
                $incoming = $request->get('properties', []);

                $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
                $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
                $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

                // Очистим существующие свойства товара (только базовые, если есть колонка variation_id)
                $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                if ($hasVariationIdCol) {
                    $deleteQuery->whereNull('variation_id');
                }
                $deleted = $deleteQuery->delete();

                foreach ($incoming as $property) {
                    if (empty($property['property_id'])) {
                        continue;
                    }

                    $propertyId = (int) $property['property_id'];

                    // Режим через справочник значений
                    if ($hasShopValueIdCol) {
                        $propertyValueId = null;
                        if (!empty($property['shop_property_value_id'])) {
                            // Если есть shop_property_value_id и новое значение, обновляем существующее значение
                            $existingValueId = (int) $property['shop_property_value_id'];
                            $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);
                            
                            if (!empty($property['value']) && $existingValue) {
                                $valueToSave = trim($property['value']);
                                // Убираем двоеточие в начале и конце значения перед сохранением
                                $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                                $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                                $valueToSave = trim($valueToSave);
                                
                                // Обновляем существующее значение
                                $existingValue->update(['value' => $valueToSave]);
                                $propertyValueId = $existingValueId;
                            } else {
                                // Если значения нет, используем существующий ID
                                $propertyValueId = $existingValueId;
                            }
                        } elseif (!empty($property['value'])) {
                            $valueToSave = trim($property['value']);
                            // Убираем двоеточие в начале и конце значения перед сохранением
                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                            $valueToSave = trim($valueToSave);
                            
                            $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                                'property_id' => $propertyId,
                                'value' => $valueToSave
                            ], [
                                'is_active' => true,
                                'sort_order' => 0
                            ]);
                            $propertyValueId = (int) $pv->id;
                        }

                        if ($propertyValueId) {
                            DB::table('shop_good_properties')->updateOrInsert(
                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                [
                                    'shop_property_value_id' => $propertyValueId,
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                            $lastSyncData[$propertyId] = ['shop_property_value_id' => $propertyValueId];
                        }
                    }
                    // Режим хранения прямого текста значения
                    elseif ($hasValueCol) {
                        $textValue = null;
                        if (!empty($property['value'])) {
                            $textValue = trim($property['value']);
                        } elseif (!empty($property['shop_property_value_id'])) {
                            $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                            $textValue = $found ? $found->value : null;
                        }

                        if ($textValue !== null && $textValue !== '') {
                            DB::table('shop_good_properties')->updateOrInsert(
                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                [
                                    'value' => $textValue,
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                            $lastSyncData[$propertyId] = ['value' => $textValue];
                        }
                    }
                }
            }

            // Аудит
            $this->logAudit($good, 'updated', $oldValues, $good->fresh()->toArray());

            DB::commit();

            // Обновляем модель из БД для получения актуальных данных
            $good->refresh();

            // Подтверждаем результат: возвращаем свойства с pivot
            $good->load(['properties' => function($q){
                $q->withPivot('shop_property_value_id');
            }]);

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно обновлен',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
                'debug' => [
                    'attached_count' => isset($lastSyncData) ? count($lastSyncData) : 0,
                    'attached' => $lastSyncData,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить характеристики товара отдельным эндпоинтом
     */
    public function updateProperties(Request $request, $id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);

        $properties = $request->get('properties', []);
        
        // Валидация: массив обязателен, но может быть пустым
        $rules = [
            'properties' => 'present|array'
        ];
        
        // Если массив не пустой, добавляем правила для элементов
        if (!empty($properties)) {
            $rules['properties.*.property_id'] = 'required|exists:shop_properties,id';
            $rules['properties.*.value'] = 'nullable|string|max:255';
            $rules['properties.*.shop_property_value_id'] = 'nullable|exists:shop_property_values,id';
        }
        
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $incoming = $request->get('properties', []);

            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
            $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

            // Очистим только базовые свойства
            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
            if ($hasVariationIdCol) {
                $deleteQuery->whereNull('variation_id');
            }
            $deleted = $deleteQuery->delete();

            $lastSyncData = [];
            foreach ($incoming as $property) {
                if (empty($property['property_id'])) {
                    continue;
                }
                $propertyId = (int) $property['property_id'];

                if ($hasShopValueIdCol) {
                    $propertyValueId = null;
                    if (!empty($property['shop_property_value_id'])) {
                        // Если есть shop_property_value_id и новое значение, обновляем существующее значение
                        $existingValueId = (int) $property['shop_property_value_id'];
                        $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);
                        
                        if (!empty($property['value']) && $existingValue) {
                            $valueToSave = trim($property['value']);
                            // Убираем двоеточие в начале и конце значения перед сохранением
                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                            $valueToSave = trim($valueToSave);
                            
                            // Обновляем существующее значение
                            $existingValue->update(['value' => $valueToSave]);
                            $propertyValueId = $existingValueId;
                        } else {
                            // Если значения нет, используем существующий ID
                            $propertyValueId = $existingValueId;
                        }
                    } elseif (!empty($property['value'])) {
                        $valueToSave = trim($property['value']);
                        // Убираем двоеточие в начале и конце значения перед сохранением
                        $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                        $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                        $valueToSave = trim($valueToSave);
                        
                        $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $propertyId,
                            'value' => $valueToSave
                        ], [
                            'is_active' => true,
                            'sort_order' => 0
                        ]);
                        $propertyValueId = (int) $pv->id;
                    }
                    if ($propertyValueId) {
                        DB::table('shop_good_properties')->updateOrInsert(
                            ['good_id' => $good->id, 'property_id' => $propertyId],
                            [
                                'shop_property_value_id' => $propertyValueId,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $lastSyncData[$propertyId] = ['shop_property_value_id' => $propertyValueId];
                    }
                } elseif ($hasValueCol) {
                    $textValue = null;
                    if (!empty($property['value'])) {
                        $textValue = trim($property['value']);
                        // Убираем двоеточие в начале и конце значения
                        $textValue = preg_replace('/^:\s*/', '', $textValue);
                        $textValue = preg_replace('/\s*:\s*$/', '', $textValue);
                        $textValue = trim($textValue);
                    } elseif (!empty($property['shop_property_value_id'])) {
                        $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                        $textValue = $found ? $found->value : null;
                        if ($textValue) {
                            // Убираем двоеточие в начале и конце значения
                            $textValue = preg_replace('/^:\s*/', '', $textValue);
                            $textValue = preg_replace('/\s*:\s*$/', '', $textValue);
                            $textValue = trim($textValue);
                        }
                    }
                    if ($textValue !== null && $textValue !== '') {
                        DB::table('shop_good_properties')->updateOrInsert(
                            ['good_id' => $good->id, 'property_id' => $propertyId],
                            [
                                'value' => $textValue,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $lastSyncData[$propertyId] = ['value' => $textValue];
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойства обновлены',
                'data' => [
                    'attached' => $lastSyncData,
                    'count' => count($lastSyncData)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления свойств: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить товар
     */
    public function destroy($id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);
        $oldValues = $good->toArray();

        try {
            DB::beginTransaction();

            // Аудит
            $this->logAudit($good, 'deleted', $oldValues, null);

            $good->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно удален'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Массовое обновление товаров
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:shop_goods,id',
            'action' => 'required|in:activate,deactivate,delete,update_categories,update_brands,update_tags,update_properties,update_stock,update_remote_stock,update_price,update_sale_price,update_demping_price,toggle_show_demping,update_label,remove_after_symbol,update_dimensions,enable_preorder,disable_preorder',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ids = $request->get('ids');
            $action = $request->get('action');
            $data = $request->get('data', []);

            $goods = ShopGood::whereIn('id', $ids)->get();

            foreach ($goods as $good) {
                $oldValues = $good->toArray();

                // Для удаления сначала создаем запись аудита, потом удаляем товар
                if ($action === 'delete') {
                    $this->logAudit($good, 'bulk_deleted', $oldValues, null);
                    $good->delete();
                    continue;
                }

                switch ($action) {
                    case 'activate':
                        $good->update(['is_active' => true]);
                        break;
                    case 'deactivate':
                        $good->update(['is_active' => false]);
                        break;
                    case 'enable_preorder':
                        $good->update(['is_preorder' => true]);
                        break;
                    case 'disable_preorder':
                        $good->update(['is_preorder' => false]);
                        break;
                    case 'update_categories':
                        $currentCategoryIds = $good->categories()->pluck('shop_categories.id')->toArray();
                        
                        // Если установлен флаг очистки всех категорий
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->categories()->sync([]);
                        } else {
                            // Удаляем категории из списка на удаление
                            if (isset($data['category_ids_to_remove']) && is_array($data['category_ids_to_remove'])) {
                                $currentCategoryIds = array_diff($currentCategoryIds, $data['category_ids_to_remove']);
                            }
                            
                            // Добавляем новые категории
                            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                                $newCategoryIds = $data['category_ids'];
                                // Объединяем и убираем дубликаты
                                $allCategoryIds = array_unique(array_merge($currentCategoryIds, $newCategoryIds));
                            } else {
                                $allCategoryIds = $currentCategoryIds;
                            }
                            
                            $good->categories()->sync($allCategoryIds);
                        }
                        break;
                    case 'update_brands':
                        $currentBrandIds = $good->brands()->pluck('shop_brands.id')->toArray();
                        
                        // Если установлен флаг очистки всех брендов
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->brands()->sync([]);
                        } else {
                            // Удаляем бренды из списка на удаление
                            if (isset($data['brand_ids_to_remove']) && is_array($data['brand_ids_to_remove'])) {
                                $currentBrandIds = array_diff($currentBrandIds, $data['brand_ids_to_remove']);
                            }
                            
                            // Добавляем новые бренды
                            if (isset($data['brand_ids']) && is_array($data['brand_ids'])) {
                                $newBrandIds = $data['brand_ids'];
                                // Объединяем и убираем дубликаты
                                $allBrandIds = array_unique(array_merge($currentBrandIds, $newBrandIds));
                            } else {
                                $allBrandIds = $currentBrandIds;
                            }
                            
                            $good->brands()->sync($allBrandIds);
                        }
                        break;
                    case 'update_tags':
                        $currentTagIds = $good->tags()->pluck('shop_tags.id')->toArray();
                        
                        // Если установлен флаг очистки всех тегов
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->tags()->sync([]);
                        } else {
                            // Удаляем теги из списка на удаление
                            if (isset($data['tag_ids_to_remove']) && is_array($data['tag_ids_to_remove'])) {
                                $currentTagIds = array_diff($currentTagIds, $data['tag_ids_to_remove']);
                            }
                            
                            // Добавляем новые теги
                            if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
                                $newTagIds = $data['tag_ids'];
                                // Объединяем и убираем дубликаты
                                $allTagIds = array_unique(array_merge($currentTagIds, $newTagIds));
                            } else {
                                $allTagIds = $currentTagIds;
                            }
                            
                            $good->tags()->sync($allTagIds);
                        }
                        break;
                    case 'update_properties':
                        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
                        $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
                        $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');
                        
                        // Получаем текущие свойства товара
                        $currentProperties = [];
                        $existingPropertiesQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                        if ($hasVariationIdCol) {
                            $existingPropertiesQuery->whereNull('variation_id');
                        }
                        $existingProperties = $existingPropertiesQuery->get();
                        
                        foreach ($existingProperties as $existingProp) {
                            $currentProperties[] = [
                                'property_id' => $existingProp->property_id,
                                'shop_property_value_id' => $existingProp->shop_property_value_id ?? null,
                                'value' => $existingProp->value ?? null
                            ];
                        }
                        
                        // Если установлен флаг очистки всех свойств
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                            if ($hasVariationIdCol) {
                                $deleteQuery->whereNull('variation_id');
                            }
                            $deleteQuery->delete();
                            $currentProperties = [];
                        } else {
                            // Удаляем свойства из списка на удаление
                            if (isset($data['properties_to_remove']) && is_array($data['properties_to_remove'])) {
                                foreach ($data['properties_to_remove'] as $propertyToRemove) {
                                    $removePropertyId = (int) $propertyToRemove['property_id'];
                                    $removeShopPropertyValueId = isset($propertyToRemove['shop_property_value_id']) ? (int) $propertyToRemove['shop_property_value_id'] : null;
                                    $removeValue = isset($propertyToRemove['value']) ? trim($propertyToRemove['value']) : null;
                                    
                                    $currentProperties = array_filter($currentProperties, function($prop) use ($removePropertyId, $removeShopPropertyValueId, $removeValue, $hasShopValueIdCol, $hasValueCol) {
                                        if ($prop['property_id'] != $removePropertyId) {
                                            return true;
                                        }
                                        
                                        if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                            $propShopPropertyValueId = isset($prop['shop_property_value_id']) ? (int) $prop['shop_property_value_id'] : null;
                                            return $propShopPropertyValueId != $removeShopPropertyValueId;
                                        }
                                        
                                        if ($hasValueCol && $removeValue !== null) {
                                            $propValue = trim($prop['value'] ?? '');
                                            return $propValue !== $removeValue;
                                        }
                                        
                                        return true;
                                    });
                                }
                            }
                        }
                        
                        // Добавляем новые свойства (если не установлен флаг очистки всех)
                        if (!isset($data['clear_all']) || !$data['clear_all']) {
                            if (isset($data['properties']) && is_array($data['properties'])) {
                                $incoming = $data['properties'];
                                
                                // Объединяем текущие свойства с новыми (убираем дубликаты)
                                // Проверяем каждое новое свойство - если у товара уже есть такая характеристика с таким значением, пропускаем
                                $allProperties = array_values($currentProperties);
                                foreach ($incoming as $property) {
                                    if (empty($property['property_id'])) {
                                        continue;
                                    }
                                    
                                    $propertyId = (int) $property['property_id'];
                                    $newShopPropertyValueId = isset($property['shop_property_value_id']) ? (int) $property['shop_property_value_id'] : null;
                                    $newValue = $property['value'] ?? null;
                                    
                                    // Проверяем, нет ли уже такого свойства с таким значением
                                    $exists = false;
                                    foreach ($allProperties as $existing) {
                                        if ($existing['property_id'] == $propertyId) {
                                            // Если используется shop_property_value_id
                                            if ($hasShopValueIdCol) {
                                                $existingShopPropertyValueId = isset($existing['shop_property_value_id']) ? (int) $existing['shop_property_value_id'] : null;
                                                if ($newShopPropertyValueId !== null && $existingShopPropertyValueId == $newShopPropertyValueId) {
                                                    $exists = true;
                                                    break;
                                                }
                                            }
                                            // Если используется value
                                            if ($hasValueCol) {
                                                $existingValue = trim($existing['value'] ?? '');
                                                if ($newValue !== null && $existingValue !== '' && $existingValue === trim($newValue)) {
                                                    $exists = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    
                                    // Если свойство с таким значением уже есть, пропускаем добавление
                                    if (!$exists) {
                                        $allProperties[] = $property;
                                    }
                                }
                                
                                // Очистим существующие свойства товара (только базовые, если есть колонка variation_id)
                                $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                                if ($hasVariationIdCol) {
                                    $deleteQuery->whereNull('variation_id');
                                }
                                $deleteQuery->delete();
                                
                                // Добавляем все свойства (текущие после удаления + новые)
                                foreach ($allProperties as $property) {
                                    if (empty($property['property_id'])) {
                                        continue;
                                    }
                                    
                                    $propertyId = (int) $property['property_id'];
                                    
                                    // Режим через справочник значений
                                    if ($hasShopValueIdCol) {
                                        $propertyValueId = null;
                                        if (!empty($property['shop_property_value_id'])) {
                                            $propertyValueId = (int) $property['shop_property_value_id'];
                                        } elseif (!empty($property['value'])) {
                                            $valueToSave = trim($property['value']);
                                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                                            $valueToSave = trim($valueToSave);
                                            
                                            $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                                                'property_id' => $propertyId,
                                                'value' => $valueToSave
                                            ], [
                                                'is_active' => true,
                                                'sort_order' => 0
                                            ]);
                                            $propertyValueId = (int) $pv->id;
                                        }
                                        
                                        if ($propertyValueId) {
                                            DB::table('shop_good_properties')->updateOrInsert(
                                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                                [
                                                    'shop_property_value_id' => $propertyValueId,
                                                    'updated_at' => now(),
                                                    'created_at' => now(),
                                                ]
                                            );
                                        }
                                    }
                                    // Режим хранения прямого текста значения
                                    elseif ($hasValueCol) {
                                        $textValue = null;
                                        if (!empty($property['value'])) {
                                            $textValue = trim($property['value']);
                                        } elseif (!empty($property['shop_property_value_id'])) {
                                            $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                                            $textValue = $found ? $found->value : null;
                                        }
                                        
                                        if ($textValue !== null && $textValue !== '') {
                                            DB::table('shop_good_properties')->updateOrInsert(
                                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                                [
                                                    'value' => $textValue,
                                                    'updated_at' => now(),
                                                    'created_at' => now(),
                                                ]
                                            );
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case 'update_stock':
                        if (isset($data['stock_action']) && isset($data['stock_value'])) {
                            $stockAction = $data['stock_action'];
                            $stockValue = (int) $data['stock_value'];
                            $currentStock = (int) $good->stock_quantity;
                            
                            if ($stockAction === 'set') {
                                $good->update(['stock_quantity' => $stockValue]);
                            } elseif ($stockAction === 'add') {
                                $good->update(['stock_quantity' => max(0, $currentStock + $stockValue)]);
                            } elseif ($stockAction === 'subtract') {
                                $good->update(['stock_quantity' => max(0, $currentStock - $stockValue)]);
                            }
                        }
                        break;
                    case 'update_remote_stock':
                        if (isset($data['remote_stock_quantity'])) {
                            $remoteStockValue = $data['remote_stock_quantity'];
                            $good->update([
                                'remote_stock_quantity' => ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string)$remoteStockValue
                            ]);
                        }
                        break;
                    case 'update_price':
                        if (isset($data['price_action']) && isset($data['price_value'])) {
                            $priceAction = $data['price_action'];
                            $priceValue = (float) $data['price_value'];
                            $isPercent = isset($data['price_is_percent']) && $data['price_is_percent'];
                            $currentPrice = (float) $good->price;
                            
                            if ($priceAction === 'set') {
                                $good->update(['price' => max(0, $priceValue)]);
                            } elseif ($priceAction === 'add') {
                                if ($isPercent) {
                                    $good->update(['price' => max(0, $currentPrice * (1 + $priceValue / 100))]);
                                } else {
                                    $good->update(['price' => max(0, $currentPrice + $priceValue)]);
                                }
                            } elseif ($priceAction === 'subtract') {
                                if ($isPercent) {
                                    $good->update(['price' => max(0, $currentPrice * (1 - $priceValue / 100))]);
                                } else {
                                    $good->update(['price' => max(0, $currentPrice - $priceValue)]);
                                }
                            }
                        }
                        break;
                    case 'update_sale_price':
                        if (isset($data['sale_price_action'])) {
                            $salePriceAction = $data['sale_price_action'];
                            
                            // Очистка акционной цены
                            if ($salePriceAction === 'clear') {
                                $good->update(['sale_price' => null]);
                            } elseif (isset($data['sale_price_value'])) {
                                $salePriceValue = (float) $data['sale_price_value'];
                                $isPercent = isset($data['sale_price_is_percent']) && $data['sale_price_is_percent'];
                                $currentPrice = (float) $good->price;
                                $currentSalePrice = $good->sale_price ? (float) $good->sale_price : 0;
                                
                                if ($salePriceAction === 'set') {
                                    $newSalePrice = max(0, $salePriceValue);
                                    // Проверяем, чтобы акционная цена была меньше базовой
                                    if ($newSalePrice >= $currentPrice) {
                                        $newSalePrice = null;
                                    }
                                    $good->update(['sale_price' => $newSalePrice]);
                                } elseif ($salePriceAction === 'subtract') {
                                    if ($isPercent) {
                                        $newSalePrice = $currentPrice - ($currentPrice * $salePriceValue / 100);
                                    } else {
                                        $newSalePrice = $currentPrice - $salePriceValue;
                                    }
                                    $newSalePrice = max(0, $newSalePrice);
                                    // Проверяем, чтобы акционная цена была меньше базовой
                                    if ($newSalePrice >= $currentPrice) {
                                        $newSalePrice = null;
                                    }
                                    $good->update(['sale_price' => $newSalePrice]);
                                } elseif ($salePriceAction === 'subtract_from_sale') {
                                    if ($currentSalePrice > 0) {
                                        if ($isPercent) {
                                            $newSalePrice = $currentSalePrice - ($currentSalePrice * $salePriceValue / 100);
                                        } else {
                                            $newSalePrice = $currentSalePrice - $salePriceValue;
                                        }
                                        $newSalePrice = max(0, $newSalePrice);
                                        // Проверяем, чтобы акционная цена была меньше базовой
                                        if ($newSalePrice >= $currentPrice) {
                                            $newSalePrice = null;
                                        }
                                        $good->update(['sale_price' => $newSalePrice]);
                                    }
                                } elseif ($salePriceAction === 'add_to_sale') {
                                    if ($currentSalePrice > 0) {
                                        if ($isPercent) {
                                            $newSalePrice = $currentSalePrice + ($currentSalePrice * $salePriceValue / 100);
                                        } else {
                                            $newSalePrice = $currentSalePrice + $salePriceValue;
                                        }
                                        $newSalePrice = max(0, $newSalePrice);
                                        // Проверяем, чтобы акционная цена была меньше базовой
                                        if ($newSalePrice >= $currentPrice) {
                                            $newSalePrice = null;
                                        }
                                        $good->update(['sale_price' => $newSalePrice]);
                                    }
                                }
                            }
                        }
                        break;
                    case 'update_demping_price':
                        if (isset($data['demping_price_action'])) {
                            $dempingPriceAction = $data['demping_price_action'];
                            
                            // Очистка демпинговой цены
                            if ($dempingPriceAction === 'clear') {
                                $good->update(['demping_price' => null]);
                            } elseif (isset($data['demping_price_value'])) {
                                $dempingPriceValue = (float) $data['demping_price_value'];
                                $isPercent = isset($data['demping_price_is_percent']) && $data['demping_price_is_percent'];
                                $currentPrice = (float) $good->price;
                                $currentDempingPrice = $good->demping_price ? (float) $good->demping_price : 0;
                                
                                if ($dempingPriceAction === 'set') {
                                    $newDempingPrice = max(0, $dempingPriceValue);
                                    $good->update(['demping_price' => $newDempingPrice]);
                                } elseif ($dempingPriceAction === 'subtract') {
                                    if ($isPercent) {
                                        $newDempingPrice = $currentPrice - ($currentPrice * $dempingPriceValue / 100);
                                    } else {
                                        $newDempingPrice = $currentPrice - $dempingPriceValue;
                                    }
                                    $newDempingPrice = max(0, $newDempingPrice);
                                    $good->update(['demping_price' => $newDempingPrice]);
                                } elseif ($dempingPriceAction === 'subtract_from_sale') {
                                    $currentSalePrice = $good->sale_price ? (float) $good->sale_price : null;
                                    if ($currentSalePrice !== null) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentSalePrice - ($currentSalePrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentSalePrice - $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                } elseif ($dempingPriceAction === 'add_to_sale') {
                                    $currentSalePrice = $good->sale_price ? (float) $good->sale_price : null;
                                    if ($currentSalePrice !== null) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentSalePrice + ($currentSalePrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentSalePrice + $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                } elseif ($dempingPriceAction === 'subtract_from_demping') {
                                    if ($currentDempingPrice > 0) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentDempingPrice - ($currentDempingPrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentDempingPrice - $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                }
                            }
                        }
                        break;
                    case 'toggle_show_demping':
                        if (isset($data['show_demping'])) {
                            $good->update(['show_demping' => (bool) $data['show_demping']]);
                        }
                        break;
                    case 'update_label':
                        if (isset($data['label_id'])) {
                            $good->update(['label_id' => $data['label_id'] ?: null]);
                        }
                        break;
                    case 'remove_after_symbol':
                        if (isset($data['symbol']) && !empty($data['symbol'])) {
                            $symbol = $data['symbol'];
                            $name = $good->name;
                            
                            // Находим первое вхождение символа/сочетания
                            $position = mb_strpos($name, $symbol);
                            
                            if ($position !== false) {
                                // Удаляем символ и всё после него
                                $newName = mb_substr($name, 0, $position);
                                $good->update(['name' => $newName]);
                            }
                        }
                        break;
                    case 'update_dimensions':
                        $updateData = [];
                        // Обновляем только заполненные поля (размеры округляем до целых)
                        if (isset($data['width']) && $data['width'] !== null && $data['width'] !== '') {
                            $updateData['width'] = (int) round((float) $data['width']);
                        }
                        if (isset($data['height']) && $data['height'] !== null && $data['height'] !== '') {
                            $updateData['height'] = (int) round((float) $data['height']);
                        }
                        if (isset($data['depth']) && $data['depth'] !== null && $data['depth'] !== '') {
                            $updateData['depth'] = (int) round((float) $data['depth']);
                        }
                        if (isset($data['weight']) && $data['weight'] !== null && $data['weight'] !== '') {
                            $updateData['weight'] = (float) $data['weight']; // Вес может быть дробным
                        }
                        if (!empty($updateData)) {
                            $good->update($updateData);
                        }
                        break;
                }

                // Аудит для всех действий кроме delete (для delete уже создан выше)
                $this->logAudit($good, 'bulk_' . $action, $oldValues, $good->fresh()->toArray());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Массовое обновление выполнено успешно'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового обновления: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить данные для фильтров
     */
    public function filters(): JsonResponse
    {
        $categories = ShopCategory::active()->ordered()->with(['children' => function($query) {
            $query->where('is_active', true)
                  ->orderBy('sort_order', 'asc')
                  ->orderBy('name', 'asc')
                  ->select('id', 'name', 'parent_id');
        }])->get(['id', 'name', 'parent_id']);
        $brands = ShopBrand::active()->ordered()->get(['id', 'name']);
        $tags = ShopTag::active()->ordered()->get(['id', 'name', 'color']);
        $labels = ShopLabel::ordered()->get(['id', 'name', 'color']);
        $properties = ShopProperty::active()->ordered()->get(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'tags' => $tags,
                'labels' => $labels,
                'properties' => $properties
            ]
        ]);
    }

    /**
     * Создать новую категорию
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $category = ShopCategory::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopCategory::max('sort_order') + 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $category
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый бренд
     */
    public function createBrand(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $brand = ShopBrand::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopBrand::max('sort_order') + 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Бренд успешно создан',
                'data' => $brand
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания бренда: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый лейбл
     */
    public function createLabel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $label = ShopLabel::create([
                'name' => $request->get('name'),
                'color' => $request->get('color', '#3B82F6'),
                'sort_order' => ShopLabel::max('sort_order') + 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Лейбл успешно создан',
                'data' => $label
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания лейбла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать и сохранить изображение по URL
     */
    public function downloadImage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imageUrl' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageUrl = $request->input('imageUrl');
            $storagePath = $request->input('storagePath', '/images/shop/goods'); // Исправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');


            // Валидация URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный формат URL'
                ], 400);
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            
            if (!in_array($extension, $imageExtensions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неподдерживаемый формат изображения'
                ], 400);
            }

            // Генерация имени файла
            if ($naming === 'original') {
                // Используем оригинальное имя файла
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName . '.' . $extension;
                
                // Очищаем имя файла от недопустимых символов
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                // Используем хеш
                $hash = hash('sha256', $imageUrl);
                $fileName = $hash . '.' . $extension;
            }
            
            // Полный путь для сохранения
            $fullPath = $storagePath . '/' . $fileName;
            // Получаем путь к фронтенду из переменной окружения FRONTEND_PATH
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $storageFullPath = $frontendPublicPath . $fullPath;
            
            // Проверяем, существует ли файл уже
            if (file_exists($storageFullPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'path' => $fullPath,
                        'originalUrl' => $imageUrl,
                        'skipped' => true
                    ]
                ]);
            }
            
            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!\App\Helpers\StorageHelper::createDirectory($directory)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать директорию для изображения'
                ], 500);
            }

            // Скачиваем изображение с помощью cURL для обхода SSL проблем
            
            $downloadResult = $this->downloadImageWithCurl($imageUrl);
            
            if (!$downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось скачать изображение: ' . ($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}")
                ], 400);
            }
            
            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл слишком большой (максимум 30MB)'
                ], 400);
            }

            // Сохраняем файл
            $saveResult = file_put_contents($storageFullPath, $imageData);
            if ($saveResult === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить файл'
                ], 500);
            }

            // Обработка изображения
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($storageFullPath, $resize, $width, $height);
            }


            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $fullPath,
                    'originalUrl' => $imageUrl,
                    'size' => strlen($imageData),
                    'optimized' => $optimize
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания изображения: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Пакетная загрузка изображений
     */
    public function downloadImagesBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imageUrls' => 'required|array|min:1|max:500', // Максимум 500 изображений за раз
            'imageUrls.*' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageUrls = $request->input('imageUrls');
            $storagePath = $request->input('storagePath', '/images/shop/goods'); // Исправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');


            $results = [];
            $errors = [];
            $skipped = [];

            // Обрабатываем изображения последовательно (для стабильности)
            foreach ($imageUrls as $index => $imageUrl) {
                $response = $this->downloadSingleImage(
                    $imageUrl,
                    $storagePath,
                    $optimize,
                    $naming,
                    $resize,
                    $width,
                    $height,
                    $index
                );

                if ($response['success']) {
                    $results[$response['originalUrl']] = $response['path'];
                    
                    if (isset($response['skipped']) && $response['skipped']) {
                        $skipped[] = $response['originalUrl'];
                    } else {
                    }
                } else {
                    $errors[] = [
                        'url' => $response['originalUrl'],
                        'error' => $response['error']
                    ];
                }
            }


            return response()->json([
                'success' => true,
                'data' => [
                    'paths' => $results,
                    'errors' => $errors,
                    'skipped' => $skipped,
                    'total' => count($imageUrls),
                    'successful' => count($results),
                    'skipped_count' => count($skipped),
                    'failed' => count($errors)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка пакетной загрузки изображений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Сохранение изображения на фронтенд
     */
    public function saveImageToFrontend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|image|max:30720', // Максимум 30MB
            'path' => 'required|string',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $image = $request->file('image');
            $path = $request->input('path');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            // Путь для сохранения на фронтенд
            // Получаем путь к фронтенду из переменной окружения FRONTEND_PATH
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . $path;
            $dir = dirname($fullPath);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Сохраняем файл
            $image->move($dir, basename($path));

            // Обработка изображения если нужно
            if ($resize !== 'no_change' && $width && $height) {
                $this->resizeImageFile($fullPath, $width, $height, $resize);
            }


            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $path,
                    'size' => filesize($fullPath)
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения изображения: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Загрузка одного изображения (вспомогательный метод для пакетной загрузки)
     */
    private function downloadSingleImage($imageUrl, $storagePath, $optimize, $naming, $resize, $width, $height, $index)
    {
        try {

            // Валидация URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Неверный формат URL'
                ];
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            
            if (!in_array($extension, $imageExtensions)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Неподдерживаемый формат изображения'
                ];
            }

            // Генерация имени файла
            if ($naming === 'original') {
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName . '.' . $extension;
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                $hash = hash('sha256', $imageUrl . $index); // Добавляем индекс для уникальности
                $fileName = $hash . '.' . $extension;
            }
            
            // Полный путь для сохранения на фронтенд
            $fullPath = $storagePath . '/' . $fileName;
            // Получаем путь к фронтенду из переменной окружения FRONTEND_PATH
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $storageFullPath = $frontendPublicPath . $fullPath;
            
            // Проверяем, существует ли файл уже
            if (file_exists($storageFullPath)) {
                
                return [
                    'success' => true,
                    'originalUrl' => $imageUrl,
                    'path' => $fullPath,
                    'skipped' => true // Флаг, что файл был пропущен
                ];
            }
            
            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!\App\Helpers\StorageHelper::createDirectory($directory)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Не удалось создать директорию для изображения'
                ];
            }

            // Скачиваем изображение с помощью cURL для обхода SSL проблем
            $downloadResult = $this->downloadImageWithCurl($imageUrl);
            
            if (!$downloadResult['success']) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Не удалось скачать изображение: ' . ($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}")
                ];
            }
            
            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Файл слишком большой (максимум 30MB)'
                ];
            }

            // Сохраняем файл
            file_put_contents($storageFullPath, $imageData);

            // Обработка изображения
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($storageFullPath, $resize, $width, $height);
            }


            return [
                'success' => true,
                'originalUrl' => $imageUrl,
                'path' => $fullPath
            ];

        } catch (\Exception $e) {
            
            return [
                'success' => false,
                'originalUrl' => $imageUrl,
                'error' => 'Ошибка: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Оптимизация изображения
     */
    private function optimizeImage($filePath)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (!$imageInfo) {
                return;
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            // Если изображение слишком большое, уменьшаем его
            if ($width > 2000 || $height > 2000) {
                $newWidth = $width > $height ? 2000 : intval(2000 * $width / $height);
                $newHeight = $height > $width ? 2000 : intval(2000 * $height / $width);
                
                // Создаем новое изображение
                $sourceImage = null;
                switch ($mimeType) {
                    case 'image/jpeg':
                        $sourceImage = imagecreatefromjpeg($filePath);
                        break;
                    case 'image/png':
                        $sourceImage = imagecreatefrompng($filePath);
                        break;
                    case 'image/gif':
                        $sourceImage = imagecreatefromgif($filePath);
                        break;
                    case 'image/webp':
                        $sourceImage = imagecreatefromwebp($filePath);
                        break;
                }
                
                if ($sourceImage) {
                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                    
                    // Сохраняем прозрачность для PNG
                    if ($mimeType === 'image/png') {
                        imagealphablending($resizedImage, false);
                        imagesavealpha($resizedImage, true);
                        $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
                    }
                    
                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    
                    // Сохраняем оптимизированное изображение
                    switch ($mimeType) {
                        case 'image/jpeg':
                            imagejpeg($resizedImage, $filePath, 85); // 85% качество
                            break;
                        case 'image/png':
                            imagepng($resizedImage, $filePath, 8); // 8 уровень сжатия
                            break;
                        case 'image/gif':
                            imagegif($resizedImage, $filePath);
                            break;
                        case 'image/webp':
                            imagewebp($resizedImage, $filePath, 85); // 85% качество
                            break;
                    }
                    
                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);
                }
            }
        } catch (\Exception $e) {
            // Ошибка оптимизации не критична, продолжаем выполнение
        }
    }

    /**
     * Скачивание изображения с помощью cURL (обход SSL проблем)
     */
    private function downloadImageWithCurl($imageUrl)
    {
        // Сначала пробуем cURL с агрессивными настройками SSL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        // Агрессивные настройки SSL для обхода проблем с DH ключом
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);
        
        // Дополнительные настройки для обхода проблем с SSL
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
        ]);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Если cURL не сработал из-за SSL проблем, пробуем wget
        if ($imageData === false || $httpCode !== 200) {
            
            return $this->downloadImageWithWget($imageUrl);
        }
        
        return [
            'data' => $imageData,
            'http_code' => $httpCode,
            'error' => $error,
            'success' => $imageData !== false && $httpCode === 200
        ];
    }

    /**
     * Fallback метод для скачивания изображений через wget
     */
    private function downloadImageWithWget($imageUrl)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'image_download_');
        
        try {
            // Используем wget с отключенной проверкой SSL
            $wgetCommand = "wget --no-check-certificate --timeout=30 --tries=1 -O '{$tempFile}' " . escapeshellarg($imageUrl) . " 2>&1";
            $output = shell_exec($wgetCommand);
            
            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $imageData = file_get_contents($tempFile);
                unlink($tempFile);
                
                
                return [
                    'data' => $imageData,
                    'http_code' => 200,
                    'error' => null,
                    'success' => true
                ];
            } else {
                unlink($tempFile);
                
                return [
                    'data' => false,
                    'http_code' => 0,
                    'error' => 'wget failed: ' . $output,
                    'success' => false
                ];
            }
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            
            return [
                'data' => false,
                'http_code' => 0,
                'error' => 'wget exception: ' . $e->getMessage(),
                'success' => false
            ];
        }
    }

    /**
     * Обработка изображения с различными типами изменения размера
     */
    private function processImage($filePath, $resize, $width, $height)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (!$imageInfo) {
                return;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            // Если размеры не заданы, используем оригинальные
            if (!$width || !$height) {
                $width = $originalWidth;
                $height = $originalHeight;
            }
            
            // Если не нужно изменять размер
            if ($resize === 'no_change') {
                $this->optimizeImage($filePath);
                return;
            }
            
            // Создаем исходное изображение
            $sourceImage = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($filePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($filePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($filePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($filePath);
                    break;
            }
            
            if (!$sourceImage) {
                return;
            }
            
            $newImage = null;
            
            if ($resize === 'crop_proportional') {
                // Обрезка с сохранением пропорций (использует системные размеры)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'fit_with_white') {
                // Подгонка под размеры с белым фоном (использует системные размеры)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'fit_system') {
                // Подгонка под размеры системы (уменьшение если превышает лимиты)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->fitSystemSize($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'custom') {
                // Пользовательские размеры (использует переданные размеры или системные)
                $customWidth = $width ?: $this->getSystemImageWidth();
                $customHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $customWidth, $customHeight);
            }
            
            if ($newImage) {
                // Сохраняем обработанное изображение
                switch ($mimeType) {
                    case 'image/jpeg':
                        imagejpeg($newImage, $filePath, 85);
                        break;
                    case 'image/png':
                        imagepng($newImage, $filePath, 8);
                        break;
                    case 'image/gif':
                        imagegif($newImage, $filePath);
                        break;
                    case 'image/webp':
                        imagewebp($newImage, $filePath, 85);
                        break;
                }
                
                imagedestroy($newImage);
            }
            
            imagedestroy($sourceImage);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Ошибка обработки изображения: ' . $e->getMessage());
        }
    }
    
    /**
     * Обрезка с сохранением пропорций
     */
    private function cropProportional($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight)
    {
        // Вычисляем коэффициенты масштабирования
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = max($scaleX, $scaleY); // Берем больший коэффициент
        
        // Вычисляем новые размеры
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        
        // Создаем новое изображение
        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Сохраняем прозрачность для PNG
        if (imageistruecolor($sourceImage)) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        
        // Вычисляем координаты для обрезки (центрируем)
        $cropX = intval(($newWidth - $targetWidth) / 2);
        $cropY = intval(($newHeight - $targetHeight) / 2);
        
        // Сначала масштабируем
        $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
        if (imageistruecolor($sourceImage)) {
            imagealphablending($scaledImage, false);
            imagesavealpha($scaledImage, true);
        }
        imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        // Затем обрезаем
        imagecopy($newImage, $scaledImage, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);
        
        imagedestroy($scaledImage);
        
        return $newImage;
    }
    
    /**
     * Подгонка под размеры с белым фоном
     */
    private function fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight)
    {
        // Вычисляем коэффициенты масштабирования
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = min($scaleX, $scaleY); // Берем меньший коэффициент для вписывания
        
        // Вычисляем новые размеры
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        
        // Создаем новое изображение с белым фоном
        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($newImage, 255, 255, 255);
        imagefill($newImage, 0, 0, $white);
        
        // Вычисляем координаты для центрирования
        $x = intval(($targetWidth - $newWidth) / 2);
        $y = intval(($targetHeight - $newHeight) / 2);
        
        // Сначала масштабируем
        $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
        if (imageistruecolor($sourceImage)) {
            imagealphablending($scaledImage, false);
            imagesavealpha($scaledImage, true);
        }
        imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        // Затем вставляем в центр
        imagecopy($newImage, $scaledImage, $x, $y, 0, 0, $newWidth, $newHeight);
        
        imagedestroy($scaledImage);
        
        return $newImage;
    }
    
    /**
     * Подгонка под размеры системы (уменьшение если превышает лимиты)
     */
    private function fitSystemSize($sourceImage, $originalWidth, $originalHeight, $maxWidth, $maxHeight)
    {
        // Если изображение уже меньше или равно максимальным размерам, возвращаем как есть
        if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
            return $sourceImage;
        }
        
        // Вычисляем коэффициенты масштабирования
        $scaleX = $maxWidth / $originalWidth;
        $scaleY = $maxHeight / $originalHeight;
        $scale = min($scaleX, $scaleY); // Берем меньший коэффициент для вписывания
        
        // Вычисляем новые размеры
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        
        // Создаем новое изображение
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Сохраняем прозрачность для PNG
        if (imageistruecolor($sourceImage)) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        // Масштабируем изображение
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        return $newImage;
    }
    
    /**
     * Получить системную ширину изображений товаров
     */
    private function getSystemImageWidth()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_width')->first();
        return $setting ? (int)$setting->value : 500;
    }
    
    /**
     * Получить системную высоту изображений товаров
     */
    private function getSystemImageHeight()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_height')->first();
        return $setting ? (int)$setting->value : 500;
    }

    /**
     * Check for duplicates by specified fields
     */
    public function checkDuplicates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fields' => 'required|array',
            'fields.*' => 'required|string',
            'data' => 'required|array',
            'data.*' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $fields = $request->input('fields');
        $data = $request->input('data');
        $results = [];

        foreach ($data as $index => $item) {
            $query = ShopGood::query();
            
            // Build query for each field
            foreach ($fields as $field) {
                if (isset($item[$field]) && $item[$field] !== '') {
                    $query->where($field, $item[$field]);
                }
            }
            
            $existing = $query->first();
            
            $results[] = [
                'index' => $index,
                'exists' => $existing !== null,
                'id' => $existing ? $existing->id : null,
                'item' => $item
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * Логирование аудита
     */
    private function logAudit($good, $action, $oldValues, $newValues)
    {
        $good->audit()->create([
            'user_id' => request()->user()->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    /**
     * Изменение размера изображения
     */
    private function resizeImageFile($imagePath, $width, $height, $resizeType)
    {
        try {
            if (!file_exists($imagePath)) {
                return false;
            }

            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                return false;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Создаем ресурс изображения в зависимости от типа
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($imagePath);
                    break;
                default:
                    return false;
            }

            if (!$sourceImage) {
                return false;
            }

            $newImage = null;

            // Применяем нужный тип изменения размера
            switch ($resizeType) {
                case 'crop_proportional':
                    $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                case 'fit_with_white':
                    $newImage = $this->fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                case 'fit_system':
                    $newImage = $this->fitSystemSize($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                default:
                    $newImage = $sourceImage;
            }

            if (!$newImage) {
                imagedestroy($sourceImage);
                return false;
            }

            // Сохраняем изображение
            $result = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $result = imagejpeg($newImage, $imagePath, 90);
                    break;
                case 'image/png':
                    $result = imagepng($newImage, $imagePath, 9);
                    break;
                case 'image/gif':
                    $result = imagegif($newImage, $imagePath);
                    break;
                case 'image/webp':
                    $result = imagewebp($newImage, $imagePath, 90);
                    break;
            }

            // Освобождаем память
            imagedestroy($sourceImage);
            if ($newImage !== $sourceImage) {
                imagedestroy($newImage);
            }

            return $result;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Массовый парсинг характеристик из описаний товаров
     */
    public function massParseProperties(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_size' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $batchSize = $request->get('batch_size', 1000);
            $offset = $request->get('offset', 0);

            // Получаем товары батчами
            $goods = ShopGood::select('id', 'description')
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->skip($offset)
                ->take($batchSize)
                ->get();

            $stats = [
                'processed' => 0,
                'success' => 0,
                'error' => 0,
                'skipped' => 0,
                'errors' => [] // Детальная информация об ошибках
            ];

            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
            $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

            // Кэш свойств для избежания повторных запросов
            $propertiesCache = [];
            $propertyValuesCache = [];

            foreach ($goods as $good) {
                try {
                    if (empty($good->description)) {
                        $stats['skipped']++;
                        $stats['processed']++;
                        continue;
                    }

                    // Парсим описание
                    $parsedProperties = $this->parseDescription($good->description);

                    if (empty($parsedProperties)) {
                        $stats['skipped']++;
                        $stats['processed']++;
                        continue;
                    }

                    // Подготавливаем свойства для сохранения
                    $propertiesToSave = [];

                    foreach ($parsedProperties as $parsed) {
                        $propertyName = trim($parsed['name']);
                        $propertyValue = trim($parsed['value']);

                        if (empty($propertyName) || empty($propertyValue)) {
                            continue;
                        }

                        // Проверяем длину названия свойства (обычно VARCHAR(255))
                        $maxNameLength = 255;
                        if (mb_strlen($propertyName) > $maxNameLength) {
                            Log::warning('Пропущена характеристика с слишком длинным названием', [
                                'good_id' => $good->id,
                                'property_name_length' => mb_strlen($propertyName),
                                'property_name_preview' => mb_substr($propertyName, 0, 100) . '...'
                            ]);
                            
                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'name_too_long',
                                'message' => "Название характеристики слишком длинное ({$maxNameLength} символов максимум)",
                                'details' => "Название длиной " . mb_strlen($propertyName) . " символов (показано первые 100: " . mb_substr($propertyName, 0, 100) . "...)"
                            ];
                            continue;
                        }

                        // Проверяем длину значения (VARCHAR(255) = максимум 255 символов)
                        $maxValueLength = 255;
                        if (mb_strlen($propertyValue) > $maxValueLength) {
                            Log::warning('Пропущена характеристика с слишком длинным значением', [
                                'good_id' => $good->id,
                                'property_name' => $propertyName,
                                'value_length' => mb_strlen($propertyValue),
                                'value_preview' => mb_substr($propertyValue, 0, 100) . '...'
                            ]);
                            
                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'value_too_long',
                                'message' => "Значение характеристики слишком длинное ({$maxValueLength} символов максимум)",
                                'details' => "Характеристика '{$propertyName}': значение длиной " . mb_strlen($propertyValue) . " символов (показано первые 100: " . mb_substr($propertyValue, 0, 100) . "...)"
                            ];
                            continue;
                        }

                        // Ищем или создаем свойство
                        $property = null;
                        $cacheKey = strtolower($propertyName);

                        if (isset($propertiesCache[$cacheKey])) {
                            $property = $propertiesCache[$cacheKey];
                        } else {
                            try {
                                // Нормализуем название: только первое слово с большой буквы
                                $normalizedName = mb_strtolower($propertyName);
                                $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)) . mb_substr($normalizedName, 1);
                                
                                $property = ShopProperty::whereRaw('LOWER(name) = ?', [strtolower($propertyName)])->first();

                                if (!$property) {
                                    $property = ShopProperty::create([
                                        'name' => $normalizedName,
                                        'property_type' => 'string',
                                        'slug' => \Illuminate\Support\Str::slug($normalizedName)
                                    ]);
                                } else {
                                    // Если свойство существует, обновляем его название на нормализованное (если оно отличается)
                                    if ($property->name !== $normalizedName) {
                                        $property->update([
                                            'name' => $normalizedName
                                        ]);
                                    }
                                }

                                $propertiesCache[$cacheKey] = $property;
                            } catch (\Exception $e) {
                                Log::error('Ошибка создания/поиска свойства', [
                                    'good_id' => $good->id,
                                    'property_name' => $propertyName,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage()
                                ]);
                                continue;
                            }
                        }

                        if (!$property) {
                            continue;
                        }

                        // Ищем или создаем значение свойства
                        $propertyValueModel = null;
                        $valueCacheKey = $property->id . '_' . strtolower($propertyValue);

                        if (isset($propertyValuesCache[$valueCacheKey])) {
                            $propertyValueModel = $propertyValuesCache[$valueCacheKey];
                        } else {
                            try {
                                $propertyValueModel = ShopPropertyValue::where('property_id', $property->id)
                                    ->whereRaw('LOWER(value) = ?', [strtolower($propertyValue)])
                                    ->first();

                                if (!$propertyValueModel) {
                                    $propertyValueModel = ShopPropertyValue::create([
                                        'property_id' => $property->id,
                                        'value' => $propertyValue,
                                        'is_active' => true
                                    ]);
                                }

                                $propertyValuesCache[$valueCacheKey] = $propertyValueModel;
                            } catch (\Exception $e) {
                                Log::error('Ошибка создания/поиска значения свойства', [
                                    'good_id' => $good->id,
                                    'property_id' => $property->id,
                                    'property_name' => $propertyName,
                                    'property_value' => $propertyValue,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage()
                                ]);
                                
                                $stats['errors'][] = [
                                    'good_id' => $good->id,
                                    'type' => 'property_value_error',
                                    'message' => $e->getMessage(),
                                    'details' => "Ошибка при создании значения свойства '{$propertyName}': '{$propertyValue}'"
                                ];
                                continue;
                            }
                        }

                        if ($propertyValueModel) {
                            $propertiesToSave[] = [
                                'property_id' => $property->id,
                                'shop_property_value_id' => $propertyValueModel->id,
                                'value' => $propertyValue
                            ];
                        }
                    }

                    // Сохраняем свойства товара
                    if (!empty($propertiesToSave)) {
                        DB::beginTransaction();

                        try {
                            // Удаляем старые свойства товара (только базовые, не вариации)
                            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                            if ($hasVariationIdCol) {
                                $deleteQuery->whereNull('variation_id');
                            }
                            $deleteQuery->delete();

                            // Убираем дубликаты по property_id (оставляем последнее вхождение)
                            $uniqueProperties = [];
                            foreach ($propertiesToSave as $prop) {
                                $uniqueProperties[$prop['property_id']] = $prop;
                            }
                            $propertiesToSave = array_values($uniqueProperties);

                            // Вставляем новые свойства
                            foreach ($propertiesToSave as $prop) {
                                $insertData = [
                                    'good_id' => $good->id,
                                    'property_id' => $prop['property_id'],
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ];

                                // Указываем variation_id как NULL для базовых свойств
                                if ($hasVariationIdCol) {
                                    $insertData['variation_id'] = null;
                                }

                                if ($hasShopValueIdCol) {
                                    $insertData['shop_property_value_id'] = $prop['shop_property_value_id'];
                                }

                                if ($hasValueCol) {
                                    $insertData['value'] = $prop['value'];
                                }

                                // Используем updateOrInsert для избежания дубликатов
                                $whereConditions = [
                                    'good_id' => $good->id,
                                    'property_id' => $prop['property_id']
                                ];
                                
                                if ($hasVariationIdCol) {
                                    $whereConditions['variation_id'] = null;
                                }

                                DB::table('shop_good_properties')->updateOrInsert($whereConditions, $insertData);
                            }

                            DB::commit();
                            $stats['success']++;
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $errorMessage = 'Ошибка сохранения свойств для товара ' . $good->id . ': ' . $e->getMessage();
                            Log::error($errorMessage, [
                                'good_id' => $good->id,
                                'exception' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                                'properties_count' => count($propertiesToSave)
                            ]);
                            
                            $stats['error']++;
                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'save_error',
                                'message' => $e->getMessage(),
                                'details' => 'Ошибка при сохранении свойств в БД'
                            ];
                        }
                    } else {
                        $stats['skipped']++;
                    }

                    $stats['processed']++;
                } catch (\Exception $e) {
                    $errorMessage = 'Ошибка обработки товара ' . $good->id . ': ' . $e->getMessage();
                    Log::error($errorMessage, [
                        'good_id' => $good->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    
                    $stats['error']++;
                    $stats['errors'][] = [
                        'good_id' => $good->id,
                        'type' => 'processing_error',
                        'message' => $e->getMessage(),
                        'details' => 'Ошибка при парсинге или обработке товара'
                    ];
                    $stats['processed']++;
                }
            }

            // Ограничиваем количество ошибок в ответе (первые 50)
            $totalErrorsCount = count($stats['errors']);
            $errorsToReturn = array_slice($stats['errors'], 0, 50);
            $stats['errors'] = $errorsToReturn;
            $stats['total_errors'] = $totalErrorsCount;
            if ($totalErrorsCount > 50) {
                $stats['errors_truncated'] = true;
            }

            return response()->json([
                'success' => true,
                'message' => 'Парсинг завершен',
                'data' => [
                    'stats' => $stats,
                    'has_more' => $goods->count() === $batchSize
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка массового парсинга: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового парсинга: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Парсинг HTML описания для извлечения характеристик
     */
    private function parseDescription(string $htmlDescription): array
    {
        if (empty($htmlDescription)) {
            return [];
        }

        $results = [];
        
        // Используем DOMDocument для парсинга HTML
        libxml_use_internal_errors(true);
        
        // Оборачиваем в контейнер для корректного парсинга
        $wrappedHtml = '<div>' . $htmlDescription . '</div>';
        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(mb_convert_encoding($wrappedHtml, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $lines = [];

        // Сначала ищем все div-ы с data-block="true" - каждая характеристика в отдельном блоке
        $dataBlockElements = $xpath->query('//div[@data-block="true"]');
        
        if ($dataBlockElements && $dataBlockElements->length > 0) {
            // Обрабатываем каждый div с data-block="true" как отдельную строку
            foreach ($dataBlockElements as $element) {
                $innerHTML = $this->getInnerHTML($element);
                $textContent = trim($element->textContent);

                if (!empty($textContent) && mb_strlen($textContent) >= 3) {
                    $lines[] = [
                        'html' => $innerHTML,
                        'text' => $textContent
                    ];
                }
            }
        }

        // Если не нашли data-block элементы, используем стандартную логику
        if (empty($lines)) {
            // Получаем все блочные элементы верхнего уровня
            $allBlockElements = $xpath->query('//p | //div | //li');
            
            $topLevelElements = [];

            if ($allBlockElements && $allBlockElements->length > 0) {
                foreach ($allBlockElements as $element) {
                    // Проверяем, не является ли элемент вложенным
                    $isNested = false;
                    $parent = $element->parentNode;
                    while ($parent && $parent->nodeName !== '#document' && $parent->nodeName !== 'body' && $parent->nodeName !== 'div') {
                        $parentTagName = strtolower($parent->nodeName);
                        if (in_array($parentTagName, ['p', 'div', 'li'])) {
                            $isNested = true;
                            break;
                        }
                        $parent = $parent->parentNode;
                    }

                    if (!$isNested) {
                        $topLevelElements[] = $element;
                    }
                }
            }

            if (count($topLevelElements) > 0) {
                // Если есть блочные элементы верхнего уровня, обрабатываем каждый
                foreach ($topLevelElements as $element) {
                    $innerHTML = $this->getInnerHTML($element);
                    $textContent = trim($element->textContent);

                    if (empty($textContent)) {
                        continue;
                    }

                    // Для остальных элементов разбиваем HTML по <br> тегам для правильной обработки строк
                    $htmlParts = preg_split('/(?:<br\s*\/?>|<br>)/i', $innerHTML);

                    foreach ($htmlParts as $htmlPart) {
                        $htmlPart = trim($htmlPart);
                        if (empty($htmlPart)) {
                            continue;
                        }

                        // Создаем временный DOM для извлечения текста
                        $tempDom = new \DOMDocument('1.0', 'UTF-8');
                        $tempWrapped = '<div>' . $htmlPart . '</div>';
                        @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        $text = trim($tempDom->textContent);

                        if (!empty($text) && mb_strlen($text) >= 3) {
                            $lines[] = [
                                'html' => $htmlPart,
                                'text' => $text
                            ];
                        }
                    }
                }
            }
        }

        // Если не получилось разбить по элементам, разбиваем по переносам строк и тегам
        if (empty($lines)) {
            $htmlContent = $htmlDescription;
            $textContent = strip_tags($htmlDescription);
            
            // Сначала пробуем разбить по HTML тегам
            $htmlParts = preg_split('/(?:<br\s*\/?>|<\/p>|<\/div>|<\/li>)/i', $htmlContent);
            
            if (count($htmlParts) > 1) {
                foreach ($htmlParts as $htmlPart) {
                    $htmlPart = trim($htmlPart);
                    if (empty($htmlPart)) {
                        continue;
                    }
                    
                    $tempDom = new \DOMDocument('1.0', 'UTF-8');
                    $tempWrapped = '<div>' . $htmlPart . '</div>';
                    @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $text = trim($tempDom->textContent);
                    
                    if (!empty($text) && mb_strlen($text) >= 3) {
                        $lines[] = [
                            'html' => $htmlPart,
                            'text' => $text
                        ];
                    }
                }
            } else {
                // Если HTML не разбился, пробуем разбить по переносам строк в тексте
                $textLines = preg_split('/\n/', $textContent);
                foreach ($textLines as $textLine) {
                    $textLine = trim($textLine);
                    if (!empty($textLine) && mb_strlen($textLine) >= 3) {
                        $lines[] = [
                            'html' => $textLine,
                            'text' => $textLine
                        ];
                    }
                }
            }
        }
        
        // Если все еще нет строк, берем весь контент как одну строку
        if (empty($lines)) {
            $htmlContent = $htmlDescription;
            $textContent = strip_tags($htmlDescription);
            
            if (!empty($textContent) && mb_strlen($textContent) >= 3) {
                $lines[] = [
                    'html' => $htmlContent,
                    'text' => $textContent
                ];
            }
        }

        // Парсим каждую строку
        foreach ($lines as $lineData) {
            $lineHTML = $lineData['html'];
            $lineText = $lineData['text'];

            if (empty($lineText) || mb_strlen($lineText) < 3) {
                continue;
            }

            $propertyName = '';
            $propertyValue = '';

            // Парсим HTML строки для поиска жирного текста
            $lineWrapped = '<div>' . $lineHTML . '</div>';
            $lineDom = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            @$lineDom->loadHTML(mb_convert_encoding($lineWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $lineXpath = new \DOMXPath($lineDom);
            
            // Ищем жирные элементы (strong, b) - сначала стандартные теги
            $boldElements = $lineXpath->query('//strong | //b');
            $boldElement = null;
            
            if ($boldElements && $boldElements->length > 0) {
                $boldElement = $boldElements->item(0);
            } else {
                // Если не нашли стандартные теги, ищем все элементы и проверяем их стили
                $allElements = $lineXpath->query('//*');
                if ($allElements) {
                    foreach ($allElements as $elem) {
                        // Проверяем тег
                        $tagName = strtolower($elem->nodeName);
                        if ($tagName === 'strong' || $tagName === 'b') {
                            $boldElement = $elem;
                            break;
                        }
                        
                        // Проверяем inline стиль
                        if ($elem->hasAttribute('style')) {
                            $style = $elem->getAttribute('style');
                            if (preg_match('/font-weight\s*:\s*(bold|700|6\d{2}|7\d{2}|8\d{2}|900)/i', $style)) {
                                $boldElement = $elem;
                                break;
                            }
                        }
                        
                        // Проверяем класс
                        if ($elem->hasAttribute('class')) {
                            $className = $elem->getAttribute('class');
                            if (preg_match('/(?:^|\s)(?:bold|font-bold|font-weight-bold|fw-bold)(?:\s|$)/i', $className)) {
                                $boldElement = $elem;
                                break;
                            }
                        }
                    }
                }
            }

            if ($boldElement) {
                // ШАГ 1: Извлекаем ВЕСЬ текст из жирного элемента - это характеристика
                $propertyName = trim($boldElement->textContent);
                $propertyName = preg_replace('/\s+/', ' ', $propertyName);
                
                // Убираем двоеточие в конце названия, если есть
                $propertyName = preg_replace('/:\s*$/', '', $propertyName);
                
                if (!empty($propertyName)) {
                    // ШАГ 2: Ищем значение - сначала пробуем найти следующий sibling элемент
                    $propertyValue = '';
                    $nextSibling = $boldElement->nextSibling;
                    
                    while ($nextSibling) {
                        if ($nextSibling->nodeType === XML_ELEMENT_NODE) {
                            $siblingText = trim($nextSibling->textContent);
                            if (!empty($siblingText)) {
                                $propertyValue = $siblingText;
                                break;
                            }
                        } elseif ($nextSibling->nodeType === XML_TEXT_NODE) {
                            $text = trim($nextSibling->textContent);
                            if (!empty($text)) {
                                $propertyValue = $text;
                                break;
                            }
                        }
                        $nextSibling = $nextSibling->nextSibling;
                    }
                    
                    // Если не нашли через sibling, пробуем извлечь из родительского элемента
                    if (empty($propertyValue)) {
                        $parent = $boldElement->parentNode;
                        if ($parent) {
                            $parentText = trim($parent->textContent);
                            $boldText = trim($boldElement->textContent);
                            
                            // Убираем название из текста родителя
                            $parentText = str_replace($boldText, '', $parentText);
                            $parentText = preg_replace('/^:\s*/', '', $parentText);
                            $propertyValue = trim($parentText);
                        }
                    }
                    
                    // Если все еще пусто, пробуем найти через HTML позицию
                    if (empty($propertyValue)) {
                        $boldOuterHTML = $this->getOuterHTML($boldElement);
                        $elementIndex = mb_strpos($lineHTML, $boldOuterHTML);
                        
                        if ($elementIndex !== false) {
                            $boldEndIndex = $elementIndex + mb_strlen($boldOuterHTML);
                            $afterBoldHTML = mb_substr($lineHTML, $boldEndIndex);
                            
                            $afterBoldWrapped = '<div>' . $afterBoldHTML . '</div>';
                            $afterBoldDom = new \DOMDocument('1.0', 'UTF-8');
                            libxml_use_internal_errors(true);
                            @$afterBoldDom->loadHTML(mb_convert_encoding($afterBoldWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                            libxml_clear_errors();
                            $propertyValue = trim($afterBoldDom->textContent);
                        }
                    }
                    
                    // Убираем двоеточие в начале, если есть
                    $propertyValue = preg_replace('/^:\s*/', '', $propertyValue);
                    
                    // Нормализуем пробелы
                    $propertyValue = preg_replace('/\s+/', ' ', trim($propertyValue));
                }
            }
            
            // Если не нашли через жирный текст, пробуем вариант с двоеточием
            if (empty($propertyName) || empty($propertyValue)) {
                // Вариант 2: Ищем текст до двоеточия в текстовом представлении
                // Но только если двоеточие находится в начале строки или после короткого названия (не более 3 слов)
                $colonIndex = mb_strpos($lineText, ':');
                if ($colonIndex !== false && $colonIndex > 0 && $colonIndex < mb_strlen($lineText) - 1) {
                    $beforeColon = trim(mb_substr($lineText, 0, $colonIndex));
                    $wordsBeforeColon = preg_split('/\s+/u', $beforeColon);
                    $wordsBeforeColon = array_filter($wordsBeforeColon, function($w) { return !empty(trim($w)); });
                    
                    // Используем двоеточие только если до него не более 3 слов (чтобы не разбивать значения с двоеточием внутри)
                    if (count($wordsBeforeColon) > 0 && count($wordsBeforeColon) <= 3) {
                        $propertyName = $beforeColon;
                        $propertyValue = trim(mb_substr($lineText, $colonIndex + 1));
                    } else {
                        // Если до двоеточия больше 3 слов, это скорее всего двоеточие внутри значения, пропускаем
                        $colonIndex = false;
                    }
                }
                
                if ($colonIndex === false) {
                    // Вариант 3: Дополнительная проверка - поиск до первого дефиса (но не более 3-х слов)
                    // Ищем дефис с пробелами вокруг (например: " - " или " -")
                    $dashPattern = '/\s*-\s*/u';
                    $dashMatch = preg_match($dashPattern, $lineText, $matches, PREG_OFFSET_CAPTURE);
                    
                    if ($dashMatch && isset($matches[0]) && isset($matches[0][1])) {
                        $dashIndex = $matches[0][1];
                        $beforeDash = trim(mb_substr($lineText, 0, $dashIndex));
                        
                        // Проверяем, что до дефиса не более 3-х слов
                        $words = preg_split('/\s+/u', $beforeDash);
                        $words = array_filter($words, function($w) { return !empty(trim($w)); });
                        
                        if (count($words) > 0 && count($words) <= 3) {
                            $propertyName = $beforeDash;
                            // Берем все после дефиса (включая сам дефис и пробелы)
                            $propertyValue = trim(mb_substr($lineText, $dashIndex + mb_strlen($matches[0][0])));
                        } else {
                            // Если нет ни жирного текста, ни двоеточия, ни подходящего дефиса - пропускаем строку
                            continue;
                        }
                    } else {
                        // Если нет ни жирного текста, ни двоеточия, ни дефиса - пропускаем строку
                        continue;
                    }
                }
            }

            // Нормализуем
            $propertyName = preg_replace('/\s+/', ' ', trim($propertyName));
            $propertyValue = preg_replace('/\s+/', ' ', trim($propertyValue));
            
            // Убираем двоеточие в начале и конце значения, если оно там есть (на случай ошибок парсинга)
            $propertyValue = preg_replace('/^:\s*/', '', $propertyValue);
            $propertyValue = preg_replace('/\s*:\s*$/', '', $propertyValue);
            $propertyValue = trim($propertyValue);

            // Трансформируем название: только первое слово с большой буквы
            $propertyName = mb_strtolower($propertyName);
            $propertyName = mb_strtoupper(mb_substr($propertyName, 0, 1)) . mb_substr($propertyName, 1);

            if (empty($propertyName) || empty($propertyValue) || mb_strlen($propertyName) < 2 || mb_strlen($propertyValue) < 1) {
                continue;
            }

            // Проверяем на дубликаты
            $isDuplicate = false;
            foreach ($results as $result) {
                if (mb_strtolower(trim($result['name'])) === mb_strtolower(trim($propertyName)) &&
                    mb_strtolower(trim($result['value'])) === mb_strtolower(trim($propertyValue))) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                $results[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue
                ];
            }
        }

        return $results;
    }

    /**
     * Получить innerHTML элемента
     */
    private function getInnerHTML(\DOMElement $element): string
    {
        $innerHTML = '';
        $children = $element->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }
        return $innerHTML;
    }

    /**
     * Получить outerHTML элемента
     */
    private function getOuterHTML(\DOMElement $element): string
    {
        return $element->ownerDocument->saveHTML($element);
    }

    /**
     * Получить атрибуты вариации
     */
    public function getVariationAttributes($goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $attributesString = $variation->attributes_string;

            return response()->json([
                'success' => true,
                'data' => $attributesString
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения атрибутов вариации: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Обновить остаток вариации
     */
    public function updateVariationStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'stock_quantity' => 'required|integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $variation->stock_quantity = $request->get('stock_quantity');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Остаток обновлен',
                'data' => [
                    'id' => $variation->id,
                    'stock_quantity' => $variation->stock_quantity
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления остатка: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить остаток на у/с вариации
     */
    public function updateVariationRemoteStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'remote_stock_quantity' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $remoteStockValue = $request->get('remote_stock_quantity');
            $variation->remote_stock_quantity = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string)$remoteStockValue;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Остаток на у/с обновлен',
                'data' => [
                    'id' => $variation->id,
                    'remote_stock_quantity' => $variation->remote_stock_quantity
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления остатка на у/с: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить цены вариации
     */
    public function updateVariationPrice(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'price' => 'required|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'demping_price' => 'nullable|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $variation->price = $request->get('price');
            $variation->sale_price = $request->has('sale_price') && $request->get('sale_price') !== null && $request->get('sale_price') !== '' ? $request->get('sale_price') : null;
            $variation->demping_price = $request->has('demping_price') && $request->get('demping_price') !== null && $request->get('demping_price') !== '' ? $request->get('demping_price') : null;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Цены обновлены',
                'data' => [
                    'id' => $variation->id,
                    'price' => $variation->price,
                    'sale_price' => $variation->sale_price,
                    'demping_price' => $variation->demping_price
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления цен: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить демпинг вариации
     */
    public function updateVariationDemping(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'show_demping' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $variation->show_demping = $request->get('show_demping');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Демпинг обновлен',
                'data' => [
                    'id' => $variation->id,
                    'show_demping' => $variation->show_demping
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления демпинга: ' . $e->getMessage()
            ], 500);
        }
    }
}
