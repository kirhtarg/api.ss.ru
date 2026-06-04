<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImageDownloadTaskJob;
use App\Jobs\PostProcessImage;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Models\ShopLabel;
use App\Models\ShopProperty;
use App\Models\ShopPropertyValue;
use App\Models\ShopTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShopGoodsController extends Controller
{
    /**
     * Получить список товаров с фильтрацией и пагинацией
     */
    public function index(Request $request): JsonResponse
    {

        $query = ShopGood::select([
            'id', 'name', 'slug', 'sku', 'description', 'short_description',
            'price', 'sale_price', 'demping_price', 'show_demping', 'label_id', 'supplier',
            'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'rating', 'reviews_count',
            'width', 'height', 'depth', 'weight',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'is_show', 'sort_order',
            'created_at', 'updated_at',
        ])->with([
            'categories:id,name',
            'brands:id,name',
            'tags:id,name,color',
            'label:id,name,color',
            'properties:id,name,slug',
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
            'variations:id,good_id,name,sku,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity,is_active,supplier',
            'variations.images:id,variation_id,file_path,alt_text,is_main,sort_order',
        ])->withCount('variations');

        // Флаг: применять фильтры остатков только к основному товару, игнорируя вариации
        $stockOnlyGoods = $request->boolean('stock_only_goods', false);
        $this->normalizeCatalogFilterAliases($request);

        // Загружаем pivot данные для свойств (поддерживаем разные схемы: value или shop_property_value_id)
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $query->with(['properties' => function ($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Фильтр по массиву ID (для массовой загрузки и экспорта) - должен быть первым
        // Проверяем selected_ids (для экспорта выбранных товаров)
        if ($request->has('selected_ids') && ! empty($request->input('selected_ids'))) {
            $selectedIdsRaw = $request->input('selected_ids');

            // Если это строка с запятыми, разбиваем на массив
            if (is_string($selectedIdsRaw) && strpos($selectedIdsRaw, ',') !== false) {
                $selectedIds = explode(',', $selectedIdsRaw);
            } elseif (is_array($selectedIdsRaw)) {
                $selectedIds = $selectedIdsRaw;
            } else {
                $selectedIds = [$selectedIdsRaw];
            }

            $selectedIds = array_map('intval', array_filter($selectedIds, function ($id) {
                return is_numeric($id) && $id > 0;
            }));

            if (! empty($selectedIds)) {
                $query->whereIn('id', $selectedIds);
                // Когда есть selected_ids, пропускаем все остальные фильтры
                // (экспортируем только выбранные товары без дополнительных фильтров)
            }
        } else {
            // Обычная фильтрация
            // Проверяем оба варианта: ids[] и ids (для других случаев)
            $ids = null;
            if ($request->has('ids')) {
                $ids = $request->input('ids');
            } elseif ($request->has('ids[]')) {
                $ids = $request->input('ids[]');
            }

            if ($ids !== null) {
                // Если это не массив, пытаемся преобразовать
                if (! is_array($ids)) {
                    $ids = [$ids];
                }

                if (! empty($ids)) {
                    $ids = array_map('intval', $ids);
                    $ids = array_filter($ids, function ($id) {
                        return $id > 0;
                    });

                    if (! empty($ids)) {
                        $query->whereIn('id', $ids);
                    }
                }
            }

            // Применяем фильтры только если нет ids
            if (! $ids || empty($ids)) {
                // $this->applyGoodsFilters($query, $request);
            }
        }
        if ($request->filled('search')) {
            $search = $request->get('search');
            // Если передан параметр search_only_name_sku, ищем только по названию и артикулу
            $searchOnlyNameSku = $request->input('search_only_name_sku');
            if ($searchOnlyNameSku && ($searchOnlyNameSku === '1' || $searchOnlyNameSku === 1 || $searchOnlyNameSku === true || $searchOnlyNameSku === 'true')) {
                $query->searchNameSku($search);
            } else {
                $query->search($search);
            }
        }

        // Фильтр по названию (точное вхождение текста)
        if ($request->filled('name_search')) {
            $nameSearch = $request->get('name_search');
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($nameSearch).'%']);
        }

        // Фильтр по артикулу (точное вхождение текста) - обновленная логика с учетом вариаций
        if ($request->filled('sku_search')) {
            $skuSearch = $request->get('sku_search');
            $skuSearchLower = '%'.mb_strtolower($skuSearch).'%';

            $query->where(function ($mainQuery) use ($skuSearchLower) {
                // Поиск по артикулу основного товара
                $mainQuery->whereRaw('LOWER(sku) LIKE ?', [$skuSearchLower])
                    // Поиск по артикулам вариаций
                    ->orWhereHas('variations', function ($variationQuery) use ($skuSearchLower) {
                        $variationQuery->whereRaw('LOWER(sku) LIKE ?', [$skuSearchLower]);
                    });
            });
        }

        // Логирование фильтра по артикулу
        if ($request->filled('sku_search')) {
        }

        // Фильтр: дубли по названию (нормализовано по LOWER(TRIM(name)))
        if ($request->filled('duplicate_names')) {
            $query->whereNotNull('name')
                ->whereRaw('LENGTH(TRIM(name)) > 0');

            // Подключаем подзапрос с группировкой дублей и отфильтровываем по нему
            $duplicatesSubquery = DB::table('shop_goods')
                ->select(DB::raw('LOWER(TRIM(name)) as norm_name'))
                ->whereNotNull('name')
                ->whereRaw('LENGTH(TRIM(name)) > 0')
                ->groupBy(DB::raw('LOWER(TRIM(name))'))
                ->havingRaw('COUNT(*) > 1');

            $query->joinSub($duplicatesSubquery, 'dup', function ($join) {
                $join->on(DB::raw('LOWER(TRIM(shop_goods.name))'), '=', 'dup.norm_name');
            })->distinct('shop_goods.id');
        }

        // Фильтр по категории
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Фильтр по множественным категориям
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
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
            if (is_array($brandIds) && ! empty($brandIds)) {
                $query->whereHas('brands', function ($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Фильтр по тегу
        if ($request->filled('tag_id')) {
            $query->byTag($request->get('tag_id'));
        }

        // Фильтр по габаритам и весу
        if ($request->filled('dimensions_weight_filter')) {
            $type = $request->input('dimensions_weight_filter');
            if ($type === 'empty_dimensions') {
                $query->where(function ($q) {
                    $q->whereNull('width')->orWhere('width', 0)
                        ->orWhereNull('height')->orWhere('height', 0)
                        ->orWhereNull('depth')->orWhere('depth', 0);
                });
            } elseif ($type === 'empty_weight') {
                $query->where(function ($q) {
                    $q->whereNull('weight')->orWhere('weight', 0);
                });
            } elseif ($type === 'empty_both') {
                $query->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('width')->orWhere('width', 0)
                            ->orWhereNull('height')->orWhere('height', 0)
                            ->orWhereNull('depth')->orWhere('depth', 0);
                    })->where(function ($sq) {
                        $sq->whereNull('weight')->orWhere('weight', 0);
                    });
                });
            } elseif ($type === 'filled_dimensions') {
                $query->where('width', '>', 0)
                    ->where('height', '>', 0)
                    ->where('depth', '>', 0);
            } elseif ($type === 'filled_weight') {
                $query->where('weight', '>', 0);
            } elseif ($type === 'filled_both') {
                $query->where('width', '>', 0)
                    ->where('height', '>', 0)
                    ->where('depth', '>', 0)
                    ->where('weight', '>', 0);
            } elseif ($type === 'exact_dimensions') {
                $exactFields = [
                    'exact_width' => 'width',
                    'exact_height' => 'height',
                    'exact_depth' => 'depth',
                    'exact_weight' => 'weight',
                ];

                foreach ($exactFields as $requestKey => $column) {
                    if (! $request->filled($requestKey)) {
                        continue;
                    }

                    $value = str_replace(',', '.', (string) $request->input($requestKey));
                    if (is_numeric($value)) {
                        $query->where($column, (float) $value);
                    }
                }
            }
        }

        // Фильтр по изображениям
        if ($request->filled('images_filter')) {
            $type = $request->input('images_filter');
            if ($type === 'with_images_main') {
                $query->has('images');
            } elseif ($type === 'with_images_variations') {
                $query->has('variations.images');
            } elseif ($type === 'without_images_main') {
                // Выводим только записи без изображений И без вариаций (согласно пожеланию пользователя)
                $query->doesntHave('images')->whereDoesntHave('variations');
            } elseif ($type === 'without_images_variations') {
                $query->whereHas('variations', function ($q) {
                    $q->doesntHave('images');
                });
            }
        }

        // Фильтр по атрибутам вариаций (товары С этими атрибутами)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            if (! empty($attributeNames)) {
                // Фильтруем товары, у которых есть ХОТЯ БЫ ОДНА вариация со ВСЕМИ указанными атрибутами
                $query->whereExists(function ($subQuery) use ($attributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->where(function ($q) use ($attributeNames) {
                            foreach ($attributeNames as $name) {
                                $q->whereExists(function ($attrSubQuery) use ($name) {
                                    $attrSubQuery->selectRaw('1')
                                        ->from('shop_variation_attributes_values as vav')
                                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                        ->whereColumn('vav.variation_id', 'v.id')
                                        ->where('a.name', $name);
                                });
                            }
                        });
                });
            }
        }

        // Исключение атрибутов вариаций
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // Показываем товары с вариациями, у которых нет указанных атрибутов
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->whereIn('a.name', $excludeAttributeNames)
                        ->limit(1);
                });
            }
        }

        // Фильтр по множественным тегам
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && ! empty($tagIds)) {
                $query->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Фильтр по поставщику (текстовое поле)
        if ($request->filled('supplier')) {
            $supplier = $request->get('supplier');
            $query->where('supplier', $supplier);
        }

        // Фильтр по множественным поставщикам
        if ($request->has('suppliers')) {
            $supplierIds = $request->input('suppliers');
            $includeVariations = $request->boolean('suppliers_include_variations', false);

            if (is_array($supplierIds) && ! empty($supplierIds)) {
                if ($includeVariations) {
                    // Включаем товары с поставщиками Ч товары с вариациями с поставщиками
                    $query->where(function ($q) use ($supplierIds) {
                        // Товары с выбранными поставщиками
                        $q->whereIn('supplier', $supplierIds)
                          // Чли товары, у которых есть вариации с выбранными поставщиками
                            ->orWhereHas('variations', function ($varQ) use ($supplierIds) {
                                $varQ->whereIn('supplier', $supplierIds);
                            });
                    });
                } else {
                    // Только товары с поставщиками (без учета вариаций)
                    $query->whereIn('supplier', $supplierIds);
                }
            }
        }

        // Фильтр "Без поставщиков"
        if ($request->has('supplier_empty')) {
            $includeVariations = $request->boolean('supplier_empty_include_variations', false);

            if ($includeVariations) {
                $query->where(function ($q) {
                    $q->whereHas('variations', function ($varQ) {
                        $varQ->where(function ($supplierQ) {
                            $supplierQ->whereNull('supplier')
                                ->orWhere('supplier', '');
                        });
                    })->orWhere(function ($noVarQ) {
                        $noVarQ->whereDoesntHave('variations')
                            ->where(function ($supplierQ) {
                                $supplierQ->whereNull('supplier')
                                    ->orWhere('supplier', '');
                            });
                    });
                });
            } else {
                // Только товары без поставщика в основном товаре
                $query->where(function ($q) {
                    $q->whereNull('supplier')
                        ->orWhere('supplier', '');
                });
            }
        }

        // Фильтр "Поставщики без товаров" - новый фильтр для поставщиков, не привязанных к товарам
        // Фильтр "Без поставщиков" - зеркальный фильтр "С поставщиками"
        if ($request->has('unlinked_suppliers')) {
            $unlinkedSupplierNames = $request->input('unlinked_suppliers');
            $includeVariations = $request->boolean('unlinked_suppliers_include_variations', false);

            if (is_array($unlinkedSupplierNames) && ! empty($unlinkedSupplierNames)) {
                if ($includeVariations) {
                    // Зеркально: показываем товары, у которых НЕТ выбранных поставщиков (включая вариации)
                    $query->where(function ($q) use ($unlinkedSupplierNames) {
                        // Товары без выбранных поставщиков
                        $q->whereNotIn('supplier', $unlinkedSupplierNames)
                          // Ч товары, у которых нет вариаций с выбранными поставщиками
                            ->whereDoesntHave('variations', function ($varQ) use ($unlinkedSupplierNames) {
                                $varQ->whereIn('supplier', $unlinkedSupplierNames);
                            });
                    });
                } else {
                    // Зеркально: показываем товары, у которых НЕТ выбранных поставщиков (только основной товар)
                    $query->whereNotIn('supplier', $unlinkedSupplierNames);
                }
            }
        }

        // Фильтр по лейблам
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && ! empty($labelIds)) {
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
                $query->where(function ($q) {
                    $q->where('show_demping', false)
                        ->orWhereNull('show_demping');
                });
            } elseif ($hasDemping === 'variations') {
                // Товары с демпингом в вариациях
                $query->whereHas('variations', function ($q) {
                    $q->where('show_demping', true);
                });
            } elseif ($hasDemping === 'both') {
                // Товары с демпингом в основном товаре ЧЛЧ в вариациях (или в обоих)
                $query->where(function ($q) {
                    $q->where('show_demping', true)
                        ->orWhereHas('variations', function ($subQ) {
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
                $query->where(function ($q) {
                    $q->whereNull('demping_price')
                        ->orWhere('demping_price', '=', 0);
                });
            } elseif ($hasDempingPrice === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('demping_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('shop_good_variations.demping_price', '>', 'shop_good_variations.price');
                    });
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

        // Фильтр по наличию акционной цены (has_sale_price)
        if ($request->filled('has_sale_price')) {
            $hasSalePrice = $request->get('has_sale_price');
            if ($hasSalePrice === 'true') {
                $query->where(function ($q) {
                    // Проверяем акционную цену основного товара
                    $q->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                      // Чли акционную цену в вариациях
                        ->orWhereHas('variations', function ($varQ) {
                            $varQ->whereNotNull('sale_price')
                                ->where('sale_price', '>', 0);
                        });
                });
            } elseif ($hasSalePrice === 'false') {
                $query->where(function ($q) {
                    // Основной товар без акционной цены
                    $q->where(function ($mainQ) {
                        $mainQ->whereNull('sale_price')
                            ->orWhere('sale_price', '=', 0);
                    })
                    // Ч нет вариаций с акционной ценой
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('sale_price')
                                ->where('sale_price', '>', 0);
                        });
                });
            } elseif ($hasSalePrice === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('sale_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('shop_good_variations.sale_price', '>', 'shop_good_variations.price');
                    });
                });
            }
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
                        $query->where(function ($q) use ($minSalePrice) {
                            $q->whereNotNull('sale_price')
                                ->where('sale_price', '>=', $minSalePrice);
                        });
                    }
                }
                if ($maxSalePrice !== null) {
                    if ($maxSalePrice == 0) {
                        // Фильтр "равна 0" - включаем null и 0
                        $query->where(function ($q) {
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
                            $query->where(function ($q) use ($maxSalePrice) {
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

        // Фильтр по наличию (in_stock) - новая логика для работы с вариациями
        if ($request->filled('stock_variations_not_empty') || $request->filled('stock_goods_not_empty') ||
            $request->filled('stock_variations_empty') || $request->filled('stock_goods_empty') ||
            $request->filled('stock_variations_exact') || $request->filled('stock_goods_exact') ||
            $request->filled('stock_variations_low') || $request->filled('stock_goods_low')) {

            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('stock_variations_not_empty') === '1' || $request->get('stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций (хотя бы одна вариация с остатком > 0)
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('stock_quantity', '>', 0);
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '>', 0);
                        });
                });
            } elseif ($request->get('stock_variations_empty') === '1' || $request->get('stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - у всех вариаций должны быть пустые все виды остатков.
                    // Остатки основного товара в этом случае игнорируются.
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')
                                        ->where('fast_remote_stock_quantity', '!=', '')
                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                });
                        })
                    // Вариант 2: Товары без вариаций - у основного товара должны быть пустые все виды остатков.
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($stockQuery) {
                                    $stockQuery->where('stock_quantity', '=', 0)
                                        ->orWhereNull('stock_quantity');
                                })
                                ->where(function ($remoteQuery) {
                                    $remoteQuery->whereNull('remote_stock_quantity')
                                        ->orWhere('remote_stock_quantity', '=', '0')
                                        ->orWhere('remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                })
                                ->where(function ($fastRemoteQuery) {
                                    $fastRemoteQuery->whereNull('fast_remote_stock_quantity')
                                        ->orWhere('fast_remote_stock_quantity', '=', '0')
                                        ->orWhere('fast_remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                });
                        });
                });
            } elseif ($request->get('stock_variations_low') === '1' || $request->get('stock_goods_low') === '1') {
                // Меньше 3-х, исключая 0
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем, что сумма остатков вариаций < 3 и > 0
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) < 3')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) > 0')
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '<', 3)
                                ->where('stock_quantity', '>', 0);
                        });
                });
            } elseif ($request->filled('stock_variations_exact') || $request->filled('stock_goods_exact')) {
                $exactValue = $request->get('stock_variations_exact') ?: $request->get('stock_goods_exact');

                if ($exactValue !== '') {
                    $query->where(function ($mainQuery) use ($exactValue) {
                        // Вариант 1: Товары с вариациями - проверяем сумму остатков вариаций
                        $mainQuery->whereHas('variations')
                            ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = ?', [$exactValue])
                        // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                            ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                                $noVariationsQuery->whereDoesntHave('variations')
                                    ->where('stock_quantity', '=', $exactValue);
                            });
                    });
                }
            }
        }

        // Фильтр по диапазону остатков (stock_quantity_min/max) - новая логика
        if (($request->filled('stock_quantity_variations_min') || $request->filled('stock_quantity_goods_min')) || ($request->filled('stock_quantity_variations_max') || $request->filled('stock_quantity_goods_max'))) {
            $min = null;
            $max = null;

            if ($request->filled('stock_quantity_variations_min')) {
                $min = (int) $request->get('stock_quantity_variations_min');
            } elseif ($request->filled('stock_quantity_goods_min')) {
                $min = (int) $request->get('stock_quantity_goods_min');
            }

            if ($request->filled('stock_quantity_variations_max')) {
                $max = (int) $request->get('stock_quantity_variations_max');
            } elseif ($request->filled('stock_quantity_goods_max')) {
                $max = (int) $request->get('stock_quantity_goods_max');
            }

            $query->where(function ($mainQuery) use ($min, $max) {
                // Вариант 1: Товары с вариациями - проверяем остатки вариаций
                $mainQuery->whereHas('variations', function ($varQ) use ($min, $max) {
                    if ($min !== null && $max !== null) {
                        if ($min === $max) {
                            $varQ->where('stock_quantity', '=', $min);
                        } else {
                            $varQ->whereBetween('stock_quantity', [$min, $max]);
                        }
                    } elseif ($min !== null) {
                        $varQ->where('stock_quantity', '>=', $min);
                    } elseif ($max !== null) {
                        $varQ->where('stock_quantity', '<=', $max);
                    }
                })
                // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                    ->orWhere(function ($noVariationsQuery) use ($min, $max) {
                        $noVariationsQuery->whereDoesntHave('variations')
                            ->where(function ($stockQuery) use ($min, $max) {
                                if ($min !== null && $max !== null) {
                                    if ($min === $max) {
                                        $stockQuery->where('stock_quantity', '=', $min);
                                    } else {
                                        $stockQuery->whereBetween('stock_quantity', [$min, $max]);
                                    }
                                } elseif ($min !== null) {
                                    $stockQuery->where('stock_quantity', '>=', $min);
                                } elseif ($max !== null) {
                                    $stockQuery->where('stock_quantity', '<=', $max);
                                }
                            });
                    });
            });
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
            if (is_array($excludeCategoryIds) && ! empty($excludeCategoryIds)) {
                $query->whereDoesntHave('categories', function ($q) use ($excludeCategoryIds) {
                    $q->whereIn('shop_categories.id', $excludeCategoryIds);
                });
            }
        }

        // Фильтр по исключению брендов (товары БЕЗ выбранных брендов)
        if ($request->has('exclude_brands')) {
            $excludeBrandIds = $request->input('exclude_brands');
            if (is_array($excludeBrandIds) && ! empty($excludeBrandIds)) {
                $query->whereDoesntHave('brands', function ($q) use ($excludeBrandIds) {
                    $q->whereIn('shop_brands.id', $excludeBrandIds);
                });
            }
        }

        // Фильтр по исключению характеристик (товары БЕЗ выбранных характеристик)
        if ($request->has('exclude_properties')) {
            $excludeProperties = $request->input('exclude_properties');
            if (is_array($excludeProperties) && ! empty($excludeProperties)) {
                $query->where(function ($q) use ($excludeProperties) {
                    foreach ($excludeProperties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && ! empty($valueIds)) {
                            $q->whereDoesntHave('properties', function ($propQuery) use ($propertyId, $valueIds) {
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
            if (is_array($excludeLabelIds) && ! empty($excludeLabelIds)) {
                $query->where(function ($q) use ($excludeLabelIds) {
                    $q->whereNull('label_id')
                        ->orWhereNotIn('label_id', $excludeLabelIds);
                });
            }
        }

        // Фильтр по исключению тегов (товары БЕЗ выбранных тегов)
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && ! empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function ($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Фильтры по описаниям (с учётом удаления HTML-тегов и пробелов)
        // Поддерживаются варианты:
        // - description_not_empty=1 | description_empty=1
        // - short_description_not_empty=1 | short_description_empty=1
        // - descriptions_both_not_empty=1 | descriptions_both_empty=1
        // Для обратной совместимости также поддерживается has_description=1 (только наличие полного описания)
        $cleanDescExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, '<p>', ''), '</p>', ''), '<br>', ''), '<br/>', ''), '<br />', ''), '&nbsp;', ''), '<div>', ''), '</div>', ''), '<span>', ''), '</span>', '')";
        $cleanShortDescExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(short_description, '<p>', ''), '</p>', ''), '<br>', ''), '<br/>', ''), '<br />', ''), '&nbsp;', ''), '<div>', ''), '</div>', ''), '<span>', ''), '</span>', '')";

        if ($request->filled('description_not_empty')) {
            $query->whereNotNull('description')
                ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0");
        } elseif ($request->filled('description_empty')) {
            $query->where(function ($q) use ($cleanDescExpr) {
                $q->whereNull('description')
                    ->orWhereRaw("LENGTH(TRIM($cleanDescExpr)) = 0");
            });
        }

        if ($request->filled('short_description_not_empty')) {
            $query->whereNotNull('short_description')
                ->whereRaw("LENGTH(TRIM($cleanShortDescExpr)) > 0");
        } elseif ($request->filled('short_description_empty')) {
            $query->where(function ($q) use ($cleanShortDescExpr) {
                $q->whereNull('short_description')
                    ->orWhereRaw("LENGTH(TRIM($cleanShortDescExpr)) = 0");
            });
        }

        if ($request->filled('descriptions_both_not_empty')) {
            $query->whereNotNull('description')
                ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0")
                ->whereNotNull('short_description')
                ->whereRaw("LENGTH(TRIM($cleanShortDescExpr)) > 0");
        } elseif ($request->filled('descriptions_both_empty')) {
            $query->where(function ($q) use ($cleanDescExpr) {
                $q->whereNull('description')
                    ->orWhereRaw("LENGTH(TRIM($cleanDescExpr)) = 0");
            })->where(function ($q) use ($cleanShortDescExpr) {
                $q->whereNull('short_description')
                    ->orWhereRaw("LENGTH(TRIM($cleanShortDescExpr)) = 0");
            });
        }

        // Обратная совместимость: старый параметр has_description=1
        if ($request->filled('has_description')) {
            $hasDescription = $request->get('has_description');
            if ($hasDescription === '1' || $hasDescription === 'true') {
                $query->whereNotNull('description')
                    ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0");
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

        // Фильтр по is_show
        if ($request->filled('is_show')) {
            $query->where('is_show', $request->boolean('is_show'));
        }

        // Фильтр по характеристикам (properties[property_id][])
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties) && ! empty($properties)) {
                // Логика ЧЛЧ - товар должен иметь хотя бы одну из выбранных характеристик
                $query->where(function ($q) use ($properties) {
                    foreach ($properties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && ! empty($valueIds)) {
                            $q->orWhereHas('properties', function ($propQuery) use ($propertyId, $valueIds) {
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
                $exactCount = (int) $request->get('properties_count');
                $query->has('properties', '=', $exactCount);
            }
        }

        // Фильтр по артикулу
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            } elseif ($skuFilterType === 'not_empty') {
                $query->whereNotNull('sku')
                    ->where('sku', '!=', '');
            }
        }

        // Фильтр по остатку у/с (remote_stock_quantity) - новая логика
        if ($request->filled('remote_stock_variations_not_empty') && $request->filled('remote_stock_goods_not_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('remote_stock_variations_not_empty') === '1' || $request->get('remote_stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->whereNotNull('remote_stock_quantity')
                                ->where('remote_stock_quantity', '!=', '')
                                ->where('remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                        });
                });
            }
        } elseif ($request->filled('remote_stock_variations_empty') && $request->filled('remote_stock_goods_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('remote_stock_variations_empty') === '1' || $request->get('remote_stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем, что все вариации пустые
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('remote_stock_quantity')
                                ->where('remote_stock_quantity', '!=', '')
                                ->where('remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                        })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($remoteCondition) {
                                    $remoteCondition->whereNull('remote_stock_quantity')
                                        ->orWhere('remote_stock_quantity', '=', '0')
                                        ->orWhere('remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                });
                        });
                });
            }
        } elseif ($request->filled('remote_stock_variations_exact') && $request->filled('remote_stock_goods_exact')) {
            $exactValue = $request->get('remote_stock_variations_exact');

            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('remote_stock_variations_exact') !== '' || $request->get('remote_stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций
                    $mainQuery->whereHas('variations', function ($varQ) use ($exactValue) {
                        $varQ->where('remote_stock_quantity', '=', $exactValue);
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('remote_stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Фильтр по остатку у/с быстро (fast_remote_stock_quantity) - новая логика
        if ($request->filled('fast_remote_stock_variations_not_empty') && $request->filled('fast_remote_stock_goods_not_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('fast_remote_stock_variations_not_empty') === '1' || $request->get('fast_remote_stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('fast_remote_stock_quantity')
                            ->where('fast_remote_stock_quantity', '!=', '')
                            ->where('fast_remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->whereNotNull('fast_remote_stock_quantity')
                                ->where('fast_remote_stock_quantity', '!=', '')
                                ->where('fast_remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                        });
                });
            }
        } elseif ($request->filled('fast_remote_stock_variations_empty') && $request->filled('fast_remote_stock_goods_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('fast_remote_stock_variations_empty') === '1' || $request->get('fast_remote_stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем, что все вариации пустые
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('fast_remote_stock_quantity')
                                ->where('fast_remote_stock_quantity', '!=', '')
                                ->where('fast_remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                        })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($fastRemoteCondition) {
                                    $fastRemoteCondition->whereNull('fast_remote_stock_quantity')
                                        ->orWhere('fast_remote_stock_quantity', '=', '0')
                                        ->orWhere('fast_remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                });
                        });
                });
            }
        } elseif ($request->filled('fast_remote_stock_variations_exact') && $request->filled('fast_remote_stock_goods_exact')) {
            $exactValue = $request->get('fast_remote_stock_variations_exact');

            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('fast_remote_stock_variations_exact') !== '' || $request->get('fast_remote_stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций
                    $mainQuery->whereHas('variations', function ($varQ) use ($exactValue) {
                        $varQ->where('fast_remote_stock_quantity', '=', $exactValue);
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('fast_remote_stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Фильтр по основному остатку (stock_quantity) - новая логика для работы с вариациями
        if ($request->filled('stock_variations_not_empty') && $request->filled('stock_goods_not_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('stock_variations_not_empty') === '1' || $request->get('stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - проверяем остатки вариаций (хотя бы одна вариация с остатком > 0)
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('stock_quantity', '>', 0);
                    })
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '>', 0);
                        });
                });
            }
        } elseif ($request->filled('stock_variations_empty') && $request->filled('stock_goods_empty')) {
            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('stock_variations_empty') === '1' || $request->get('stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Вариант 1: Товары с вариациями - у всех вариаций должны быть пустые все виды остатков.
                    // Остатки основного товара в этом случае игнорируются.
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')
                                        ->where('fast_remote_stock_quantity', '!=', '')
                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                });
                        })
                    // Вариант 2: Товары без вариаций - у основного товара должны быть пустые все виды остатков.
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($stockQuery) {
                                    $stockQuery->where('stock_quantity', '=', 0)
                                        ->orWhereNull('stock_quantity');
                                })
                                ->where(function ($remoteQuery) {
                                    $remoteQuery->whereNull('remote_stock_quantity')
                                        ->orWhere('remote_stock_quantity', '=', '0')
                                        ->orWhere('remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                })
                                ->where(function ($fastRemoteQuery) {
                                    $fastRemoteQuery->whereNull('fast_remote_stock_quantity')
                                        ->orWhere('fast_remote_stock_quantity', '=', '0')
                                        ->orWhere('fast_remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                });
                        });
                });
            }
        } elseif ($request->filled('stock_variations_exact') && $request->filled('stock_goods_exact')) {
            $exactValue = $request->get('stock_variations_exact');

            // Проверяем, если хотя бы один из параметров включен
            if ($request->get('stock_variations_exact') !== '' || $request->get('stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Вариант 1: Товары с вариациями - проверяем сумму остатков вариаций
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = ?', [$exactValue])
                    // Вариант 2: Товары без вариаций - проверяем остатки основного товара
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Фильтр по общему остатку (total_stock) - исправленная логика
        // Фильтр "Общий остаток"
        if ($request->get('total_stock_variations_not_empty') === '1' && $request->get('total_stock_goods_not_empty') === '1') {
            $query->where(function ($q) {
                // Case 1: Products WITH variations: at least ONE variation must be in stock.
                $q->whereHas('variations', function ($varQ) {
                    $varQ->where('stock_quantity', '>', 0)
                        ->orWhere(function ($remote) {
                            $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                        })
                        ->orWhere(function ($fastRemote) {
                            $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                        });
                });
                // Case 2: Products WITHOUT variations: at least ONE of the three stock fields must be non-empty.
                $q->orWhere(function ($noVars) {
                    $noVars->whereDoesntHave('variations')
                        ->where(function ($stock) {
                            $stock->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                                });
                        });
                });
            });
        } elseif ($request->get('total_stock_variations_both_empty') === '1' && $request->get('total_stock_goods_both_empty') === '1') {

            $query->where(function ($q) {
                // Case 1: Products WITH variations: ALL variations must be out of stock.
                $q->where(function ($withVars) {
                    $withVars->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            // Find any variation that IS in stock
                            $varQ->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                                });
                        });
                })
                // Case 2: Products WITHOUT variations: ALL three stock fields must be empty.
                    ->orWhere(function ($noVars) {
                        $noVars->whereDoesntHave('variations')
                            ->where(function ($stock) {
                                $stock->where('stock_quantity', '=', 0)->orWhereNull('stock_quantity');
                            })
                            ->where(function ($remote) {
                                $remote->whereIn('remote_stock_quantity', ['0', ''])->orWhereNull('remote_stock_quantity');
                            })
                            ->where(function ($fastRemote) {
                                $fastRemote->whereIn('fast_remote_stock_quantity', ['0', ''])->orWhereNull('fast_remote_stock_quantity');
                            });
                    });
            });
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');

        $allowedSortFields = [
            'id', 'name', 'sku', 'price', 'rating', 'stock_quantity', 'remote_stock_quantity',
            'fast_remote_stock_quantity', 'created_at', 'updated_at', 'sort_order', 'supplier', 'slug',
            'categories', 'brands',
        ];

        // Специальная обработка для полей отношений - используем подзапросы для сортировки
        if (in_array($sortBy, ['categories', 'brands', 'label', 'tags'])) {
            switch ($sortBy) {
                case 'categories':
                    // Сортировка по первой категории товара (по алфавиту)
                    $query->leftJoin('shop_good_categories', 'shop_goods.id', '=', 'shop_good_categories.good_id')
                        ->leftJoin('shop_categories', 'shop_good_categories.category_id', '=', 'shop_categories.id')
                        ->orderByRaw('(SELECT MIN(sc.name) FROM shop_good_categories sgc INNER JOIN shop_categories sc ON sgc.category_id = sc.id WHERE sgc.good_id = shop_goods.id) '.$sortDirection)
                        ->select('shop_goods.*')
                        ->groupBy('shop_goods.id');
                    break;
                case 'brands':
                    // Сортировка по первой марке товара (по алфавиту)
                    $query->leftJoin('shop_good_brands', 'shop_goods.id', '=', 'shop_good_brands.good_id')
                        ->leftJoin('shop_brands', 'shop_good_brands.brand_id', '=', 'shop_brands.id')
                        ->orderByRaw('(SELECT MIN(sb.name) FROM shop_good_brands sgb INNER JOIN shop_brands sb ON sgb.brand_id = sb.id WHERE sgb.good_id = shop_goods.id) '.$sortDirection)
                        ->select('shop_goods.*')
                        ->groupBy('shop_goods.id');
                    break;
                case 'label':
                    $query->leftJoin('shop_labels', 'shop_goods.label_id', '=', 'shop_labels.id')
                        ->orderBy('shop_labels.name', $sortDirection)
                        ->select('shop_goods.*');
                    break;
                case 'tags':
                    // Сортировка по количеству тегов
                    $query->withCount('tags')->orderBy('tags_count', $sortDirection);
                    break;
            }
        } elseif (in_array($sortBy, $allowedSortFields)) {
            // Обычная сортировка для простых полей
            $query->orderBy($sortBy, $sortDirection);
        }

        // ids_only: вернуть только список ID (для массового выбора)
        $idsOnly = $request->has('ids_only') && $request->get('ids_only') === '1';
        if ($idsOnly) {
            $limit = (int) $request->get('limit', 1000000);
            if ($limit <= 0) {
                $limit = 1000000;
            }
            $limit = min($limit, 1000000);
            $ids = $query->select('shop_goods.id')->limit($limit)->pluck('shop_goods.id')->toArray();

            return response()->json([
                'success' => true,
                'ids' => $ids,
            ]);
        }

        // Проверяем, запрошена ли не пагинированная выборка для экспорта
        $forExport = $request->has('for_export') && $request->get('for_export') === '1';
        $countOnly = $request->has('count_only') && $request->get('count_only') === '1';

        if ($forExport && $countOnly) {
            // ТОЛЬКО ПОДСЧ§ЕТ СТЧ ОК - без загрузки данных
            $goodsIdsQuery = clone $query;
            $goodsIdsQuery->select('shop_goods.id')->distinct();

            $goodsWithoutVariations = DB::table('shop_goods')
                ->whereIn('shop_goods.id', clone $goodsIdsQuery)
                ->whereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('shop_good_variations.good_id', 'shop_goods.id');
                })
                ->count();

            $variationsQuery = ShopGoodVariation::query()->whereIn('good_id', clone $goodsIdsQuery);
            if ($this->hasExportVariationRowFilters($request)) {
                $this->applyExportVariationRowFilters($variationsQuery, $request);
            }
            $variationsCount = $variationsQuery->count();
            $totalRowsCount = $goodsWithoutVariations + $variationsCount;

            return response()->json([
                'success' => true,
                'count' => $totalRowsCount,
                'goods_count' => (int) $goodsWithoutVariations,
                'variations_count' => (int) $variationsCount,
            ]);
        }

        if ($forExport) {
            // Оптимизированный подсчет количества строк для экспорта
            // Чспользуем SQL для подсчета без загрузки всех данных
            $goodsIdsQuery = clone $query;
            $goodsIdsQuery->select('shop_goods.id')->distinct();

            $goodsWithoutVariations = DB::table('shop_goods')
                ->whereIn('shop_goods.id', clone $goodsIdsQuery)
                ->whereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('shop_good_variations.good_id', 'shop_goods.id');
                })
                ->count();

            $variationsQuery = ShopGoodVariation::query()->whereIn('good_id', clone $goodsIdsQuery);
            if ($this->hasExportVariationRowFilters($request)) {
                $this->applyExportVariationRowFilters($variationsQuery, $request);
            }
            $variationsCount = $variationsQuery->count();
            $totalRowsCount = $goodsWithoutVariations + $variationsCount;

            // Теперь загружаем товары для экспорта
            $goods = $query->get();
            if ($this->hasExportVariationRowFilters($request)) {
                $goods->load([
                    'variations' => function ($variationQuery) use ($request) {
                        $this->applyExportVariationRowFilters($variationQuery, $request);
                    },
                    'variations.images:id,variation_id,file_path,alt_text,is_main,sort_order',
                ]);
            }

            // Загружаем значения свойств для всех товаров (как в обычном режиме)
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Если свойство использует value напрямую
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $goods,
                'count' => $totalRowsCount,
            ]);
        }

        // Пагинация (если не запрашиваются конкретные ID, используем пагинацию)
        if (! $request->has('ids')) {
            // Определяем параметры пагинации
            $perPage = $request->get('per_page', 20);
            $perPage = in_array($perPage, [10, 20, 50, 100, 5000]) ? $perPage : 20;

            // Счетчик групп дублей, если активен duplicate_names
            $duplicateGroupsCount = null;
            if ($request->filled('duplicate_names')) {
                $idsSub = (clone $query)->select('id');
                $duplicateGroupsCount = DB::table('shop_goods')
                    ->whereIn('id', $idsSub)
                    ->whereNotNull('name')
                    ->whereRaw('LENGTH(TRIM(name)) > 0')
                    ->select(DB::raw('LOWER(TRIM(name)) as n'))
                    ->groupBy('n')
                    ->havingRaw('COUNT(*) > 1')
                    ->get()
                    ->count();
            }

            $goods = $query->paginate($perPage);

            // Получаем URL фронтенда для изображений
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

            // Проверяем, нужно ли загружать изображения вариаций
            $withVariationImages = $request->boolean('with_variation_images', false);
            if ($withVariationImages) {
                $goods->load('variations.images');
            }

            // Проверяем, нужно ли загружать атрибуты вариаций
            $withVariationAttributes = $request->boolean('with_variation_attributes', false);
            if ($withVariationAttributes) {
                // Загружаем атрибуты для всех вариаций
                foreach ($goods->items() as $good) {
                    if ($good->variations) {
                        foreach ($good->variations as $variation) {
                            $variation->attributes = DB::table('shop_variation_attributes_values as vav')
                                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                ->where('vav.variation_id', $variation->id)
                                ->select('a.id as attribute_id', 'a.name as attribute_name', 'av.id as value_id', 'av.value as value_value')
                                ->get()
                                ->map(function ($row) {
                                    return [
                                        'attribute' => [
                                            'id' => $row->attribute_id,
                                            'name' => $row->attribute_name,
                                        ],
                                        'value' => [
                                            'id' => $row->value_id,
                                            'value' => $row->value_value,
                                        ],
                                        'attribute_id' => $row->attribute_id,
                                        'attribute_name' => $row->attribute_name,
                                        'value_id' => $row->value_id,
                                        'value_value' => $row->value_value,
                                        'value' => $row->value_value,
                                    ];
                                })
                                ->toArray();
                        }
                    }
                }
            }

            // Загружаем значения свойств для всех товаров
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods->items() as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Если свойство использует value напрямую
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }

                // Обрабатываем изображения товара - добавляем полный URL фронтенда
                if ($good->images) {
                    foreach ($good->images as $image) {
                        if ($image->file_path) {
                            // Если путь уже полный URL, оставляем как есть
                            if (str_starts_with($image->file_path, 'http')) {
                                $image->url = $image->file_path;
                            } else {
                                // Нормализуем путь (заменяем обратные слеши на прямые)
                                $normalizedPath = str_replace('\\', '/', $image->file_path);
                                $cleanPath = ltrim($normalizedPath, '/');

                                // Убираем префикс cms/shop/ если он есть в пути
                                if (str_starts_with($cleanPath, 'cms/shop/')) {
                                    $cleanPath = substr($cleanPath, strlen('cms/shop/'));
                                }

                                // Формируем полный URL - просто добавляем базовый URL фронтенда к file_path
                                $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                            }
                        }
                    }
                }

                // Обрабатываем изображения вариаций - добавляем полный URL фронтенда
                if ($good->variations) {
                    foreach ($good->variations as $variation) {
                        // Загружаем изображения вариации, если они не загружены
                        if (! $variation->relationLoaded('images')) {
                            $variation->load('images');
                        }

                        if ($variation->images) {
                            foreach ($variation->images as $image) {
                                if ($image->file_path) {
                                    // Если путь уже полный URL, оставляем как есть
                                    if (str_starts_with($image->file_path, 'http')) {
                                        $image->url = $image->file_path;
                                    } else {
                                        // Нормализуем путь (заменяем обратные слеши на прямые)
                                        $normalizedPath = str_replace('\\', '/', $image->file_path);
                                        $cleanPath = ltrim($normalizedPath, '/');

                                        // Убираем префикс cms/shop/ если он есть в пути
                                        if (str_starts_with($cleanPath, 'cms/shop/')) {
                                            $cleanPath = substr($cleanPath, strlen('cms/shop/'));
                                        }

                                        // Формируем полный URL - просто добавляем базовый URL фронтенда к file_path
                                        $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                                    }
                                }
                            }
                        }
                    }
                }

                // Добавляем вычисления для вариаций
                if ($good->variations_count > 0 && $good->variations) {
                    // Сумма остатков вариаций
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');

                    // Проверка наличия непустых remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function ($variation) {
                        $remoteStock = $variation->remote_stock_quantity;

                        $hasRemote = $remoteStock !== null
                            && $remoteStock !== ''
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';

                        return $hasRemote;
                    })->count() > 0;

                    $good->variations_has_remote_stock = $hasRemoteStock;

                    // Проверка наличия непустых fast_remote_stock_quantity
                    $hasFastRemoteStock = $good->variations->filter(function ($variation) {
                        $fastRemoteStock = $variation->fast_remote_stock_quantity;

                        $hasFastRemote = $fastRemoteStock !== null
                            && $fastRemoteStock !== ''
                            && $fastRemoteStock !== '0'
                            && trim($fastRemoteStock) !== '';

                        return $hasFastRemote;
                    })->count() > 0;

                    $good->variations_has_fast_remote_stock = $hasFastRemoteStock;

                    // Проверка наличия активного демпинга
                    $hasDemping = $good->variations->filter(function ($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;

                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }

            // Подсчитываем вариации с выбранными поставщиками (если фильтр по поставщикам активен)
            $variationsCount = 0;
            if ($request->has('suppliers')) {
                $supplierIds = $request->input('suppliers');
                if (is_array($supplierIds) && ! empty($supplierIds)) {
                    $variationsCount = ShopGoodVariation::whereIn('supplier', $supplierIds)->count();
                }
            }

            $response = [
                'success' => true,
                'data' => $goods->items(),
                'pagination' => [
                    'current_page' => $goods->currentPage(),
                    'last_page' => $goods->lastPage(),
                    'per_page' => $goods->perPage(),
                    'total' => $goods->total(),
                    'from' => $goods->firstItem(),
                    'to' => $goods->lastItem(),
                ],
            ];

            // Добавляем количество вариаций, если фильтр по поставщикам активен
            if ($request->has('suppliers')) {
                $response['variations_count'] = $variationsCount;
            }
            // Добавляем счетчик групп дублей, если активен фильтр duplicate_names
            if ($duplicateGroupsCount !== null) {
                $response['duplicate_groups_count'] = $duplicateGroupsCount;
            }

            return response()->json($response);
        } else {
            // Если запрашиваются конкретные ID, возвращаем все без пагинации
            $goods = $query->get();

            // Получаем URL фронтенда для изображений
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

            // Проверяем, нужно ли загружать изображения вариаций
            $withVariationImages = $request->boolean('with_variation_images', false);
            if ($withVariationImages) {
                $goods->load('variations.images');
            }

            // Проверяем, нужно ли загружать атрибуты вариаций
            $withVariationAttributes = $request->boolean('with_variation_attributes', false);
            if ($withVariationAttributes) {
                // Загружаем атрибуты для всех вариаций
                foreach ($goods as $good) {
                    if ($good->variations) {
                        foreach ($good->variations as $variation) {
                            $variation->attributes = DB::table('shop_variation_attributes_values as vav')
                                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                ->where('vav.variation_id', $variation->id)
                                ->select('a.id as attribute_id', 'a.name as attribute_name', 'av.id as value_id', 'av.value as value_value')
                                ->get()
                                ->map(function ($row) {
                                    return [
                                        'attribute' => [
                                            'id' => $row->attribute_id,
                                            'name' => $row->attribute_name,
                                        ],
                                        'value' => [
                                            'id' => $row->value_id,
                                            'value' => $row->value_value,
                                        ],
                                        'attribute_id' => $row->attribute_id,
                                        'attribute_name' => $row->attribute_name,
                                        'value_id' => $row->value_id,
                                        'value_value' => $row->value_value,
                                        'value' => $row->value_value,
                                    ];
                                })
                                ->toArray();
                        }
                    }
                }
            }

            // Загружаем значения свойств для всех товаров
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Если свойство использует value напрямую
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }

                // Обрабатываем изображения товара - добавляем полный URL фронтенда
                if ($good->images) {
                    foreach ($good->images as $image) {
                        if ($image->file_path) {
                            // Если путь уже полный URL, оставляем как есть
                            if (str_starts_with($image->file_path, 'http')) {
                                $image->url = $image->file_path;
                            } else {
                                // Нормализуем путь (заменяем обратные слеши на прямые)
                                $normalizedPath = str_replace('\\', '/', $image->file_path);
                                $cleanPath = ltrim($normalizedPath, '/');

                                // Формируем полный URL - просто добавляем базовый URL фронтенда к file_path
                                $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                            }
                        }
                    }
                }

                // Обрабатываем изображения вариаций - добавляем полный URL фронтенда
                if ($good->variations) {
                    foreach ($good->variations as $variation) {
                        // Загружаем изображения вариации, если они не загружены
                        if (! $variation->relationLoaded('images')) {
                            $variation->load('images');
                        }

                        if ($variation->images) {
                            foreach ($variation->images as $image) {
                                if ($image->file_path) {
                                    // Если путь уже полный URL, оставляем как есть
                                    if (str_starts_with($image->file_path, 'http')) {
                                        $image->url = $image->file_path;
                                    } else {
                                        // Нормализуем путь (заменяем обратные слеши на прямые)
                                        $normalizedPath = str_replace('\\', '/', $image->file_path);
                                        $cleanPath = ltrim($normalizedPath, '/');

                                        // Формируем полный URL - просто добавляем базовый URL фронтенда к file_path
                                        $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                                    }
                                }
                            }
                        }
                    }
                }

                // Добавляем вычисления для вариаций
                if ($good->variations_count > 0 && $good->variations) {
                    // Сумма остатков вариаций
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');

                    // Проверка наличия непустых remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function ($variation) {
                        $remoteStock = $variation->remote_stock_quantity;

                        $hasRemote = $remoteStock !== null
                            && $remoteStock !== ''
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';

                        return $hasRemote;
                    })->count() > 0;

                    $good->variations_has_remote_stock = $hasRemoteStock;

                    // Проверка наличия непустых fast_remote_stock_quantity
                    $hasFastRemoteStock = $good->variations->filter(function ($variation) {
                        $fastRemoteStock = $variation->fast_remote_stock_quantity;

                        $hasFastRemote = $fastRemoteStock !== null
                            && $fastRemoteStock !== ''
                            && $fastRemoteStock !== '0'
                            && trim($fastRemoteStock) !== '';

                        return $hasFastRemote;
                    })->count() > 0;

                    $good->variations_has_fast_remote_stock = $hasFastRemoteStock;

                    // Проверка наличия активного демпинга
                    $hasDemping = $good->variations->filter(function ($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;

                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_fast_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }

            // Формируем ответ
            $response = [
                'success' => true,
                'data' => $goods->toArray(),
            ];

            // Добавляем счетчик групп дублей, если активен фильтр duplicate_names
            if ($request->filled('duplicate_names')) {
                $names = collect($goods)->map(function ($g) {
                    return is_string($g->name ?? null) ? mb_strtolower(trim($g->name)) : '';
                })->filter(function ($n) {
                    return $n !== '';
                });
                $duplicateGroupsCount = $names->countBy()->filter(function ($c) {
                    return $c > 1;
                })->count();
                $response['duplicate_groups_count'] = $duplicateGroupsCount;
            }

            return response()->json($response);
        }
    }

    /**
     * Клонировать товар
     */
    public function clone(Request $request, $id): JsonResponse
    {
        $originalGood = ShopGood::with(['categories', 'brands', 'tags', 'properties', 'variations.attributeValues'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:255|unique:shop_goods,sku',
            'clone_variations' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Создаем клон основного товара
            $newGood = $originalGood->replicate(['slug']);
            $newGood->name = $request->input('name');
            $newGood->sku = $request->input('sku');
            $newGood->slug = Str::slug($newGood->name);
            
            // Если такой slug уже есть, добавляем суффикс
            $slugCount = ShopGood::where('slug', 'like', $newGood->slug . '%')->count();
            if ($slugCount > 0) {
                $newGood->slug .= '-' . ($slugCount + 1);
            }
            
            $newGood->save();

            // Клонируем связи
            $newGood->categories()->attach($originalGood->categories->pluck('id'));
            $newGood->brands()->attach($originalGood->brands->pluck('id'));
            $newGood->tags()->attach($originalGood->tags->pluck('id'));

            // Клонируем свойства через pivot
            foreach ($originalGood->properties as $property) {
                $newGood->properties()->attach($property->id, [
                    'shop_property_value_id' => $property->pivot->shop_property_value_id,
                ]);
            }

            // Клонируем вариации, если запрошено
            if ($request->boolean('clone_variations')) {
                foreach ($originalGood->variations as $variation) {
                    $newVariation = $variation->replicate(['good_id']);
                    $newVariation->good_id = $newGood->id;
                    $newVariation->sku = $variation->sku . '-copy-' . $newGood->id; // Генерируем временный SKU для вариации
                    $newVariation->save();

                    // Клонируем атрибуты вариации
                    if ($variation->attributeValues) {
                        $newVariation->attributeValues()->attach($variation->attributeValues->pluck('id'));
                    }
                }
            }

            // Аудит
            $this->logAudit($newGood, 'cloned', $originalGood->id, $newGood->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно склонирован',
                'data' => [
                    'id' => $newGood->id,
                    'name' => $newGood->name,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка клонирования товара: ' . $e->getMessage(),
            ], 500);
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
            'variations:id,good_id,supplier,name,description,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity,sku,is_active',
            'stock:id,good_id,warehouse_id,quantity,reserved_quantity,min_quantity',
            'stock.warehouse:id,name',
            'prices:id,good_id,price_type_id,price,sale_price',
            'prices.priceType:id,name,multiplier',
        ])->findOrFail($id);

        // Загружаем дополнительные данные для свойств товара
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $good->load(['properties' => function ($query) use ($hasValueCol) {
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
            'data' => $good,
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
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
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
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $good = ShopGood::create($request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
                'stock_quantity', 'remote_stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'sort_order',
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
                    if (! empty($property['shop_property_value_id'])) {
                        $propertyValueId = $property['shop_property_value_id'];
                    }
                    // Если есть value, ищем или создаем запись в shop_property_values
                    elseif (! empty($property['value'])) {
                        $propertyValue = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $property['property_id'],
                            'value' => trim($property['value']),
                        ], [
                            'is_active' => true,
                            'sort_order' => 0,
                        ]);
                        $propertyValueId = $propertyValue->id;
                    }

                    // Привязываем свойство только если есть propertyValueId
                    if ($propertyValueId) {
                        $good->properties()->attach($property['property_id'], [
                            'shop_property_value_id' => $propertyValueId,
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
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания товара: '.$e->getMessage(),
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
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
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
            'is_show' => 'boolean',
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
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
                'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'is_show', 'sort_order',
            ]);

            // Явно обрабатываем remote_stock_quantity - всегда обновляем, даже если null
            // Чспользуем прямой доступ к полю из JSON тела запроса
            $allRequestData = $request->all();
            if (isset($allRequestData['remote_stock_quantity'])) {
                $remoteStockValue = $allRequestData['remote_stock_quantity'];
                $updateData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
            }

            // Явно обрабатываем fast_remote_stock_quantity - всегда обновляем, даже если null
            if (isset($allRequestData['fast_remote_stock_quantity'])) {
                $fastRemoteStockValue = $allRequestData['fast_remote_stock_quantity'];
                $updateData['fast_remote_stock_quantity'] = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
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

                    // Ч ежим через справочник значений
                    if ($hasShopValueIdCol) {
                        $propertyValueId = null;
                        if (! empty($property['shop_property_value_id'])) {
                            // Если есть shop_property_value_id и новое значение, обновляем существующее значение
                            $existingValueId = (int) $property['shop_property_value_id'];
                            $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);

                            if (! empty($property['value']) && $existingValue) {
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
                        } elseif (! empty($property['value'])) {
                            $valueToSave = trim($property['value']);
                            // Убираем двоеточие в начале и конце значения перед сохранением
                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                            $valueToSave = trim($valueToSave);

                            $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                                'property_id' => $propertyId,
                                'value' => $valueToSave,
                            ], [
                                'is_active' => true,
                                'sort_order' => 0,
                            ]);
                            $propertyValueId = (int) $pv->id;
                        }

                        if ($propertyValueId) {
                            DB::table('shop_good_properties')->insert([
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'shop_property_value_id' => $propertyValueId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $lastSyncData[] = ['property_id' => $propertyId, 'shop_property_value_id' => $propertyValueId];
                        }
                    }
                    // Ч ежим хранения прямого текста значения
                    elseif ($hasValueCol) {
                        $textValue = null;
                        if (! empty($property['value'])) {
                            $textValue = trim($property['value']);
                        } elseif (! empty($property['shop_property_value_id'])) {
                            $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                            $textValue = $found ? $found->value : null;
                        }

                        if ($textValue !== null && $textValue !== '') {
                            DB::table('shop_good_properties')->insert([
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'value' => $textValue,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $lastSyncData[] = ['property_id' => $propertyId, 'value' => $textValue];
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
            $good->load(['properties' => function ($q) {
                $q->withPivot('shop_property_value_id');
            }]);

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно обновлен',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
                'debug' => [
                    'attached_count' => isset($lastSyncData) ? count($lastSyncData) : 0,
                    'attached' => $lastSyncData,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления товара: '.$e->getMessage(),
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
            'properties' => 'present|array',
        ];

        // Если массив не пустой, добавляем правила для элементов
        if (! empty($properties)) {
            $rules['properties.*.property_id'] = 'required|exists:shop_properties,id';
            $rules['properties.*.value'] = 'nullable|string|max:255';
            $rules['properties.*.shop_property_value_id'] = 'nullable|exists:shop_property_values,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
                    if (! empty($property['shop_property_value_id'])) {
                        // Если есть shop_property_value_id и новое значение, обновляем существующее значение
                        $existingValueId = (int) $property['shop_property_value_id'];
                        $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);

                        if (! empty($property['value']) && $existingValue) {
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
                    } elseif (! empty($property['value'])) {
                        $valueToSave = trim($property['value']);
                        // Убираем двоеточие в начале и конце значения перед сохранением
                        $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                        $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                        $valueToSave = trim($valueToSave);

                        $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $propertyId,
                            'value' => $valueToSave,
                        ], [
                            'is_active' => true,
                            'sort_order' => 0,
                        ]);
                        $propertyValueId = (int) $pv->id;
                    }
                    if ($propertyValueId) {
                        DB::table('shop_good_properties')->insert([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'shop_property_value_id' => $propertyValueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $lastSyncData[] = ['property_id' => $propertyId, 'shop_property_value_id' => $propertyValueId];
                    }
                } elseif ($hasValueCol) {
                    $textValue = null;
                    if (! empty($property['value'])) {
                        $textValue = trim($property['value']);
                        // Убираем двоеточие в начале и конце значения
                        $textValue = preg_replace('/^:\s*/', '', $textValue);
                        $textValue = preg_replace('/\s*:\s*$/', '', $textValue);
                        $textValue = trim($textValue);
                    } elseif (! empty($property['shop_property_value_id'])) {
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
                        DB::table('shop_good_properties')->insert([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'value' => $textValue,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $lastSyncData[] = ['property_id' => $propertyId, 'value' => $textValue];
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойства обновлены',
                'data' => [
                    'attached' => $lastSyncData,
                    'count' => count($lastSyncData),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления свойств: '.$e->getMessage(),
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
                'message' => 'Товар успешно удален',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить ID товаров для серверного выбора "все с учетом фильтра".
     */
    private function resolveGoodsIdsFromSelectionFilters(array $payload, Request $originalRequest): array
    {
        $filters = $payload['filters'] ?? [];
        if (! is_array($filters)) {
            return [];
        }

        unset($filters['selected_ids'], $filters['ids'], $filters['ids_only'], $filters['limit'], $filters['page'], $filters['per_page']);
        $filters = $this->normalizeSelectionFilters($filters);

        $filters['ids_only'] = '1';
        $filters['limit'] = '1000000';

        $filterRequest = Request::create('/api/admin/shop/goods', 'GET', $filters);
        $filterRequest->headers->replace($originalRequest->headers->all());
        $filterRequest->setUserResolver($originalRequest->getUserResolver());
        $filterRequest->setRouteResolver($originalRequest->getRouteResolver());

        $response = $this->index($filterRequest);
        $responseData = json_decode($response->getContent(), true);
        $ids = $responseData['ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    }

    private function normalizeSelectionFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            $values = is_array($value) ? array_values($value) : [$value];

            if (preg_match('/^([^\[]+)\[([^\]]+)\]\[\]$/', (string) $key, $matches)) {
                $root = $matches[1];
                $index = $matches[2];
                if (! isset($normalized[$root]) || ! is_array($normalized[$root])) {
                    $normalized[$root] = [];
                }
                $normalized[$root][$index] = array_values(array_merge($normalized[$root][$index] ?? [], $values));
                continue;
            }

            if (substr((string) $key, -2) === '[]') {
                $baseKey = substr((string) $key, 0, -2);
                $normalized[$baseKey] = array_values(array_merge($normalized[$baseKey] ?? [], $values));
                continue;
            }

            $normalized[$key] = is_array($value) ? $values : $value;
        }

        return $normalized;
    }

    private function hasExportVariationRowFilters(Request $request): bool
    {
        $keys = [
            'min_price',
            'max_price',
            'stock_variations_not_empty',
            'stock_variations_empty',
            'stock_variations_exact',
            'stock_variations_low',
            'remote_stock_variations_not_empty',
            'remote_stock_variations_empty',
            'remote_stock_variations_exact',
            'fast_remote_stock_variations_not_empty',
            'fast_remote_stock_variations_empty',
            'fast_remote_stock_variations_exact',
            'total_stock_variations_not_empty',
            'total_stock_variations_both_empty',
            'variation_attribute_names',
            'exclude_variation_attribute_names',
        ];

        foreach ($keys as $key) {
            if ($request->filled($key) || $request->has($key)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCatalogFilterAliases(Request $request): void
    {
        $merge = [];

        if ($request->filled('in_stock') && ! $request->filled('stock_variations_not_empty') && ! $request->filled('stock_goods_not_empty') && ! $request->filled('stock_variations_empty') && ! $request->filled('stock_goods_empty')) {
            $inStock = (string) $request->get('in_stock');
            if ($inStock === 'true' || $inStock === '1') {
                $merge['stock_variations_not_empty'] = '1';
                $merge['stock_goods_not_empty'] = '1';
            } elseif ($inStock === 'false' || $inStock === '0') {
                $merge['stock_variations_empty'] = '1';
                $merge['stock_goods_empty'] = '1';
            } elseif ($inStock === 'low') {
                $merge['stock_variations_low'] = '1';
                $merge['stock_goods_low'] = '1';
            } elseif (str_starts_with($inStock, 'exact:')) {
                $exactStock = (string) ((int) substr($inStock, 6));
                $merge['stock_variations_exact'] = $exactStock;
                $merge['stock_goods_exact'] = $exactStock;
            }
        }

        if ($request->filled('remote_stock_quantity_not_empty')) {
            $merge['remote_stock_variations_not_empty'] = '1';
            $merge['remote_stock_goods_not_empty'] = '1';
        } elseif ($request->filled('remote_stock_quantity_empty')) {
            $merge['remote_stock_variations_empty'] = '1';
            $merge['remote_stock_goods_empty'] = '1';
        } elseif ($request->filled('remote_stock_quantity')) {
            $merge['remote_stock_variations_exact'] = $request->get('remote_stock_quantity');
            $merge['remote_stock_goods_exact'] = $request->get('remote_stock_quantity');
        }

        if ($request->filled('fast_remote_stock_quantity_not_empty')) {
            $merge['fast_remote_stock_variations_not_empty'] = '1';
            $merge['fast_remote_stock_goods_not_empty'] = '1';
        } elseif ($request->filled('fast_remote_stock_quantity_empty')) {
            $merge['fast_remote_stock_variations_empty'] = '1';
            $merge['fast_remote_stock_goods_empty'] = '1';
        } elseif ($request->filled('fast_remote_stock_quantity')) {
            $merge['fast_remote_stock_variations_exact'] = $request->get('fast_remote_stock_quantity');
            $merge['fast_remote_stock_goods_exact'] = $request->get('fast_remote_stock_quantity');
        }

        if ($request->filled('total_stock_not_empty')) {
            $merge['total_stock_variations_not_empty'] = '1';
            $merge['total_stock_goods_not_empty'] = '1';
        }

        if (! empty($merge)) {
            $request->merge($merge);
        }
    }

    private function applyExportVariationRowFilters($query, Request $request): void
    {
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->get('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->get('max_price'));
        }

        if ($request->get('stock_variations_not_empty') === '1') {
            $query->where('stock_quantity', '>', 0);
        } elseif ($request->get('stock_variations_empty') === '1') {
            $query->where(function ($stockQuery) {
                $stockQuery->whereNull('stock_quantity')->orWhere('stock_quantity', '<=', 0);
            });
        } elseif ($request->filled('stock_variations_exact')) {
            $query->where('stock_quantity', '=', (int) $request->get('stock_variations_exact'));
        } elseif ($request->get('stock_variations_low') === '1') {
            $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<', 3);
        }

        if ($request->get('remote_stock_variations_not_empty') === '1') {
            $query->whereNotNull('remote_stock_quantity')
                ->where('remote_stock_quantity', '!=', '')
                ->where('remote_stock_quantity', '!=', '0')
                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
        } elseif ($request->get('remote_stock_variations_empty') === '1') {
            $query->where(function ($remoteQuery) {
                $remoteQuery->whereNull('remote_stock_quantity')
                    ->orWhere('remote_stock_quantity', '=', '')
                    ->orWhere('remote_stock_quantity', '=', '0')
                    ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
            });
        } elseif ($request->filled('remote_stock_variations_exact')) {
            $query->where('remote_stock_quantity', '=', $request->get('remote_stock_variations_exact'));
        }

        if ($request->get('fast_remote_stock_variations_not_empty') === '1') {
            $query->whereNotNull('fast_remote_stock_quantity')
                ->where('fast_remote_stock_quantity', '!=', '')
                ->where('fast_remote_stock_quantity', '!=', '0')
                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
        } elseif ($request->get('fast_remote_stock_variations_empty') === '1') {
            $query->where(function ($fastRemoteQuery) {
                $fastRemoteQuery->whereNull('fast_remote_stock_quantity')
                    ->orWhere('fast_remote_stock_quantity', '=', '')
                    ->orWhere('fast_remote_stock_quantity', '=', '0')
                    ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
            });
        } elseif ($request->filled('fast_remote_stock_variations_exact')) {
            $query->where('fast_remote_stock_quantity', '=', $request->get('fast_remote_stock_variations_exact'));
        }

        if ($request->get('total_stock_variations_not_empty') === '1') {
            $query->where(function ($totalQuery) {
                $totalQuery->where('stock_quantity', '>', 0)
                    ->orWhere(function ($remote) {
                        $remote->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    })
                    ->orWhere(function ($fastRemote) {
                        $fastRemote->whereNotNull('fast_remote_stock_quantity')
                            ->where('fast_remote_stock_quantity', '!=', '')
                            ->where('fast_remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                    });
            });
        } elseif ($request->get('total_stock_variations_both_empty') === '1') {
            $query->where(function ($totalQuery) {
                $totalQuery->where(function ($stock) {
                    $stock->whereNull('stock_quantity')->orWhere('stock_quantity', '<=', 0);
                })
                    ->where(function ($remote) {
                        $remote->whereNull('remote_stock_quantity')
                            ->orWhere('remote_stock_quantity', '=', '')
                            ->orWhere('remote_stock_quantity', '=', '0')
                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                    })
                    ->where(function ($fastRemote) {
                        $fastRemote->whereNull('fast_remote_stock_quantity')
                            ->orWhere('fast_remote_stock_quantity', '=', '')
                            ->orWhere('fast_remote_stock_quantity', '=', '0')
                            ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                    });
            });
        }

        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            foreach (array_filter($attributeNames) as $name) {
                $query->whereExists(function ($attrSubQuery) use ($name) {
                    $attrSubQuery->selectRaw('1')
                        ->from('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('vav.variation_id', 'shop_good_variations.id')
                        ->where('a.name', $name);
                });
            }
        }

        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            $excludeAttributeNames = array_filter($excludeAttributeNames);
            if (! empty($excludeAttributeNames)) {
                $query->whereNotExists(function ($attrSubQuery) use ($excludeAttributeNames) {
                    $attrSubQuery->selectRaw('1')
                        ->from('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('vav.variation_id', 'shop_good_variations.id')
                        ->whereIn('a.name', $excludeAttributeNames);
                });
            }
        }
    }

    /**
     * Массовое обновление товаров
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        // Получаем сырые JSON данные до обработки middleware
        $rawJsonData = json_decode($request->getContent(), true) ?: [];

        if (($rawJsonData['selection_mode'] ?? null) === 'filters') {
            $rawJsonData['ids'] = $this->resolveGoodsIdsFromSelectionFilters($rawJsonData, $request);
            $request->merge(['ids' => $rawJsonData['ids']]);
        }

        $action = $rawJsonData['action'] ?? $request->get('action');

        // Для действий clear_by_tags и clear_by_suppliers ids не обязателен, так как используются фильтры в data
        $idsRules = in_array($action, ['clear_by_tags', 'clear_by_suppliers'])
            ? 'nullable|array'
            : 'required|array';

        $idsItemRule = (($rawJsonData['selection_mode'] ?? null) === 'filters') ? 'integer' : 'exists:shop_goods,id';

        $validator = Validator::make($rawJsonData, [
            'ids' => $idsRules,
            'ids.*' => $idsItemRule,
            'action' => 'required|in:activate,deactivate,delete,delete_without_supplier,delete_without_images,update_categories,update_brands,update_tags,update_properties,update_stock,update_remote_stock,update_fast_remote_stock,update_price,update_sale_price,update_demping_price,toggle_show_demping,toggle_fields,update_label,remove_after_symbol,replace_text,update_dimensions,enable_preorder,disable_preorder,clear_by_tags,clear_by_suppliers,delete_images,delete_non_main_images,delete_zero_stock_no_media',
            'data' => 'nullable|array',
            'data.field' => 'nullable|in:name,description,short_description',
            'data.mode' => 'nullable|in:exact,start_end',
            'data.delete_type' => 'nullable|in:goods,variations,goods_and_variations',
            'data.variation_ids' => 'nullable|array',
            'data.variation_ids.*' => 'exists:shop_good_variations,id',
            'data.delete_good_if_all_variations_removed' => 'nullable|boolean',
            'data.dry_run' => 'nullable|boolean',
            'data.show_demping' => 'nullable|boolean',
            'data.is_sale' => 'nullable|boolean',
            'data.is_new' => 'nullable|boolean',
            'data.is_featured' => 'nullable|boolean',
            'data.is_show' => 'nullable|boolean',
            'data.search' => 'nullable|string',
            'data.replace' => 'nullable|string',
            'data.start' => 'nullable|string',
            'data.end' => 'nullable|string',
            'data.normalize_spaces' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ids = $request->get('ids');
            $action = $request->get('action');
            // Получаем данные напрямую из JSON, без обработки middleware
            $jsonData = json_decode($request->getContent(), true);
            $data = $jsonData['data'] ?? [];

            // Для массового удаления по меткам/поставщикам делаем прямые запросы
            if (in_array($action, ['clear_by_tags', 'clear_by_suppliers'])) {
                $query = ShopGood::query();

                // Если пришли фильтры в data, используем их для поиска товаров
                if (! empty($data)) {
                    // Фильтр по категориям
                    if (isset($data['categories']) && is_array($data['categories']) && ! empty($data['categories'])) {
                        $query->whereHas('categories', function ($q) use ($data) {
                            $q->whereIn('shop_categories.id', $data['categories']);
                        });
                    }

                    // Фильтр по брендам
                    if (isset($data['brands']) && is_array($data['brands']) && ! empty($data['brands'])) {
                        $query->whereHas('brands', function ($q) use ($data) {
                            $q->whereIn('shop_brands.id', $data['brands']);
                        });
                    }

                    // Фильтр по лейблам
                    if (isset($data['labels']) && is_array($data['labels']) && ! empty($data['labels'])) {
                        $query->whereIn('label_id', $data['labels']);
                    }

                    // Фильтр по тегам
                    if (isset($data['tags']) && is_array($data['tags']) && ! empty($data['tags'])) {
                        $query->whereHas('tags', function ($q) use ($data) {
                            $q->whereIn('shop_tags.id', $data['tags']);
                        });
                    }

                    // Фильтр по поставщикам
                    if (isset($data['suppliers']) && is_array($data['suppliers']) && ! empty($data['suppliers'])) {
                        $includeVariations = isset($data['suppliers_include_variations']) && $data['suppliers_include_variations'];

                        if ($includeVariations) {
                            // Включаем товары с поставщиками Ч товары с вариациями с поставщиками
                            $query->where(function ($q) use ($data) {
                                // Товары с выбранными поставщиками
                                $q->whereIn('supplier', $data['suppliers'])
                                  // Чли товары, у которых есть вариации с выбранными поставщиками
                                    ->orWhereHas('variations', function ($varQ) use ($data) {
                                        $varQ->whereIn('supplier', $data['suppliers']);
                                    });
                            });
                        } else {
                            // Только товары с поставщиками (без учета вариаций)
                            $query->whereIn('supplier', $data['suppliers']);
                        }
                    }
                } else {
                    // Если фильтров нет, используем переданные ID
                    if (! empty($ids)) {
                        $query->whereIn('id', $ids);
                    } else {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Не указаны ID товаров или фильтры для удаления',
                        ], 400);
                    }
                }

                // Получаем ID товаров для удаления
                $goodIds = $query->pluck('id')->toArray();

                // Для поставщиков также получаем ID вариаций с выбранными поставщиками
                $variationIdsToDelete = [];
                if ($action === 'clear_by_suppliers' && isset($data['suppliers']) && is_array($data['suppliers']) && ! empty($data['suppliers'])) {
                    // Получаем вариации с выбранными поставщиками (даже если основной товар не имеет этого поставщика)
                    $variationIdsToDelete = ShopGoodVariation::whereIn('supplier', $data['suppliers'])
                        ->pluck('id')
                        ->toArray();
                }

                if (empty($goodIds) && empty($variationIdsToDelete)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Не найдено товаров или вариаций для удаления',
                    ], 404);
                }

                // Удаляем вариации с выбранными поставщиками (если есть)
                $variationsDeletedBySupplier = 0;
                if (! empty($variationIdsToDelete)) {
                    // Удаляем изображения этих вариаций
                    ShopGoodImage::whereIn('variation_id', $variationIdsToDelete)->delete();
                    // Удаляем вариации
                    $variationsDeletedBySupplier = ShopGoodVariation::whereIn('id', $variationIdsToDelete)->delete();
                }

                // Удаляем вариации товаров, которые будут удалены
                $variationsDeletedByGood = 0;
                if (! empty($goodIds)) {
                    $variationsDeletedByGood = ShopGoodVariation::whereIn('good_id', $goodIds)->delete();
                }

                // Удаляем изображения товаров и вариаций
                if (! empty($goodIds)) {
                    ShopGoodImage::whereIn('good_id', $goodIds)->delete();
                }

                // Удаляем товары
                $deletedCount = 0;
                if (! empty($goodIds)) {
                    $deletedCount = ShopGood::whereIn('id', $goodIds)->delete();
                }

                $totalVariationsDeleted = $variationsDeletedBySupplier + $variationsDeletedByGood;

                DB::commit();

                $message = '';
                if ($deletedCount > 0) {
                    $message .= "Удалено {$deletedCount} товаров";
                }
                if ($totalVariationsDeleted > 0) {
                    if ($message) {
                        $message .= ' и ';
                    }
                    $message .= "{$totalVariationsDeleted} вариаций";
                }
                if (! $message) {
                    $message = 'Нечего удалять';
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'variations_deleted' => $totalVariationsDeleted,
                    'variations_deleted_by_supplier' => $variationsDeletedBySupplier,
                    'variations_deleted_by_good' => $variationsDeletedByGood,
                ]);
            }

            if ($action === 'delete_non_main_images') {
                $variationIds = ShopGoodVariation::whereIn('good_id', $ids)->pluck('id')->toArray();

                $imagesQuery = ShopGoodImage::query()
                    ->where(function ($query) use ($ids, $variationIds) {
                        $query->where(function ($goodsQ) use ($ids) {
                            $goodsQ->whereIn('good_id', $ids)
                                ->whereNull('variation_id');
                        });

                        if (! empty($variationIds)) {
                            $query->orWhereIn('variation_id', $variationIds);
                        }
                    })
                    ->where(function ($query) {
                        $query->where('is_main', false)
                            ->orWhereNull('is_main');
                    });

                $imagesToDelete = $imagesQuery->get(['id', 'good_id', 'variation_id']);
                $deletedImagesCount = $imagesToDelete->count();
                $deletedGoodImagesCount = $imagesToDelete->whereNull('variation_id')->count();
                $deletedVariationImagesCount = $deletedImagesCount - $deletedGoodImagesCount;

                if (! empty($data['dry_run'])) {
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => "Найдено изображений без отметки главное: {$deletedImagesCount}",
                        'dry_run' => true,
                        'images_count' => $deletedImagesCount,
                        'good_images_count' => $deletedGoodImagesCount,
                        'variation_images_count' => $deletedVariationImagesCount,
                    ]);
                }

                if ($deletedImagesCount > 0) {
                    ShopGoodImage::whereIn('id', $imagesToDelete->pluck('id')->all())->delete();
                }

                foreach (ShopGood::whereIn('id', $ids)->get() as $good) {
                    $this->logAudit($good, 'bulk_delete_non_main_images', null, [
                        'deleted_images_count' => $deletedImagesCount,
                    ]);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Удалено изображений без отметки главное: {$deletedImagesCount}",
                    'deleted_images_count' => $deletedImagesCount,
                    'deleted_good_images_count' => $deletedGoodImagesCount,
                    'deleted_variation_images_count' => $deletedVariationImagesCount,
                ]);
            }

            // Специальная обработка для удаления выбранных вариаций с нулевым остатком
            // с предварительным перемещением изображений в другие вариации товара
            if ($action === 'delete_zero_stock_no_media') {
                $variationIds = $data['variation_ids'] ?? [];

                if (empty($variationIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Не указаны ID вариаций для удаления',
                    ], 400);
                }

                // Получаем вариации для обработки
                $variationsToDelete = ShopGoodVariation::whereIn('id', $variationIds)
                    ->whereIn('good_id', $ids) // Дополнительная проверка, что вариации принадлежат выбранным товарам
                    ->where('stock_quantity', 0)
                    ->where(function ($query) {
                        $query->whereNull('remote_stock_quantity')
                            ->orWhere('remote_stock_quantity', 0);
                    })
                    ->where(function ($query) {
                        $query->whereNull('fast_remote_stock_quantity')
                            ->orWhere('fast_remote_stock_quantity', 0);
                    })
                    ->with(['images', 'good']) // Загружаем изображения и товар
                    ->get();

                $deletedCount = 0;
                $movedImagesCount = 0;

                foreach ($variationsToDelete as $variation) {
                    // Если у вариации есть изображения, пытаемся их перенести
                    if ($variation->images->count() > 0) {
                        // Находим другие вариации того же товара (любые, кроме тех что будут удалены)
                        // Приоритет: активные вариации с положительным остатком, затем активные вариации, затем любые другие
                        $targetVariation = ShopGoodVariation::where('good_id', $variation->good_id)
                            ->where('id', '!=', $variation->id)
                            ->whereNotIn('id', $variationIds) // Чсключаем все вариации, которые будут удалены в этом запросе
                            ->orderByRaw('
                                CASE
                                    WHEN is_active = 1 AND (stock_quantity > 0 OR remote_stock_quantity > 0 OR fast_remote_stock_quantity > 0) THEN 1
                                    WHEN is_active = 1 THEN 2
                                    ELSE 3
                                END,
                                sort_order ASC
                            ')
                            ->first();

                        // Если нашли целевую вариацию, перемещаем изображения
                        if ($targetVariation) {
                            foreach ($variation->images as $image) {
                                $image->variation_id = $targetVariation->id;
                                $image->save();
                                $movedImagesCount++;
                            }
                        } else {
                            // Если нет других вариаций, отвязываем изображения от вариации (привязываем к товару)
                            foreach ($variation->images as $image) {
                                $image->variation_id = null;
                                $image->save();
                                $movedImagesCount++;
                            }
                        }
                    }
                    // Вариации без изображений просто удаляются без дополнительных действий

                    // Удаляем вариацию
                    $variation->delete();
                    $deletedCount++;
                }

                DB::commit();

                $message = "Удалено {$deletedCount} вариаций с нулевым остатком";
                if ($movedImagesCount > 0) {
                    $message .= ". Перемещено {$movedImagesCount} изображений";
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'moved_images_count' => $movedImagesCount,
                    'variation_ids' => $variationIds,
                ]);
            }

            if ($action === 'delete_without_images') {
                $deleteGoodIfAllVariationsRemoved = (bool) ($data['delete_good_if_all_variations_removed'] ?? true);
                $goodsForProcessing = ShopGood::whereIn('id', $ids)
                    ->with(['images', 'variations.images'])
                    ->get();

                $deletedCount = 0;
                $goodsWithoutVariationsCount = 0;
                $deletedVariationsCount = 0;
                $deletedGoodsAfterVariationsRemovedCount = 0;

                foreach ($goodsForProcessing as $good) {
                    $oldValues = $good->toArray();
                    $variations = $good->variations;

                    if ($variations->isEmpty()) {
                        if ($good->images->count() > 0) {
                            continue;
                        }

                        $goodsWithoutVariationsCount++;
                        $this->logAudit($good, 'bulk_deleted_without_images', $oldValues, null);
                        $good->delete();
                        $deletedCount++;
                        continue;
                    }

                    $variationsWithoutImages = $variations->filter(function ($variation) {
                        return $variation->images->count() === 0;
                    });

                    if ($variationsWithoutImages->isEmpty()) {
                        continue;
                    }

                    foreach ($variationsWithoutImages as $variation) {
                        $variation->delete();
                        $deletedVariationsCount++;
                    }

                    if ($deleteGoodIfAllVariationsRemoved && $variationsWithoutImages->count() === $variations->count()) {
                        $this->logAudit($good, 'bulk_deleted_without_images', $oldValues, null);
                        $good->delete();
                        $deletedGoodsAfterVariationsRemovedCount++;
                        $deletedCount++;
                    }
                }

                DB::commit();

                $message = "Удалено товаров без изображений: {$deletedCount}";
                $message .= ". Товаров без вариаций: {$goodsWithoutVariationsCount}";
                $message .= ". Удалено вариаций без изображений: {$deletedVariationsCount}";
                $message .= ". Удалено товаров после очистки всех вариаций: {$deletedGoodsAfterVariationsRemovedCount}";

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'deleted_goods_without_variations_count' => $goodsWithoutVariationsCount,
                    'deleted_variations_count' => $deletedVariationsCount,
                    'deleted_goods_after_variations_removed_count' => $deletedGoodsAfterVariationsRemovedCount,
                ]);
            }

            $goods = ShopGood::whereIn('id', $ids)->get();
            $deletedCount = 0; // Счетчик удаленных товаров для delete_without_supplier
            $deletedVariationsCount = 0; // Счетчик удаленных вариаций для delete_without_supplier

            foreach ($goods as $good) {
                if (! $good) {
                    continue; // Пропускаем null товары
                }
                $oldValues = $good->toArray();

                // Для удаления сначала создаем запись аудита, потом удаляем товар
                if ($action === 'delete') {
                    $this->logAudit($good, 'bulk_deleted', $oldValues, null);
                    $good->delete();

                    continue;
                }

                // Для удаления вариаций без поставщика (товары не удаляем, даже если у товара не останется вариаций)
                if ($action === 'delete_without_supplier') {
                    // Удаляем только вариации без поставщика
                    // ВАЖНО: Основной товар НЕ удаляется, даже если у него не останется вариаций
                    $variations = $good->variations()->get();
                    foreach ($variations as $variation) {
                        $variationSupplier = $variation->supplier;
                        if (empty($variationSupplier) || $variationSupplier === null || trim($variationSupplier) === '') {
                            $variation->delete();
                            $deletedVariationsCount++;
                        }
                    }

                    // Явно убеждаемся, что товар не удаляется - просто продолжаем цикл
                    // Товар остается в базе данных, даже если у него не осталось вариаций
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
                                'value' => $existingProp->value ?? null,
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

                                    $currentProperties = array_filter($currentProperties, function ($prop) use ($removePropertyId, $removeShopPropertyValueId, $removeValue, $hasShopValueIdCol, $hasValueCol) {
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

                            // Теперь удаляем свойства из базы данных, которых нет в отфильтрованном массиве
                            if (isset($data['properties_to_remove']) && is_array($data['properties_to_remove'])) {
                                foreach ($data['properties_to_remove'] as $propertyToRemove) {
                                    $removePropertyId = (int) $propertyToRemove['property_id'];
                                    $removeShopPropertyValueId = isset($propertyToRemove['shop_property_value_id']) ? (int) $propertyToRemove['shop_property_value_id'] : null;
                                    $removeValue = isset($propertyToRemove['value']) ? trim($propertyToRemove['value']) : null;

                                    // Проверяем, есть ли это свойство в отфильтрованном массиве
                                    $stillExists = false;
                                    foreach ($currentProperties as $existing) {
                                        if ($existing['property_id'] == $removePropertyId) {
                                            if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                                $existingShopPropertyValueId = isset($existing['shop_property_value_id']) ? (int) $existing['shop_property_value_id'] : null;
                                                if ($existingShopPropertyValueId == $removeShopPropertyValueId) {
                                                    $stillExists = true;
                                                    break;
                                                }
                                            } elseif ($hasValueCol && $removeValue !== null) {
                                                $existingValue = trim($existing['value'] ?? '');
                                                if ($existingValue === $removeValue) {
                                                    $stillExists = true;
                                                    break;
                                                }
                                            } else {
                                                // Если нет значения, проверяем только property_id
                                                $stillExists = true;
                                                break;
                                            }
                                        }
                                    }

                                    // Если свойство не найдено в отфильтрованном массиве, удаляем его из базы
                                    if (! $stillExists) {
                                        $deleteQuery = DB::table('shop_good_properties')
                                            ->where('good_id', $good->id)
                                            ->where('property_id', $removePropertyId);

                                        if ($hasVariationIdCol) {
                                            $deleteQuery->whereNull('variation_id');
                                        }

                                        if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                            $deleteQuery->where('shop_property_value_id', $removeShopPropertyValueId);
                                        } elseif ($hasValueCol && $removeValue !== null) {
                                            $deleteQuery->where('value', $removeValue);
                                        }

                                        $deleteQuery->delete();
                                    }
                                }
                            }
                        }

                        // Добавляем новые свойства (если не установлен флаг очистки всех)
                        if (! isset($data['clear_all']) || ! $data['clear_all']) {
                            if (isset($data['properties']) && is_array($data['properties'])) {
                                $incoming = $data['properties'];

                                // Добавляем только новые свойства, которые еще не существуют у товара
                                foreach ($incoming as $property) {
                                    if (empty($property['property_id'])) {
                                        continue;
                                    }

                                    $propertyId = (int) $property['property_id'];
                                    $newShopPropertyValueId = isset($property['shop_property_value_id']) ? (int) $property['shop_property_value_id'] : null;
                                    $newValue = $property['value'] ?? null;

                                    // Проверяем, нет ли уже такого свойства с таким значением
                                    $exists = false;
                                    foreach ($currentProperties as $existing) {
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
                                    if (! $exists) {
                                        // Добавляем свойство напрямую в базу данных
                                        $insertData = [
                                            'good_id' => $good->id,
                                            'property_id' => $propertyId,
                                        ];

                                        if ($hasShopValueIdCol && $newShopPropertyValueId !== null) {
                                            $insertData['shop_property_value_id'] = $newShopPropertyValueId;
                                        }

                                        if ($hasValueCol && $newValue !== null) {
                                            $insertData['value'] = $newValue;
                                        }

                                        if ($hasVariationIdCol) {
                                            $insertData['variation_id'] = null;
                                        }

                                        DB::table('shop_good_properties')->insert($insertData);

                                        // Добавляем в текущие свойства для будущих проверок
                                        $currentProperties[] = $property;
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

                            // Обновляем остаток основного товара
                            if ($stockAction === 'set') {
                                $good->update(['stock_quantity' => $stockValue]);
                            } elseif ($stockAction === 'add') {
                                $good->update(['stock_quantity' => max(0, $currentStock + $stockValue)]);
                            } elseif ($stockAction === 'subtract') {
                                $good->update(['stock_quantity' => max(0, $currentStock - $stockValue)]);
                            }

                            // Также обновляем остатки всех вариаций товара
                            $variations = $good->variations()->get();
                            foreach ($variations as $variation) {
                                $variationCurrentStock = (int) $variation->stock_quantity;

                                if ($stockAction === 'set') {
                                    $variation->update(['stock_quantity' => $stockValue]);
                                } elseif ($stockAction === 'add') {
                                    $variation->update(['stock_quantity' => max(0, $variationCurrentStock + $stockValue)]);
                                } elseif ($stockAction === 'subtract') {
                                    $variation->update(['stock_quantity' => max(0, $variationCurrentStock - $stockValue)]);
                                }
                            }
                        }
                        break;
                    case 'update_remote_stock':
                        if (isset($data['remote_stock_quantity'])) {
                            $remoteStockValue = $data['remote_stock_quantity'];
                            $remoteStockValueFormatted = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;

                            // Обновляем остаток у/с основного товара
                            $good->update([
                                'remote_stock_quantity' => $remoteStockValueFormatted,
                            ]);

                            // Также обновляем остатки у/с всех вариаций товара
                            $good->variations()->update([
                                'remote_stock_quantity' => $remoteStockValueFormatted,
                            ]);
                        }
                        break;
                    case 'update_fast_remote_stock':
                        if (isset($data['fast_remote_stock_quantity'])) {
                            $fastRemoteStockValue = $data['fast_remote_stock_quantity'];
                            $fastRemoteStockValueFormatted = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;

                            // Обновляем быстрый остаток у/с основного товара
                            $good->update([
                                'fast_remote_stock_quantity' => $fastRemoteStockValueFormatted,
                            ]);

                            // Также обновляем быстрые остатки у/с всех вариаций товара
                            $good->variations()->update([
                                'fast_remote_stock_quantity' => $fastRemoteStockValueFormatted,
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

                        // Обработка поля show_demping
                        if (isset($data['show_demping'])) {
                            $good->update(['show_demping' => (bool) $data['show_demping']]);
                        }
                        break;
                    case 'toggle_show_demping':
                        if (isset($data['show_demping'])) {
                            $good->update(['show_demping' => (bool) $data['show_demping']]);
                        }
                        break;
                    case 'toggle_fields':
                        $updateData = [];
                        if (isset($data['show_demping'])) {
                            $updateData['show_demping'] = (bool) $data['show_demping'];
                        }
                        if (isset($data['is_sale'])) {
                            $updateData['is_sale'] = (bool) $data['is_sale'];
                        }
                        if (isset($data['is_new'])) {
                            $updateData['is_new'] = (bool) $data['is_new'];
                        }
                        if (isset($data['is_featured'])) {
                            $updateData['is_featured'] = (bool) $data['is_featured'];
                        }
                        if (isset($data['is_show'])) {
                            $updateData['is_show'] = (bool) $data['is_show'];
                        }
                        if (! empty($updateData)) {
                            $good->update($updateData);
                        }
                        break;
                    case 'update_label':
                        if (array_key_exists('label_id', $data)) {
                            $good->update(['label_id' => $data['label_id'] ?: null]);
                        }
                        break;
                    case 'remove_after_symbol':
                        if (isset($data['symbol']) && ! empty($data['symbol'])) {
                            $symbol = $data['symbol'];
                            $name = $good->name;

                            // Находим первое вхождение символа/сочетания
                            $position = mb_strpos($name, $symbol);

                            if ($position !== false) {
                                // Удаляем символ и вс‘ после него
                                $newName = mb_substr($name, 0, $position);
                                $good->update(['name' => $newName]);
                            }
                        }
                        break;
                    case 'replace_text':
                        $search = $data['search'] ?? '';
                        $replace = $data['replace'] ?? '';
                        $normalizeSpaces = $data['normalize_spaces'] ?? false;
                        $field = $data['field'] ?? 'name';
                        $mode = $data['mode'] ?? 'exact';
                        $start = $data['start'] ?? '';
                        $end = $data['end'] ?? '';

                        if (! in_array($field, ['name', 'description', 'short_description'])) {
                            $field = 'name';
                        }

                        if ($field === 'name') {
                            $text = $good->name ?? '';
                            $newText = $text;
                            if ($mode === 'start_end' && $start !== '' && $end !== '') {
                                $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/si';
                                $newText = preg_replace($pattern, $replace, $text);
                            } else {
                                if ($search !== '' || $normalizeSpaces) {
                                    if ($normalizeSpaces) {
                                        $newText = preg_replace('/\s+/', ' ', trim($text));
                                    } else {
                                        $isSearchOnlySpaces = trim($search) === '';
                                        if ($isSearchOnlySpaces && $search !== '') {
                                            if ($replace === '') {
                                                $newText = str_replace($search, '', $text);
                                            } else {
                                                $replaceSpaces = str_repeat(' ', strlen($replace));
                                                $newText = str_replace($search, $replaceSpaces, $text);
                                            }
                                        } else {
                                            $newText = str_ireplace($search, $replace, $text);
                                        }
                                    }
                                }
                            }
                            if ($newText !== $text) {
                                $good->update(['name' => $newText]);
                            }
                        } else {
                            $text = $good->{$field} ?? '';
                            $newText = $text;
                            if ($mode === 'start_end' && $start !== '' && $end !== '') {
                                $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/si';
                                $newText = preg_replace($pattern, $replace, $text);
                            } elseif ($search !== '') {
                                $newText = str_ireplace($search, $replace, $text);
                            }
                            if ($newText !== $text) {
                                $good->update([$field => $newText]);
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
                        if (! empty($updateData)) {
                            $good->update($updateData);
                        }
                        break;
                    case 'delete_images':
                        if (isset($data['delete_type'])) {
                            $deleteType = $data['delete_type'];

                            if ($deleteType === 'goods') {
                                // Удаляем только изображения товаров (не вариаций)
                                ShopGoodImage::where('good_id', $good->id)
                                    ->whereNull('variation_id')
                                    ->delete();
                            } elseif ($deleteType === 'variations') {
                                // Удаляем изображения вариаций этого товара
                                $variationIds = $good->variations()->pluck('id')->toArray();
                                if (! empty($variationIds)) {
                                    ShopGoodImage::whereIn('variation_id', $variationIds)->delete();
                                }
                            } elseif ($deleteType === 'goods_and_variations') {
                                // Удаляем все изображения товара и его вариаций
                                ShopGoodImage::where('good_id', $good->id)->delete();

                                $variationIds = $good->variations()->pluck('id')->toArray();
                                if (! empty($variationIds)) {
                                    ShopGoodImage::whereIn('variation_id', $variationIds)->delete();
                                }
                            }
                        }
                        break;
                }

                // Аудит для всех действий кроме delete (для delete уже создан выше)
                $this->logAudit($good, 'bulk_'.$action, $oldValues, $good->fresh()->toArray());
            }

            DB::commit();

            // Формируем сообщение в зависимости от действия
            $message = 'Массовое обновление выполнено успешно';
            $responseData = ['success' => true, 'message' => $message, 'updated_count' => count($ids)];

            if ($action === 'delete_without_supplier') {
                $message = "Удалено вариаций без поставщика: {$deletedVariationsCount}";
                $responseData['message'] = $message;
                $responseData['deleted_count'] = 0; // Товары не удаляются
                $responseData['deleted_variations_count'] = $deletedVariationsCount;
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового обновления: '.$e->getMessage(),
            ], 500);
        }
    }

    public function findGoodsWithoutImages(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|array',
            'good_ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $goodIds = $request->get('good_ids', []);

            $goodsForProcessing = ShopGood::query()
                ->whereIn('id', $goodIds)
                ->with(['images', 'variations.images'])
                ->get();

            $goodsCount = 0;
            $goodsWithoutVariationsCount = 0;
            $goodsWithAllVariationsWithoutImagesCount = 0;
            $variationsCount = 0;

            foreach ($goodsForProcessing as $good) {
                $variations = $good->variations;

                if ($variations->isEmpty()) {
                    if ($good->images->count() === 0) {
                        $goodsCount++;
                        $goodsWithoutVariationsCount++;
                    }
                    continue;
                }

                $variationsWithoutImages = $variations->filter(function ($variation) {
                    return $variation->images->count() === 0;
                });

                if ($variationsWithoutImages->isEmpty()) {
                    continue;
                }

                $goodsCount++;
                $variationsCount += $variationsWithoutImages->count();

                if ($variationsWithoutImages->count() === $variations->count()) {
                    $goodsWithAllVariationsWithoutImagesCount++;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'goods_count' => $goodsCount,
                    'goods_without_variations_count' => $goodsWithoutVariationsCount,
                    'goods_with_all_variations_without_images_count' => $goodsWithAllVariationsWithoutImagesCount,
                    'variations_count' => $variationsCount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка поиска товаров без изображений: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Применить фильтры к запросу товаров
     */
    private function applyGoodsFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->get('search');
            // Если передан параметр search_only_name_sku, ищем только по названию и артикулу
            $searchOnlyNameSku = $request->input('search_only_name_sku');
            if ($searchOnlyNameSku && ($searchOnlyNameSku === '1' || $searchOnlyNameSku === 1 || $searchOnlyNameSku === true || $searchOnlyNameSku === 'true')) {
                $query->searchNameSku($search);
            } else {
                $query->search($search);
            }
        }

        // Фильтр по названию (точное вхождение текста)
        if ($request->filled('name_search')) {
            $nameSearch = $request->get('name_search');
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($nameSearch).'%']);
        }

        // Фильтр по артикулу (точное вхождение текста)
        if ($request->filled('sku_search')) {
            $skuSearch = $request->get('sku_search');
            $query->whereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower($skuSearch).'%']);
        }

        // Фильтр по категории
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Фильтр по множественным категориям
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
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
            if (is_array($brandIds) && ! empty($brandIds)) {
                $query->whereHas('brands', function ($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Фильтр по тегам
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && ! empty($tagIds)) {
                $query->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Чсключение тегов
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && ! empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function ($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Фильтр по лейблам
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && ! empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Фильтр по свойствам
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties)) {
                foreach ($properties as $propertyId => $valueIds) {
                    if (is_array($valueIds) && ! empty($valueIds)) {
                        $query->whereHas('properties', function ($q) use ($propertyId, $valueIds) {
                            $q->where('shop_properties.id', $propertyId)
                                ->whereIn('shop_property_value_id', $valueIds);
                        });
                    }
                }
            }
        }

        // Фильтр по демпингу
        if ($request->filled('has_demping')) {
            $hasDemping = $request->boolean('has_demping');
            if ($hasDemping) {
                $query->where('show_demping', true);
            } else {
                $query->where('show_demping', false);
            }
        }

        // Фильтр по демпинговой цене
        if ($request->filled('has_demping_price')) {
            $hasDempingPrice = $request->get('has_demping_price');
            if ($hasDempingPrice === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('demping_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('shop_good_variations.demping_price', '>', 'shop_good_variations.price');
                    });
                });
            } elseif ($request->boolean('has_demping_price')) {
                $query->whereNotNull('demping_price');
            } else {
                $query->whereNull('demping_price');
            }
        }

        // Фильтр по акционной цене
        if ($request->filled('has_sale_price')) {
            $hasSalePrice = $request->get('has_sale_price');
            if ($hasSalePrice === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('sale_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('shop_good_variations.sale_price', '>', 'shop_good_variations.price');
                    });
                });
            } elseif ($request->boolean('has_sale_price')) {
                $query->whereNotNull('sale_price');
            } else {
                $query->whereNull('sale_price');
            }
        }

        // Фильтр по лейблу
        if ($request->filled('has_label')) {
            $hasLabel = $request->boolean('has_label');
            if ($hasLabel) {
                $query->whereNotNull('label_id');
            } else {
                $query->whereNull('label_id');
            }
        }

        // Фильтр по тегам
        if ($request->filled('has_tags')) {
            $hasTags = $request->boolean('has_tags');
            if ($hasTags) {
                $query->whereHas('tags');
            } else {
                $query->whereDoesntHave('tags');
            }
        }

        // Фильтр по количеству характеристик
        if ($request->filled('properties_count_type')) {
            $countType = $request->get('properties_count_type');
            if ($countType === 'exact' && $request->filled('properties_count')) {
                $count = (int) $request->get('properties_count');
                $query->whereHas('properties', function ($q) use ($count) {
                    $q->havingRaw('COUNT(*) = ?', [$count]);
                }, '=', $count);
            }
        }

        // Фильтр по артикулу
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            }
        }

        // Фильтр по цене
        if ($request->filled('min_price') || $request->filled('max_price')) {
            if ($request->filled('min_price')) {
                $query->where('price', '>=', $request->get('min_price'));
            }
            if ($request->filled('max_price')) {
                $query->where('price', '<=', $request->get('max_price'));
            }
        }

        // Фильтр по остатку
        if ($request->filled('stock_quantity_min') || $request->filled('stock_quantity_max')) {
            if ($request->filled('stock_quantity_min')) {
                $query->where('stock_quantity', '>=', $request->get('stock_quantity_min'));
            }
            if ($request->filled('stock_quantity_max')) {
                $query->where('stock_quantity', '<=', $request->get('stock_quantity_max'));
            }
        }

        // Фильтр по удаленному остатку
        if ($request->filled('remote_stock_quantity')) {
            $query->where('remote_stock_quantity', $request->get('remote_stock_quantity'));
        }

        // Фильтр по быстрому удаленному остатку
        if ($request->filled('fast_remote_stock_quantity')) {
            $query->where('fast_remote_stock_quantity', $request->get('fast_remote_stock_quantity'));
        }

        // Фильтр по статусу активности
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Фильтр по статусу "Ч екомендуемый"
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Фильтр по статусу "Новый"
        if ($request->has('is_new')) {
            $query->where('is_new', $request->boolean('is_new'));
        }

        // Фильтр по статусу "Со скидкой"
        if ($request->has('is_sale')) {
            $query->where('is_sale', $request->boolean('is_sale'));
        }

        // Фильтр по статусу "Доступен к заказу"
        if ($request->has('is_preorder')) {
            $query->where('is_preorder', $request->boolean('is_preorder'));
        }

        // Фильтр по статусу "Показывать"
        if ($request->has('is_show')) {
            $query->where('is_show', $request->boolean('is_show'));
        }

        // Фильтр по наличию вариаций
        if ($request->filled('has_variations')) {
            $hasVariations = $request->boolean('has_variations');
            if ($hasVariations) {
                $query->has('variations');
            } else {
                $query->doesntHave('variations');
            }
        }

        // Фильтр по наличию категорий
        if ($request->filled('has_categories')) {
            $hasCategories = $request->boolean('has_categories');
            if ($hasCategories) {
                $query->has('categories');
            } else {
                $query->doesntHave('categories');
            }
        }

        // Фильтр по наличию брендов
        if ($request->filled('has_brands')) {
            $hasBrands = $request->boolean('has_brands');
            if ($hasBrands) {
                $query->has('brands');
            } else {
                $query->doesntHave('brands');
            }
        }

        // Фильтр по наличию на складе
        if ($request->filled('in_stock')) {
            $inStock = $request->boolean('in_stock');
            if ($inStock) {
                $query->where('stock_quantity', '>', 0);
            } else {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        // Фильтр по атрибутам вариаций (товары С этими атрибутами)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            if (! empty($attributeNames)) {
                // Фильтруем товары, у которых есть ХОТЯ БЫ ОДНА вариация со ВСЕМИ указанными атрибутами
                $query->whereExists(function ($subQuery) use ($attributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->where(function ($q) use ($attributeNames) {
                            foreach ($attributeNames as $name) {
                                $q->whereExists(function ($attrSubQuery) use ($name) {
                                    $attrSubQuery->selectRaw('1')
                                        ->from('shop_variation_attributes_values as vav')
                                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                        ->whereColumn('vav.variation_id', 'v.id')
                                        ->where('a.name', $name);
                                });
                            }
                        });
                });
            }
        }

        // Чсключение атрибутов вариаций (товары БЕЗ этих атрибутов)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты
                // Чспользуем подзапрос для точного контроля
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->whereIn('a.name', $excludeAttributeNames)
                        ->limit(1);
                });
            }
        }
    }

    public function getInvalidPricesStats(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->buildInvalidPricesStats(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки цен: '.$e->getMessage(),
            ], 500);
        }
    }

    public function cleanupInvalidPrices(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        $allowedTypes = [
            'goods_sale_price',
            'goods_demping_price',
            'variations_sale_price',
            'variations_demping_price',
            'all',
        ];

        if (! in_array($type, $allowedTypes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректный тип очистки',
            ], 422);
        }

        try {
            $cleaned = 0;

            DB::transaction(function () use ($type, &$cleaned) {
                if ($type === 'goods_sale_price' || $type === 'all') {
                    $cleaned += $this->invalidGoodsSalePriceQuery()->update(['sale_price' => 0]);
                }

                if ($type === 'goods_demping_price' || $type === 'all') {
                    $cleaned += $this->invalidGoodsDempingPriceQuery()->update([
                        'demping_price' => 0,
                        'show_demping' => false,
                    ]);
                }

                if ($type === 'variations_sale_price' || $type === 'all') {
                    $cleaned += $this->invalidVariationSalePriceQuery()->update(['sale_price' => 0]);
                }

                if ($type === 'variations_demping_price' || $type === 'all') {
                    $cleaned += $this->invalidVariationDempingPriceQuery()->update([
                        'demping_price' => 0,
                        'show_demping' => false,
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Некорректные цены обнулены',
                'data' => [
                    'cleaned' => $cleaned,
                    'stats' => $this->buildInvalidPricesStats(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обнуления цен: '.$e->getMessage(),
            ], 500);
        }
    }

    private function buildInvalidPricesStats(): array
    {
        return [
            'goods' => [
                'sale_price' => $this->invalidGoodsSalePriceQuery()->count(),
                'demping_price' => $this->invalidGoodsDempingPriceQuery()->count(),
            ],
            'variations' => [
                'sale_price' => $this->invalidVariationSalePriceQuery()->count(),
                'demping_price' => $this->invalidVariationDempingPriceQuery()->count(),
            ],
        ];
    }

    private function invalidGoodsSalePriceQuery()
    {
        return ShopGood::query()
            ->whereNotNull('price')
            ->whereNotNull('sale_price')
            ->where('sale_price', '>', 0)
            ->whereColumn('sale_price', '>', 'price');
    }

    private function invalidGoodsDempingPriceQuery()
    {
        return ShopGood::query()
            ->whereNotNull('price')
            ->whereNotNull('demping_price')
            ->where('demping_price', '>', 0)
            ->whereColumn('demping_price', '>', 'price');
    }

    private function invalidVariationSalePriceQuery()
    {
        return ShopGoodVariation::query()
            ->whereNotNull('price')
            ->whereNotNull('sale_price')
            ->where('sale_price', '>', 0)
            ->whereColumn('sale_price', '>', 'price');
    }

    private function invalidVariationDempingPriceQuery()
    {
        return ShopGoodVariation::query()
            ->whereNotNull('price')
            ->whereNotNull('demping_price')
            ->where('demping_price', '>', 0)
            ->whereColumn('demping_price', '>', 'price');
    }

    /**
     * Получить данные для фильтров
     */
    public function filters(): JsonResponse
    {
        $categories = ShopCategory::ordered()->with(['children' => function ($query) {
            $query->orderBy('sort_order', 'asc')
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
                'properties' => $properties,
            ],
        ]);
    }

    /**
     * Создать новую категорию
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $category = ShopCategory::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopCategory::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $category,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания категории: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый бренд
     */
    public function createBrand(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $brand = ShopBrand::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopBrand::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Бренд успешно создан',
                'data' => $brand,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания бренда: '.$e->getMessage(),
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
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $label = ShopLabel::create([
                'name' => $request->get('name'),
                'color' => $request->get('color', '#3B82F6'),
                'sort_order' => ShopLabel::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Лейбл успешно создан',
                'data' => $label,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания лейбла: '.$e->getMessage(),
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
            'height' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $imageUrl = $request->input('imageUrl');
            $storagePath = $request->input("storagePath", "/images/shop/goods"); // Исправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');

            \Log::debug('ShopGoodsController@downloadImage: Request parameters', [
                'imageUrl' => $imageUrl,
                'naming' => $naming,
                'storagePath' => $storagePath,
            ]);
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');


            // Валидация URL
            if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный формат URL',
                ], 400);
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            if (! in_array($extension, $imageExtensions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неподдерживаемый формат изображения',
                ], 400);
            }

            // Генерация имени файла
            if ($naming === 'original') {
                // Используем оригинальное имя файла
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName.'.'.$extension;

                // Очищаем имя файла от недопустимых символов
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                // Используем хеш
                $hash = hash('sha256', $imageUrl);
                $fileName = $hash.'.'.$extension;
            }

            // Полный путь для сохранения
            $fullPath = $storagePath.'/'.$fileName;
            // Получаем путь к фронтенду из FRONTEND_PATH в .env
            $frontendPublicPath = frontend_public_path();
            $storageFullPath = $frontendPublicPath.'/'.ltrim($fullPath, '/');

            // Проверяем, существует ли файл уже
            // Если режим "Оригинал" - проверяем наличие файла. Для "Хеш" - качаем ВСЕГДА (перезаписываем)
            if ($naming === 'original' && file_exists($storageFullPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'path' => $fullPath,
                        'originalUrl' => $imageUrl,
                        'skipped' => true,
                    ],
                ]);
            }

            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (! \App\Helpers\StorageHelper::createDirectory($directory)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать директорию для изображения',
                ], 500);
            }

            // Скачиваем изображение с помощью cURL для обхода SSL проблем

            $downloadResult = $this->downloadImageWithCurl($imageUrl);

            if (! $downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось скачать изображение: '.($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}"),
                ], 400);
            }

            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл слишком большой (максимум 30MB)',
                ], 400);
            }

            // Сохраняем файл
            $saveResult = file_put_contents($storageFullPath, $imageData);
            if ($saveResult === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить файл',
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
                    'optimized' => $optimize,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания изображения: '.$e->getMessage(),
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
            'height' => 'nullable|integer|min:1',
            'concurrency' => 'nullable|integer|min:1|max:20',
            'timeout' => 'nullable|integer|min:5|max:120',
            'connect_timeout' => 'nullable|integer|min:1|max:60',
            'queue_post_process' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $imageUrls = $request->input('imageUrls');
            // Очищаем все URL от невалидных UTF-8 символов
            if (is_array($imageUrls)) {
                $imageUrls = array_map(function ($url) {
                    $url = mb_convert_encoding($url, 'UTF-8', 'UTF-8');

                    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $url);
                }, $imageUrls);
            }

            $storagePath = $request->input('storagePath', '/images/shop/goods'); // Чсправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');
            $concurrency = (int) ($request->input('concurrency', 8));
            if ($concurrency < 1) {
                $concurrency = 1;
            }
            if ($concurrency > 20) {
                $concurrency = 20;
            }
            $timeout = (int) ($request->input('timeout', 30));
            $connectTimeout = (int) ($request->input('connect_timeout', 10));

            $results = [];
            $errors = [];
            $skipped = [];

            // Предварительная подготовка: вычисляем имена файлов и пропускаем существующие, затем скачиваем параллельно
            $frontendPublicPath = frontend_public_path();
            $cacheKey = 'imgdl_cache:'.md5($storagePath);
            $queue = [];
            foreach ($imageUrls as $index => $imageUrlRaw) {
                $imageUrl = mb_convert_encoding($imageUrlRaw, 'UTF-8', 'UTF-8');
                $imageUrl = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $imageUrl);
                if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $errors[] = ['url' => $imageUrl, 'error' => 'Неверный формат URL'];

                    continue;
                }

                $cachedRelativePath = null;
                try {
                    // В режиме хеш игнорируем кэш Redis, чтобы форсировать обновление если нужно
                    if ($naming !== 'hash') {
                        $cached = Redis::hGet($cacheKey, $imageUrl);
                        if (is_string($cached) && $cached !== '') {
                            $cachedRelativePath = $cached;
                            $cachedAbsolutePath = $frontendPublicPath.'/'.ltrim($cachedRelativePath, '/');
                            if (file_exists($cachedAbsolutePath)) {
                                $results[$imageUrl] = $cachedRelativePath;
                                $skipped[] = $imageUrl;
                                continue;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Чгнорируем ошибки Redis, продолжаем обычный поток
                }
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                if (! $extension) {
                    $extension = 'jpg';
                }
                if ($naming === 'original') {
                    $originalName = pathinfo($urlPath, PATHINFO_FILENAME);
                    $originalName = mb_convert_encoding($originalName, 'UTF-8', 'UTF-8');
                    $originalName = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
                    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName).'.'.$extension;
                } else {
                    $hash = hash('sha256', $imageUrl.$index);
                    $fileName = $hash.'.'.$extension;
                }
                $relativePath = rtrim($storagePath, '/').'/'.$fileName;
                $absolutePath = $frontendPublicPath.'/'.ltrim($relativePath, '/');
                $normalizedAbsolutePath = realpath($absolutePath) ?: $absolutePath;
                if ($naming === 'original' && file_exists($normalizedAbsolutePath)) {
                    $results[$imageUrl] = $relativePath;
                    $skipped[] = $imageUrl;

                    continue;
                }
                $dir = dirname($normalizedAbsolutePath);
                if (! \App\Helpers\StorageHelper::createDirectory($dir)) {
                    $errors[] = ['url' => $imageUrl, 'error' => 'Не удалось создать директорию для изображения'];

                    continue;
                }
                $queue[] = [
                    'url' => $imageUrl,
                    'relativePath' => $relativePath,
                    'absolutePath' => $normalizedAbsolutePath,
                    'index' => $index,
                    'extension' => $extension,
                ];
            }

            // Если нечего скачивать
            if (empty($queue)) {
                $cleanResults = [];
                foreach ($results as $url => $path) {
                    $cleanResults[mb_convert_encoding($url, 'UTF-8', 'UTF-8')] = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
                }
                $cleanSkipped = array_map(fn ($u) => mb_convert_encoding($u, 'UTF-8', 'UTF-8'), $skipped);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'paths' => $cleanResults,
                        'skipped' => $cleanSkipped,
                        'errors' => $errors,
                        'total' => count($imageUrls),
                        'successful' => count($cleanResults),
                        'failed' => count($errors),
                        'skipped_count' => count($cleanSkipped),
                    ],
                ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Перемешиваем очередь, чтобы распределить запросы по различным хостам более равномерно
            if (count($queue) > 1) {
                shuffle($queue);
            }

            // Параллельная загрузка с помощью curl_multi
            $mh = curl_multi_init();
            // Общий share-объект для DNS/SSL/соединений
            $sh = function_exists('curl_share_init') ? curl_share_init() : null;
            if ($sh && function_exists('curl_share_setopt')) {
                if (defined('CURLSHOPT_SHARE') && defined('CURL_LOCK_DATA_DNS')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
                }
                if (defined('CURL_LOCK_DATA_SSL_SESSION')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
                }
                if (defined('CURL_LOCK_DATA_CONNECT')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
                }
            }
            $handles = [];
            $active = null;
            $startedAt = microtime(true);

            $createHandle = function ($item) use ($timeout, $connectTimeout, $sh) {
                $ch = curl_init();
                $normalizedUrl = $this->normalizeImageUrl($item['url']);
                curl_setopt($ch, CURLOPT_URL, $normalizedUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                if (defined('CURL_SSLVERSION_TLSv1_2')) {
                    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                }
                curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
                if (defined('CURLSSLOPT_ALLOW_BEAST') && defined('CURLSSLOPT_NO_REVOKE')) {
                    curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);
                }
                if (defined('CURL_HTTP_VERSION_2TLS')) {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
                } else {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                }
                curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
                curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
                curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
                if (defined('CURLOPT_LOW_SPEED_LIMIT') && defined('CURLOPT_LOW_SPEED_TIME')) {
                    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
                    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 15);
                }
                if (defined('CURLOPT_NOSIGNAL')) {
                    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                }
                if (defined('CURLOPT_DNS_CACHE_TIMEOUT')) {
                    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
                }
                if (defined('CURL_IPRESOLVE_V4')) {
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate',
                    'Connection: keep-alive',
                    'Cache-Control: no-cache',
                ]);
                if ($sh && function_exists('curl_setopt')) {
                    if (defined('CURLOPT_SHARE')) {
                        curl_setopt($ch, CURLOPT_SHARE, $sh);
                    }
                }
                // Сохраняем метаданные для последующей обработки
                curl_setopt($ch, CURLOPT_PRIVATE, json_encode($item));

                return $ch;
            };

            $nextIndex = 0;
            $totalToDownload = count($queue);

            // Чнициализируем первые потоки
            for (; $nextIndex < $totalToDownload && count($handles) < $concurrency; $nextIndex++) {
                $item = $queue[$nextIndex];
                $ch = $createHandle($item);
                $handles[(int) $ch] = $ch;
                curl_multi_add_handle($mh, $ch);
            }

            if (function_exists('curl_multi_setopt')) {
                if (defined('CURLMOPT_MAXCONNECTS')) {
                    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, max($concurrency * 2, 10));
                }
                if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                    // Ограничиваем соединения на хост, чтобы не В«душитьВ» один источник
                    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, min($concurrency, 6));
                }
                if (defined('CURLMOPT_PIPELINING')) {
                    // Включаем HTTP/2 мультиплексирование, если поддерживается
                    $pipemode = defined('CURLPIPE_MULTIPLEX') ? CURLPIPE_MULTIPLEX : 1;
                    curl_multi_setopt($mh, CURLMOPT_PIPELINING, $pipemode);
                }
            }

            do {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);

                // Обрабатываем завершенные
                while ($info = curl_multi_info_read($mh)) {
                    $ch = $info['handle'];
                    $content = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    $private = curl_getinfo($ch, CURLINFO_PRIVATE);
                    $item = json_decode($private, true) ?: [];

                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($handles[(int) $ch]);

                    $originalUrl = mb_convert_encoding($item['url'] ?? '', 'UTF-8', 'UTF-8');

                    if ($content === false || $httpCode !== 200) {
                        $errors[] = [
                            'url' => $originalUrl,
                            'error' => $error ? mb_convert_encoding($error, 'UTF-8', 'UTF-8') : "HTTP {$httpCode}",
                        ];
                    } else {
                        if (strlen($content) > 30 * 1024 * 1024) {
                            $errors[] = [
                                'url' => $originalUrl,
                                'error' => 'Файл слишком большой (максимум 30MB)',
                            ];
                        } else {
                            $mimeType = $this->getMimeTypeFromData($content);
                            $allowedMimeTypes = [
                                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml', 'image/tiff', 'image/x-icon',
                            ];
                            if (! in_array($mimeType, $allowedMimeTypes)) {
                                $errors[] = [
                                    'url' => $originalUrl,
                                    'error' => 'Скачанный контент не является изображением (MIME: '.$mimeType.')',
                                ];
                            } else {
                                file_put_contents($item['absolutePath'], $content);
                                if ($optimize || $resize !== 'no_change') {
                                    if ($request->boolean('queue_post_process', false)) {
                                        PostProcessImage::dispatch($item['absolutePath'], $resize, $width, $height);
                                    } else {
                                        $this->processImage($item['absolutePath'], $resize, $width, $height);
                                    }
                                }
                                $results[$originalUrl] = mb_convert_encoding($item['relativePath'], 'UTF-8', 'UTF-8');
                                try {
                                    Redis::hSet($cacheKey, $originalUrl, $item['relativePath']);
                                } catch (\Throwable $e) {
                                    // Ошибки Redis не критичны для основного потока
                                }
                            }
                        }
                    }

                    // Добавляем следующий элемент в очередь
                    if ($nextIndex < $totalToDownload) {
                        $nextItem = $queue[$nextIndex++];
                        $chNext = $createHandle($nextItem);
                        $handles[(int) $chNext] = $chNext;
                        curl_multi_add_handle($mh, $chNext);
                    }
                }

                if ($active) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($active || ! empty($handles));

            curl_multi_close($mh);
            if ($sh && function_exists('curl_share_close')) {
                curl_share_close($sh);
            }

            // Очищаем все данные перед JSON-кодированием для предотвращения ошибок UTF-8
            $cleanResults = [];
            foreach ($results as $url => $path) {
                $cleanUrl = mb_convert_encoding($url, 'UTF-8', 'UTF-8');
                $cleanPath = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
                $cleanResults[$cleanUrl] = $cleanPath;
            }

            $cleanSkipped = array_map(function ($url) {
                return mb_convert_encoding($url, 'UTF-8', 'UTF-8');
            }, $skipped);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return response()->json([
                'success' => true,
                'data' => [
                    'paths' => $cleanResults,
                    'skipped' => $cleanSkipped,
                    'errors' => $errors,
                    'total' => count($imageUrls),
                    'successful' => count($cleanResults),
                    'failed' => count($errors),
                    'skipped_count' => count($cleanSkipped),
                    'metrics' => [
                        'duration_ms' => $durationMs,
                        'concurrency_used' => $concurrency,
                        'timeout_s' => $timeout,
                        'connect_timeout_s' => $connectTimeout,
                        'to_download' => $totalToDownload,
                    ],
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\Exception $e) {
            // Очищаем сообщение об ошибке от невалидных UTF-8 символов
            $errorMessage = $e->getMessage();
            $errorMessage = mb_convert_encoding($errorMessage, 'UTF-8', 'UTF-8');
            $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage); // Удаляем управляющие символы

            return response()->json([
                'success' => false,
                'message' => 'Ошибка пакетной загрузки изображений: '.$errorMessage,
            ], 500);
        }
    }

    public function startImageDownloadsTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imageUrls' => 'required|array|min:1|max:5000',
            'imageUrls.*' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'concurrency' => 'nullable|integer|min:1|max:20',
            'timeout' => 'nullable|integer|min:5|max:120',
            'connect_timeout' => 'nullable|integer|min:1|max:60',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }
        $taskId = (string) Str::uuid();
        $imageUrls = $request->input('imageUrls');
        $storagePath = $request->input('storagePath');
        $options = [
            'optimize' => $request->boolean('optimize', true),
            'naming' => $request->input('naming', 'hash'),
            'resize' => $request->input('resize', 'no_change'),
            'width' => $request->input('width'),
            'height' => $request->input('height'),
            'concurrency' => (int) $request->input('concurrency', 12),
            'timeout' => (int) $request->input('timeout', 60),
            'connect_timeout' => (int) $request->input('connect_timeout', 10),
        ];
        $metaKey = "imgdl:$taskId:meta";
        Redis::hMSet($metaKey, [
            'status' => 'pending',
            'total' => count($imageUrls),
            'queued' => count($imageUrls),
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ]);
        Redis::expire($metaKey, 172800);
        ImageDownloadTaskJob::dispatch($taskId, $imageUrls, $storagePath, $options);

        return response()->json([
            'success' => true,
            'data' => ['task_id' => $taskId],
        ]);
    }

    public function imageDownloadsTaskStatus(string $taskId): JsonResponse
    {
        $metaKey = "imgdl:$taskId:meta";
        $errorsKey = "imgdl:$taskId:errors";
        $recentKey = "imgdl:$taskId:recent";
        $pathsKey = "imgdl:$taskId:paths";
        $meta = Redis::hGetAll($metaKey);
        if (! $meta) {
            return response()->json([
                'success' => false,
                'message' => 'task_not_found',
            ], 404);
        }
        $errors = Redis::lrange($errorsKey, -50, -1) ?: [];
        $recent = Redis::lrange($recentKey, -50, -1) ?: [];
        $progressPct = 0;
        $total = (int) ($meta['total'] ?? 0);
        $processed = (int) ($meta['success'] ?? 0) + (int) ($meta['failed'] ?? 0) + (int) ($meta['skipped'] ?? 0);
        if ($total > 0) {
            $progressPct = (int) round($processed * 100 / $total);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'meta' => $meta,
                'progress_pct' => $progressPct,
                'recent' => array_map(function ($v) {
                    return json_decode($v, true);
                }, $recent),
                'errors' => array_map(function ($v) {
                    return json_decode($v, true);
                }, $errors),
            ],
        ]);
    }

    public function imageDownloadsTaskResult(string $taskId): JsonResponse
    {
        $pathsKey = "imgdl:$taskId:paths";
        $metaKey = "imgdl:$taskId:meta";
        $exists = Redis::hGetAll($metaKey);
        if (! $exists) {
            return response()->json(['success' => false, 'message' => 'task_not_found'], 404);
        }
        $paths = Redis::hGetAll($pathsKey) ?: [];

        return response()->json([
            'success' => true,
            'data' => [
                'paths' => $paths,
            ],
        ]);
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
            'height' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $image = $request->file('image');
            $path = $request->input('path');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            // Путь для сохранения на фронтенд (из FRONTEND_PATH в .env)
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath.'/'.ltrim($path, '/');
            $dir = dirname($fullPath);

            // Создаем директорию если не существует
            if (! is_dir($dir)) {
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
                    'size' => filesize($fullPath),
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения изображения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Загрузка одного изображения (вспомогательный метод для пакетной загрузки)
     */
    private function downloadSingleImage($imageUrl, $storagePath, $optimize, $naming, $resize, $width, $height, $index)
    {
        try {
            // Очищаем URL от невалидных UTF-8 символов перед обработкой
            $imageUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
            $imageUrl = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $imageUrl); // Удаляем управляющие символы

            // Валидация URL
            if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Неверный формат URL',
                ];
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            if (! in_array($extension, $imageExtensions)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Неподдерживаемый формат изображения',
                ];
            }

            // Генерация имени файла
            if ($naming === 'original') {
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $originalName = pathinfo($urlPath, PATHINFO_FILENAME);
                // Очищаем от невалидных UTF-8 символов
                $originalName = mb_convert_encoding($originalName, 'UTF-8', 'UTF-8');
                $originalName = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
                $fileName = $originalName.'.'.$extension;
                // Дополнительная очистка для безопасности
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                $hash = hash('sha256', $imageUrl.$index); // Добавляем индекс для уникальности
                $fileName = $hash.'.'.$extension;
            }

            // Полный путь для сохранения на фронтенд
            $fullPath = $storagePath.'/'.$fileName;
            // Получаем путь к фронтенду из FRONTEND_PATH в .env
            $frontendPublicPath = frontend_public_path();
            $storageFullPath = $frontendPublicPath.'/'.ltrim($fullPath, '/');
            $normalizedStorageFullPath = realpath($storageFullPath) ?: $storageFullPath;

            // Проверяем, существует ли файл уже (до скачивания, чтобы не тратить время)
            if (file_exists($normalizedStorageFullPath)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
                $cleanPath = mb_convert_encoding($fullPath, 'UTF-8', 'UTF-8');

                return [
                    'success' => true,
                    'originalUrl' => $cleanUrl,
                    'path' => $cleanPath,
                    'skipped' => true, // Флаг, что файл был пропущен (уже существовал)
                ];
            }

            // Создаем директорию если не существует
            $directory = dirname($normalizedStorageFullPath);
            if (! \App\Helpers\StorageHelper::createDirectory($directory)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Не удалось создать директорию для изображения',
                ];
            }

            // Нормализуем URL перед скачиванием
            $normalizedImageUrl = $this->normalizeImageUrl($imageUrl);

            // Скачиваем изображение с помощью cURL для обхода SSL проблем
            $downloadResult = $this->downloadImageWithCurl($normalizedImageUrl);

            if (! $downloadResult['success']) {
                // Очищаем сообщение об ошибке от невалидных UTF-8 символов
                $errorMsg = $downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}";
                $errorMsg = mb_convert_encoding($errorMsg, 'UTF-8', 'UTF-8');
                $errorMsg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMsg);

                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Не удалось скачать изображение: '.$errorMsg,
                ];
            }

            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Файл слишком большой (максимум 30MB)',
                ];
            }

            // Проверка MIME типа - убеждаемся, что это действительно изображение
            $mimeType = $this->getMimeTypeFromData($imageData);
            $allowedMimeTypes = [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/bmp',
                'image/svg+xml',
                'image/tiff',
                'image/x-icon',
            ];

            if (! in_array($mimeType, $allowedMimeTypes)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Скачанный контент не является изображением (MIME: '.$mimeType.')',
                ];
            }

            // Сохраняем файл (проверка на существование уже была выше)
            file_put_contents($normalizedStorageFullPath, $imageData);

            // Обработка изображения
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($normalizedStorageFullPath, $resize, $width, $height);
            }

            // Очищаем URL и путь от невалидных UTF-8 символов перед возвратом
            $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
            $cleanPath = mb_convert_encoding($fullPath, 'UTF-8', 'UTF-8');

            return [
                'success' => true,
                'originalUrl' => $cleanUrl,
                'path' => $cleanPath,
                'skipped' => false, // Файл был загружен (не существовал ранее)
            ];

        } catch (\Exception $e) {
            // Очищаем сообщение об ошибке и URL от невалидных UTF-8 символов
            $errorMessage = $e->getMessage();
            $errorMessage = mb_convert_encoding($errorMessage, 'UTF-8', 'UTF-8');
            $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage); // Удаляем управляющие символы

            $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

            \Log::error("downloadSingleImage error: {$cleanUrl} - {$errorMessage}");

            return [
                'success' => false,
                'originalUrl' => $cleanUrl,
                'error' => 'Ошибка: '.$errorMessage,
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
            if (! $imageInfo) {
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
        // Нормализуем URL перед использованием в cURL
        $normalizedUrl = $this->normalizeImageUrl($imageUrl);

        // Сначала пробуем cURL с агрессивными настройками SSL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $normalizedUrl);
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
            'success' => $imageData !== false && $httpCode === 200,
        ];
    }

    /**
     * Fallback метод для скачивания изображений через wget
     */
    private function downloadImageWithWget($imageUrl)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'image_download_');

        try {
            // Нормализуем URL перед передачей в wget
            // Чспользуем функцию normalizeImageUrl, которая делает многократное декодирование
            $normalizedUrl = $this->normalizeImageUrl($imageUrl);

            // Чспользуем wget с отключенной проверкой SSL
            $wgetCommand = "wget --no-check-certificate --timeout=30 --tries=1 -O '{$tempFile}' ".escapeshellarg($normalizedUrl).' 2>&1';
            $output = shell_exec($wgetCommand);

            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $imageData = file_get_contents($tempFile);
                unlink($tempFile);

                return [
                    'data' => $imageData,
                    'http_code' => 200,
                    'error' => null,
                    'success' => true,
                ];
            } else {
                unlink($tempFile);

                return [
                    'data' => false,
                    'http_code' => 0,
                    'error' => 'wget failed: '.$output,
                    'success' => false,
                ];
            }
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'data' => false,
                'http_code' => 0,
                'error' => 'wget exception: '.$e->getMessage(),
                'success' => false,
            ];
        }
    }

    /**
     * Получение MIME типа из бинарных данных изображения
     */
    private function getMimeTypeFromData($data)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $data);
        finfo_close($finfo);

        return $mimeType;
    }

    /**
     * Нормализация URL изображения для правильной обработки Unicode символов
     */
    private function normalizeImageUrl($url)
    {
        if (empty($url)) {
            return $url;
        }

        try {
            // Парсим URL
            $parsed = parse_url($url);

            if (! $parsed || ! isset($parsed['scheme']) || ! isset($parsed['host'])) {
                return $url; // Если не удалось распарсить, возвращаем как есть
            }

            // Многократное декодирование пути для исправления двойного/тройного кодирования
            $path = isset($parsed['path']) ? $parsed['path'] : '';
            $previousPath = '';
            $decodeAttempts = 0;
            $maxDecodeAttempts = 5;

            while ($decodeAttempts < $maxDecodeAttempts && $path !== $previousPath) {
                $previousPath = $path;
                $path = urldecode($path);
                $decodeAttempts++;
            }

            // Нормализуем Unicode символы (NFD -> NFC)
            // Это исправляет проблемы с комбинирующими диакритическими знаками (например, iМ†)
            if (class_exists('Normalizer') && method_exists('Normalizer', 'normalize')) {
                $path = \Normalizer::normalize($path, \Normalizer::FORM_C);
            } elseif (function_exists('normalizer_normalize')) {
                $path = normalizer_normalize($path, \Normalizer::FORM_C);
            }

            // Правильно кодируем путь заново
            $pathParts = explode('/', $path);
            $encodedParts = array_map(function ($part) {
                if (empty($part)) {
                    return $part;
                }

                // Кодируем каждый компонент пути отдельно
                return rawurlencode($part);
            }, $pathParts);

            $encodedPath = implode('/', $encodedParts);

            // Восстанавливаем двоеточие в пути (оно должно быть закодировано как %3A)
            $encodedPath = str_replace(':', '%3A', $encodedPath);

            // Собираем URL обратно
            $normalized = $parsed['scheme'].'://'.$parsed['host'];
            if (isset($parsed['port'])) {
                $normalized .= ':'.$parsed['port'];
            }
            $normalized .= $encodedPath;
            if (isset($parsed['query'])) {
                $normalized .= '?'.$parsed['query'];
            }
            if (isset($parsed['fragment'])) {
                $normalized .= '#'.$parsed['fragment'];
            }

            return $normalized;
        } catch (\Exception $e) {
            // Если нормализация не удалась, возвращаем исходный URL
            return $url;
        }
    }

    /**
     * Обработка изображения с различными типами изменения размера
     */
    private function processImage($filePath, $resize, $width, $height)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (! $imageInfo) {
                return;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Если размеры не заданы, используем оригинальные
            if (! $width || ! $height) {
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

            if (! $sourceImage) {
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
            \Illuminate\Support\Facades\Log::warning('Ошибка обработки изображения: '.$e->getMessage());
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

        return $setting ? (int) $setting->value : 500;
    }

    /**
     * Получить системную высоту изображений товаров
     */
    private function getSystemImageHeight()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_height')->first();

        return $setting ? (int) $setting->value : 500;
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
            'data.*' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
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
                'item' => $item,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results,
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
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Чзменение размера изображения
     */
    private function resizeImageFile($imagePath, $width, $height, $resizeType)
    {
        try {
            if (! file_exists($imagePath)) {
                return false;
            }

            $imageInfo = getimagesize($imagePath);
            if (! $imageInfo) {
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

            if (! $sourceImage) {
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

            if (! $newImage) {
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
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
                'errors' => [], // Детальная информация об ошибках
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
                            \Log::warning('Пропущена характеристика с слишком длинным названием', [
                                'good_id' => $good->id,
                                'property_name_length' => mb_strlen($propertyName),
                                'property_name_preview' => mb_substr($propertyName, 0, 100).'...',
                            ]);

                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'name_too_long',
                                'message' => "Название характеристики слишком длинное ({$maxNameLength} символов максимум)",
                                'details' => 'Название длиной '.mb_strlen($propertyName).' символов (показано первые 100: '.mb_substr($propertyName, 0, 100).'...)',
                            ];

                            continue;
                        }

                        // Проверяем длину значения (VARCHAR(255) = максимум 255 символов)
                        $maxValueLength = 255;
                        if (mb_strlen($propertyValue) > $maxValueLength) {
                            \Log::warning('Пропущена характеристика с слишком длинным значением', [
                                'good_id' => $good->id,
                                'property_name' => $propertyName,
                                'value_length' => mb_strlen($propertyValue),
                                'value_preview' => mb_substr($propertyValue, 0, 100).'...',
                            ]);

                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'value_too_long',
                                'message' => "Значение характеристики слишком длинное ({$maxValueLength} символов максимум)",
                                'details' => "Характеристика '{$propertyName}': значение длиной ".mb_strlen($propertyValue).' символов (показано первые 100: '.mb_substr($propertyValue, 0, 100).'...)',
                            ];

                            continue;
                        }

                        // Чщем или создаем свойство
                        $property = null;
                        $cacheKey = strtolower($propertyName);

                        if (isset($propertiesCache[$cacheKey])) {
                            $property = $propertiesCache[$cacheKey];
                        } else {
                            try {
                                // Нормализуем название: только первое слово с большой буквы
                                $normalizedName = mb_strtolower($propertyName);
                                $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)).mb_substr($normalizedName, 1);

                                $property = ShopProperty::whereRaw('LOWER(name) = ?', [strtolower($propertyName)])->first();

                                if (! $property) {
                                    $property = ShopProperty::create([
                                        'name' => $normalizedName,
                                        'property_type' => 'string',
                                        'slug' => \Illuminate\Support\Str::slug($normalizedName),
                                    ]);
                                } else {
                                    // Если свойство существует, обновляем его название на нормализованное (если оно отличается)
                                    if ($property->name !== $normalizedName) {
                                        $property->update([
                                            'name' => $normalizedName,
                                        ]);
                                    }
                                }

                                $propertiesCache[$cacheKey] = $property;
                            } catch (\Exception $e) {
                                \Log::error('Ошибка создания/поиска свойства', [
                                    'good_id' => $good->id,
                                    'property_name' => $propertyName,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage(),
                                ]);

                                continue;
                            }
                        }

                        if (! $property) {
                            continue;
                        }

                        // Чщем или создаем значение свойства
                        $propertyValueModel = null;
                        $valueCacheKey = $property->id.'_'.strtolower($propertyValue);

                        if (isset($propertyValuesCache[$valueCacheKey])) {
                            $propertyValueModel = $propertyValuesCache[$valueCacheKey];
                        } else {
                            try {
                                $propertyValueModel = ShopPropertyValue::where('property_id', $property->id)
                                    ->whereRaw('LOWER(value) = ?', [strtolower($propertyValue)])
                                    ->first();

                                if (! $propertyValueModel) {
                                    $propertyValueModel = ShopPropertyValue::create([
                                        'property_id' => $property->id,
                                        'value' => $propertyValue,
                                        'is_active' => true,
                                    ]);
                                }

                                $propertyValuesCache[$valueCacheKey] = $propertyValueModel;
                            } catch (\Exception $e) {
                                \Log::error('Ошибка создания/поиска значения свойства', [
                                    'good_id' => $good->id,
                                    'property_id' => $property->id,
                                    'property_name' => $propertyName,
                                    'property_value' => $propertyValue,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage(),
                                ]);

                                $stats['errors'][] = [
                                    'good_id' => $good->id,
                                    'type' => 'property_value_error',
                                    'message' => $e->getMessage(),
                                    'details' => "Ошибка при создании значения свойства '{$propertyName}': '{$propertyValue}'",
                                ];

                                continue;
                            }
                        }

                        if ($propertyValueModel) {
                            $propertiesToSave[] = [
                                'property_id' => $property->id,
                                'shop_property_value_id' => $propertyValueModel->id,
                                'value' => $propertyValue,
                            ];
                        }
                    }

                    // Сохраняем свойства товара
                    if (! empty($propertiesToSave)) {
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
                                    'updated_at' => now(),
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

                                // Чспользуем updateOrInsert для избежания дубликатов
                                $whereConditions = [
                                    'good_id' => $good->id,
                                    'property_id' => $prop['property_id'],
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
                            $errorMessage = 'Ошибка сохранения свойств для товара '.$good->id.': '.$e->getMessage();
                            \Log::error($errorMessage, [
                                'good_id' => $good->id,
                                'exception' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                                'properties_count' => count($propertiesToSave),
                            ]);

                            $stats['error']++;
                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'save_error',
                                'message' => $e->getMessage(),
                                'details' => 'Ошибка при сохранении свойств в БД',
                            ];
                        }
                    } else {
                        $stats['skipped']++;
                    }

                    $stats['processed']++;
                } catch (\Exception $e) {
                    $errorMessage = 'Ошибка обработки товара '.$good->id.': '.$e->getMessage();
                    \Log::error($errorMessage, [
                        'good_id' => $good->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    $stats['error']++;
                    $stats['errors'][] = [
                        'good_id' => $good->id,
                        'type' => 'processing_error',
                        'message' => $e->getMessage(),
                        'details' => 'Ошибка при парсинге или обработке товара',
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
                    'has_more' => $goods->count() === $batchSize,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка массового парсинга: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового парсинга: '.$e->getMessage(),
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

        // Чспользуем DOMDocument для парсинга HTML
        libxml_use_internal_errors(true);

        // Оборачиваем в контейнер для корректного парсинга
        $wrappedHtml = '<div>'.$htmlDescription.'</div>';
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

                if (! empty($textContent) && mb_strlen($textContent) >= 3) {
                    $lines[] = [
                        'html' => $innerHTML,
                        'text' => $textContent,
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

                    if (! $isNested) {
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
                        $tempWrapped = '<div>'.$htmlPart.'</div>';
                        @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        $text = trim($tempDom->textContent);

                        if (! empty($text) && mb_strlen($text) >= 3) {
                            $lines[] = [
                                'html' => $htmlPart,
                                'text' => $text,
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
                    $tempWrapped = '<div>'.$htmlPart.'</div>';
                    @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $text = trim($tempDom->textContent);

                    if (! empty($text) && mb_strlen($text) >= 3) {
                        $lines[] = [
                            'html' => $htmlPart,
                            'text' => $text,
                        ];
                    }
                }
            } else {
                // Если HTML не разбился, пробуем разбить по переносам строк в тексте
                $textLines = preg_split('/\n/', $textContent);
                foreach ($textLines as $textLine) {
                    $textLine = trim($textLine);
                    if (! empty($textLine) && mb_strlen($textLine) >= 3) {
                        $lines[] = [
                            'html' => $textLine,
                            'text' => $textLine,
                        ];
                    }
                }
            }
        }

        // Если все еще нет строк, берем весь контент как одну строку
        if (empty($lines)) {
            $htmlContent = $htmlDescription;
            $textContent = strip_tags($htmlDescription);

            if (! empty($textContent) && mb_strlen($textContent) >= 3) {
                $lines[] = [
                    'html' => $htmlContent,
                    'text' => $textContent,
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
            $lineWrapped = '<div>'.$lineHTML.'</div>';
            $lineDom = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            @$lineDom->loadHTML(mb_convert_encoding($lineWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $lineXpath = new \DOMXPath($lineDom);

            // Чщем жирные элементы (strong, b) - сначала стандартные теги
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
                // ШАГ 1: Чзвлекаем ВЕСЬ текст из жирного элемента - это характеристика
                $propertyName = trim($boldElement->textContent);
                $propertyName = preg_replace('/\s+/', ' ', $propertyName);

                // Убираем двоеточие в конце названия, если есть
                $propertyName = preg_replace('/:\s*$/', '', $propertyName);

                if (! empty($propertyName)) {
                    // ШАГ 2: Чщем значение - сначала пробуем найти следующий sibling элемент
                    $propertyValue = '';
                    $nextSibling = $boldElement->nextSibling;

                    while ($nextSibling) {
                        if ($nextSibling->nodeType === XML_ELEMENT_NODE) {
                            $siblingText = trim($nextSibling->textContent);
                            if (! empty($siblingText)) {
                                $propertyValue = $siblingText;
                                break;
                            }
                        } elseif ($nextSibling->nodeType === XML_TEXT_NODE) {
                            $text = trim($nextSibling->textContent);
                            if (! empty($text)) {
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

                            $afterBoldWrapped = '<div>'.$afterBoldHTML.'</div>';
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
                // Вариант 2: Чщем текст до двоеточия в текстовом представлении
                // Но только если двоеточие находится в начале строки или после короткого названия (не более 3 слов)
                $colonIndex = mb_strpos($lineText, ':');
                if ($colonIndex !== false && $colonIndex > 0 && $colonIndex < mb_strlen($lineText) - 1) {
                    $beforeColon = trim(mb_substr($lineText, 0, $colonIndex));
                    $wordsBeforeColon = preg_split('/\s+/u', $beforeColon);
                    $wordsBeforeColon = array_filter($wordsBeforeColon, function ($w) {
                        return ! empty(trim($w));
                    });

                    // Чспользуем двоеточие только если до него не более 3 слов (чтобы не разбивать значения с двоеточием внутри)
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
                    // Чщем дефис с пробелами вокруг (например: " - " или " -")
                    $dashPattern = '/\s*-\s*/u';
                    $dashMatch = preg_match($dashPattern, $lineText, $matches, PREG_OFFSET_CAPTURE);

                    if ($dashMatch && isset($matches[0]) && isset($matches[0][1])) {
                        $dashIndex = $matches[0][1];
                        $beforeDash = trim(mb_substr($lineText, 0, $dashIndex));

                        // Проверяем, что до дефиса не более 3-х слов
                        $words = preg_split('/\s+/u', $beforeDash);
                        $words = array_filter($words, function ($w) {
                            return ! empty(trim($w));
                        });

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
            $propertyName = mb_strtoupper(mb_substr($propertyName, 0, 1)).mb_substr($propertyName, 1);

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

            if (! $isDuplicate) {
                $results[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue,
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
                'data' => $attributesString,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения атрибутов вариации: '.$e->getMessage(),
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
                'stock_quantity' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $variation->stock_quantity = $request->get('stock_quantity');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Остаток обновлен',
                'data' => [
                    'id' => $variation->id,
                    'stock_quantity' => $variation->stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления остатка: '.$e->getMessage(),
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
                'remote_stock_quantity' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $remoteStockValue = $request->get('remote_stock_quantity');
            $variation->remote_stock_quantity = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Остаток на у/с обновлен',
                'data' => [
                    'id' => $variation->id,
                    'remote_stock_quantity' => $variation->remote_stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления остатка на у/с: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить быстрый остаток на у/с вариации
     */
    public function updateVariationFastRemoteStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'fast_remote_stock_quantity' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $fastRemoteStockValue = $request->get('fast_remote_stock_quantity');
            $variation->fast_remote_stock_quantity = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Быстрый остаток на у/с обновлен',
                'data' => [
                    'id' => $variation->id,
                    'fast_remote_stock_quantity' => $variation->fast_remote_stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления быстрого остатка на у/с: '.$e->getMessage(),
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
                'demping_price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $price = round((float) $request->get('price'));
            $salePrice = $request->get('sale_price');
            if ($salePrice !== null && $salePrice !== '') {
                $salePrice = round((float) $salePrice);
                // Акционная цена не может быть больше или равна базовой
                if ($salePrice >= $price) {
                    $salePrice = null;
                }
                // Акционная цена не может быть отрицательной
                if ($salePrice < 0) {
                    $salePrice = 0;
                }
            } else {
                $salePrice = null;
            }

            $dempingPrice = $request->get('demping_price');
            if ($dempingPrice !== null && $dempingPrice !== '') {
                $dempingPrice = round((float) $dempingPrice);
                if ($dempingPrice < 0) {
                    $dempingPrice = 0;
                }
            } else {
                $dempingPrice = null;
            }

            $variation->price = $price;
            $variation->sale_price = $salePrice;
            $variation->demping_price = $dempingPrice;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Цены обновлены',
                'data' => [
                    'id' => $variation->id,
                    'price' => $variation->price,
                    'sale_price' => $variation->sale_price,
                    'demping_price' => $variation->demping_price,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления цен: '.$e->getMessage(),
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
                'show_demping' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $variation->show_demping = $request->get('show_demping');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Демпинг обновлен',
                'data' => [
                    'id' => $variation->id,
                    'show_demping' => $variation->show_demping,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления демпинга: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Прокси для загрузки YML фидов (обход CORS)
     */
    public function proxyYMLFeed(Request $request): JsonResponse
    {
        try {
            // Middleware auth:sanctum уже проверил авторизацию
            // Если запрос дошел до этого метода, значит пользователь авторизован
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'url' => 'required|url|max:2048',
                'username' => 'nullable|string|max:255',
                'password' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $url = $request->input('url');
            $username = $request->input('username');
            $password = $request->input('password');

            // Валидация URL
            if (empty($url)) {
                return response()->json([
                    'success' => false,
                    'error' => 'URL не указан',
                ], 400);
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Некорректный URL',
                ], 400);
            }

            // Загружаем YML фид через cURL (на сервере нет ограничений CORS)
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            // Настройки SSL (аналогично методу downloadImage)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);

            // HTTP Basic Authentication, если указаны учетные данные
            if (! empty($username) && ! empty($password)) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
            }

            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
            curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/xml, text/xml, */*',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ]);

            $xmlContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($xmlContent === false || $curlErrno !== 0) {
                $errorMessage = $curlError ?: 'Неизвестная ошибка cURL';
                if ($curlErrno) {
                    $errorMessage .= ' (код ошибки: '.$curlErrno.')';
                }

                Log::error('Ошибка cURL при загрузке YML фида', [
                    'url' => $url,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno,
                    'http_code' => $httpCode,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Ошибка загрузки фида: '.$errorMessage,
                ], 500);
            }

            if ($httpCode !== 200) {
                return response()->json([
                    'success' => false,
                    'error' => "Ошибка загрузки фида: HTTP {$httpCode}",
                ], $httpCode);
            }

            // Проверяем, что это действительно XML
            if (empty($xmlContent)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Фид пуст',
                ], 400);
            }

            // Проверяем базовую структуру YML
            if (strpos($xmlContent, 'yml_catalog') === false && strpos($xmlContent, '<?xml') === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'Файл не является валидным YML фидом',
                ], 400);
            }

            // Парсим YML на сервере
            try {
                // Отключаем ошибки libxml для более гибкого парсинга
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlContent);

                if ($xml === false) {
                    $errors = libxml_get_errors();
                    libxml_clear_errors();
                    $errorMessages = array_map(function ($error) {
                        return trim($error->message);
                    }, $errors);

                    return response()->json([
                        'success' => false,
                        'error' => 'Ошибка парсинга XML: '.implode('; ', array_slice($errorMessages, 0, 3)),
                    ], 400);
                }

                // Проверяем структуру
                if (! isset($xml->shop)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Не найден элемент shop в YML фиде',
                    ], 400);
                }

                // Чзвлекаем данные о магазине
                $shopData = [
                    'name' => (string) ($xml->shop->name ?? ''),
                    'company' => (string) ($xml->shop->company ?? ''),
                    'url' => (string) ($xml->shop->url ?? ''),
                ];

                // Чзвлекаем валюты
                $currencies = [];
                if (isset($xml->shop->currencies->currency)) {
                    foreach ($xml->shop->currencies->currency as $currency) {
                        $currencies[] = [
                            'id' => (string) $currency['id'],
                            'rate' => (float) ($currency['rate'] ?? 1),
                        ];
                    }
                }

                // Чзвлекаем категории
                $categories = [];
                if (isset($xml->shop->categories->category)) {
                    foreach ($xml->shop->categories->category as $category) {
                        $categories[] = [
                            'id' => (string) $category['id'],
                            'name' => trim((string) $category),
                            'parentId' => isset($category['parentId']) ? (string) $category['parentId'] : null,
                        ];
                    }
                }

                // Чзвлекаем товары (offers)
                $offers = [];
                if (isset($xml->shop->offers->offer)) {
                    foreach ($xml->shop->offers->offer as $offer) {
                        $offerData = [
                            'id' => (string) $offer['id'],
                            'available' => (string) ($offer['available'] ?? 'false') === 'true',
                        ];

                        // Основные поля
                        if (isset($offer->name) && (string) $offer->name !== '') {
                            $offerData['name'] = trim((string) $offer->name);
                        }
                        if (isset($offer->price) && (string) $offer->price !== '') {
                            $offerData['price'] = (float) $offer->price;
                        }
                        if (isset($offer->currencyId) && (string) $offer->currencyId !== '') {
                            $offerData['currencyId'] = (string) $offer->currencyId;
                        }
                        if (isset($offer->categoryId) && (string) $offer->categoryId !== '') {
                            $offerData['categoryId'] = (string) $offer->categoryId;
                        }
                        if (isset($offer->url) && (string) $offer->url !== '') {
                            $offerData['url'] = (string) $offer->url;
                        }
                        if (isset($offer->vendor) && (string) $offer->vendor !== '') {
                            $offerData['vendor'] = (string) $offer->vendor;
                        }
                        if (isset($offer->model) && (string) $offer->model !== '') {
                            $offerData['model'] = (string) $offer->model;
                        }
                        if (isset($offer->description) && (string) $offer->description !== '') {
                            $offerData['description'] = trim((string) $offer->description);
                        }
                        if (isset($offer->vendorCode) && (string) $offer->vendorCode !== '') {
                            $offerData['vendorCode'] = (string) $offer->vendorCode;
                        }
                        if (isset($offer->barcode) && (string) $offer->barcode !== '') {
                            $offerData['barcode'] = (string) $offer->barcode;
                        }
                        if (isset($offer->oldprice) && (string) $offer->oldprice !== '') {
                            $offerData['oldprice'] = (float) $offer->oldprice;
                        }
                        if (isset($offer->sales_notes) && (string) $offer->sales_notes !== '') {
                            $offerData['sales_notes'] = (string) $offer->sales_notes;
                        }
                        if (isset($offer->manufacturer_warranty)) {
                            $offerData['manufacturer_warranty'] = (string) $offer->manufacturer_warranty === 'true';
                        }
                        if (isset($offer->country_of_origin) && (string) $offer->country_of_origin !== '') {
                            $offerData['country_of_origin'] = (string) $offer->country_of_origin;
                        }
                        if (isset($offer->adult)) {
                            $offerData['adult'] = (string) $offer->adult === 'true';
                        }

                        // Чзображения (может быть несколько)
                        $pictures = [];
                        if (isset($offer->picture)) {
                            // В SimpleXML picture может быть одним элементом или коллекцией
                            // Чспользуем count() для проверки количества элементов
                            $pictureCount = count($offer->picture);
                            if ($pictureCount > 1) {
                                // Несколько изображений - итерируем
                                foreach ($offer->picture as $picture) {
                                    $pictureUrl = trim((string) $picture);
                                    if ($pictureUrl) {
                                        $pictures[] = $pictureUrl;
                                    }
                                }
                            } else {
                                // Одно изображение
                                $pictureUrl = trim((string) $offer->picture);
                                if ($pictureUrl) {
                                    $pictures[] = $pictureUrl;
                                }
                            }
                        }
                        // Формируем поле picture согласно интерфейсу YMLOffer
                        if (count($pictures) === 1) {
                            $offerData['picture'] = $pictures[0];
                        } elseif (count($pictures) > 1) {
                            $offerData['picture'] = $pictures;
                        }

                        // Параметры (может быть несколько)
                        $params = [];
                        if (isset($offer->param)) {
                            // В SimpleXML param может быть одним элементом или коллекцией
                            $paramCount = count($offer->param);
                            if ($paramCount > 1) {
                                // Несколько параметров - итерируем
                                foreach ($offer->param as $param) {
                                    $paramName = (string) ($param['name'] ?? '');
                                    $paramValue = trim((string) $param);
                                    if ($paramName && $paramValue) {
                                        $params[] = [
                                            'name' => $paramName,
                                            'value' => $paramValue,
                                        ];
                                    }
                                }
                            } else {
                                // Один параметр
                                $paramName = (string) ($offer->param['name'] ?? '');
                                $paramValue = trim((string) $offer->param);
                                if ($paramName && $paramValue) {
                                    $params[] = [
                                        'name' => $paramName,
                                        'value' => $paramValue,
                                    ];
                                }
                            }
                        }
                        if (! empty($params)) {
                            $offerData['params'] = $params;
                        }

                        $offers[] = $offerData;
                    }
                }

                // Формируем результат
                $ymlData = [
                    'shop' => $shopData,
                    'currencies' => $currencies,
                    'categories' => $categories,
                    'offers' => $offers,
                    'date' => isset($xml['date']) ? (string) $xml['date'] : null,
                ];

                // Возвращаем распарсенные данные
                return response()->json([
                    'success' => true,
                    'data' => $ymlData,
                ]);

            } catch (\Exception $parseError) {
                // Если парсинг не удался, возвращаем XML для парсинга на клиенте
                return response()->json([
                    'success' => true,
                    'data' => [
                        'xml' => $xmlContent,
                        'url' => $url,
                        'size' => strlen($xmlContent),
                    ],
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка при загрузке фида: '.$e->getMessage(),
                'message' => 'Ошибка при загрузке фида: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Загрузка YML/XML файла на сервер для последующего парсинга
     */
    public function uploadYMLFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xml,yml|max:51200', // 50MB max
            ]);

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();

            // Проверяем расширение
            if (! in_array(strtolower($extension), ['xml', 'yml'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Файл должен иметь расширение .xml или .yml',
                ], 400);
            }

            // Генерируем уникальное имя файла
            $fileName = 'yml_upload_'.time().'_'.Str::random(8).'.'.$extension;
            $filePath = storage_path('app/temp/'.$fileName);

            // Создаем директорию если не существует
            $directory = dirname($filePath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Сохраняем файл
            $file->move($directory, basename($filePath));

            // Проверяем, что файл сохранен
            if (! file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Не удалось сохранить файл',
                ], 500);
            }

            // Возвращаем URL для доступа к файлу
            $fileUrl = url('/api/admin/shop/goods/temp-yml-file/'.$fileName);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => $fileUrl,
                    'filename' => $fileName,
                    'original_name' => $originalName,
                    'size' => filesize($filePath),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка при загрузке файла: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удаление временного YML файла
     */
    public function deleteTempYMLFile(Request $request, string $filename): JsonResponse
    {
        try {
            // Проверяем формат имени файла для безопасности
            if (! preg_match('/^yml_upload_\d+_[a-zA-Z0-9]+\.(xml|yml)$/', $filename)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Некорректное имя файла',
                ], 400);
            }

            $filePath = storage_path('app/temp/'.$filename);

            if (file_exists($filePath)) {
                unlink($filePath);

                return response()->json([
                    'success' => true,
                    'message' => 'Файл удален',
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Файл не найден',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка при удалении файла: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить временный YML файл
     */
    public function getTempYMLFile(Request $request, string $filename)
    {
        // Проверяем, что пользователь авторизован через токен (для API запросов)
        if (! $request->user()) {
            abort(403, 'Unauthorized');
        }

        // Проверяем формат имени файла для безопасности
        if (! preg_match('/^yml_upload_\d+_[a-zA-Z0-9]+\.(xml|yml)$/', $filename)) {
            abort(404, 'File not found');
        }

        $filePath = storage_path('app/temp/'.$filename);

        if (! file_exists($filePath)) {
            abort(404, 'File not found');
        }

        // Ч§итаем файл и возвращаем как текст
        $content = file_get_contents($filePath);

        return response($content, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Получить статистику количества товаров по категориям
     */
    public function getCategoriesStats(Request $request): JsonResponse
    {
        try {
            // Получаем количество товаров для каждой категории одним SQL запросом
            $stats = DB::table('shop_good_categories')
                ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                ->where('shop_goods.is_active', true)
                ->select('shop_good_categories.category_id', DB::raw('COUNT(*) as products_count'))
                ->groupBy('shop_good_categories.category_id')
                ->get()
                ->pluck('products_count', 'category_id')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список всех уникальных названий характеристик
     */
    public function getCharacteristicsList(): JsonResponse
    {
        try {
            // Получаем все уникальные названия характеристик из таблицы shop_properties
            // через связь с shop_good_properties
            $characteristics = DB::table('shop_properties')
                ->join('shop_good_properties', 'shop_properties.id', '=', 'shop_good_properties.property_id')
                ->select('shop_properties.name')
                ->distinct()
                ->whereNotNull('shop_properties.name')
                ->where('shop_properties.name', '!=', '')
                ->orderBy('shop_properties.name')
                ->pluck('shop_properties.name')
                ->map(function ($name) {
                    return [
                        'id' => $name,
                        'name' => $name,
                    ];
                })
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $characteristics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка характеристик: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Перенос данных между товарами
     */
    public function transferData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_good_id' => 'required|exists:shop_goods,id',
            'target_good_id' => 'required|exists:shop_goods,id|different:source_good_id',
            'transfer' => 'required|array',
            'transfer.description' => 'nullable|boolean',
            'transfer.short_description' => 'nullable|boolean',
            'transfer.variations' => 'nullable|boolean',
            'transfer.images' => 'nullable|boolean',
            'delete_source' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $sourceGood = ShopGood::with(['variations', 'images'])->findOrFail($request->source_good_id);
            $targetGood = ShopGood::with(['variations', 'images'])->findOrFail($request->target_good_id);
            $transfer = $request->transfer;
            $deleteSource = $request->boolean('delete_source', false);

            $transferredItems = [];

            // Функция для сравнения характеристик вариаций (используется в нескольких местах)
            $compareVariationAttributes = function ($attrs1, $attrs2) {
                if (count($attrs1) !== count($attrs2)) {
                    return false;
                }

                // Сортируем по attribute_id и value_id для сравнения
                $sorted1 = collect($attrs1)->sortBy(function ($attr) {
                    return $attr['attribute_id'].'_'.$attr['value_id'];
                })->values()->toArray();

                $sorted2 = collect($attrs2)->sortBy(function ($attr) {
                    return $attr['attribute_id'].'_'.$attr['value_id'];
                })->values()->toArray();

                return json_encode($sorted1) === json_encode($sorted2);
            };

            // Перенос описания
            if (! empty($transfer['description'])) {
                $targetGood->description = $sourceGood->description;
                $transferredItems[] = 'описание';
            }

            // Перенос краткого описания
            if (! empty($transfer['short_description'])) {
                $targetGood->short_description = $sourceGood->short_description;
                $transferredItems[] = 'краткое описание';
            }

            // Сохраняем изменения в основных полях
            if (! empty($transfer['description']) || ! empty($transfer['short_description'])) {
                $targetGood->save();
            }

            // Перенос вариаций
            if (! empty($transfer['variations']) && $sourceGood->variations) {
                $sourceVariations = $sourceGood->variations;

                // Загружаем атрибуты для всех вариаций источника
                $sourceVariationIds = $sourceVariations->pluck('id')->toArray();
                $sourceVariationAttributes = [];

                if (! empty($sourceVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $sourceVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'a.name as attribute_name',
                            'av.id as value_id',
                            'av.value as value_value'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($sourceVariationAttributes[$row->variation_id])) {
                            $sourceVariationAttributes[$row->variation_id] = [];
                        }
                        $sourceVariationAttributes[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'attribute_name' => $row->attribute_name,
                            'value_id' => $row->value_id,
                            'value' => $row->value_value,
                        ];
                    }
                }

                // Загружаем атрибуты для всех вариаций получателя
                $targetVariations = $targetGood->variations;
                $targetVariationIds = $targetVariations->pluck('id')->toArray();
                $targetVariationAttributes = [];

                if (! empty($targetVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $targetVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'a.name as attribute_name',
                            'av.id as value_id',
                            'av.value as value_value'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($targetVariationAttributes[$row->variation_id])) {
                            $targetVariationAttributes[$row->variation_id] = [];
                        }
                        $targetVariationAttributes[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'attribute_name' => $row->attribute_name,
                            'value_id' => $row->value_id,
                            'value' => $row->value_value,
                        ];
                    }
                }

                $createdVariations = 0;
                $skippedVariations = 0;

                // Создаем вариации для получателя
                foreach ($sourceVariations as $sourceVariation) {
                    $sourceAttrs = $sourceVariationAttributes[$sourceVariation->id] ?? [];

                    // Проверяем, есть ли уже вариация с такими же характеристиками
                    $duplicateFound = false;
                    foreach ($targetVariations as $targetVariation) {
                        $targetAttrs = $targetVariationAttributes[$targetVariation->id] ?? [];
                        if ($compareVariationAttributes($sourceAttrs, $targetAttrs)) {
                            $duplicateFound = true;
                            $skippedVariations++;
                            break;
                        }
                    }

                    if (! $duplicateFound) {
                        // Создаем новую вариацию
                        $newVariation = \App\Models\ShopGoodVariation::create([
                            'good_id' => $targetGood->id,
                            'name' => $sourceVariation->name,
                            'sku' => $sourceVariation->sku,
                            'price' => $sourceVariation->price,
                            'sale_price' => $sourceVariation->sale_price,
                            'demping_price' => $sourceVariation->demping_price,
                            'show_demping' => $sourceVariation->show_demping,
                            'stock_quantity' => $sourceVariation->stock_quantity,
                            'remote_stock_quantity' => $sourceVariation->remote_stock_quantity,
                            'is_active' => $sourceVariation->is_active,
                        ]);

                        // Копируем атрибуты вариации
                        if (! empty($sourceAttrs)) {
                            foreach ($sourceAttrs as $attr) {
                                DB::table('shop_variation_attributes_values')->insert([
                                    'variation_id' => $newVariation->id,
                                    'attribute_value_id' => $attr['value_id'],
                                ]);
                            }
                        }

                        $createdVariations++;
                    }
                }

                $transferredItems[] = "вариации (создано: {$createdVariations}, пропущено: {$skippedVariations})";
            }

            // Перенос изображений (после операций с вариациями)
            if (! empty($transfer['images'])) {
                // Загружаем все вариации с атрибутами для обоих товаров
                $sourceVariations = ShopGood::with(['variations'])->find($sourceGood->id)->variations;
                $targetVariations = ShopGood::with(['variations'])->find($targetGood->id)->variations;

                // Загружаем атрибуты вариаций источника
                $sourceVariationIds = $sourceVariations->pluck('id')->toArray();
                $sourceVariationAttributesMap = [];

                if (! empty($sourceVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $sourceVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'av.id as value_id'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($sourceVariationAttributesMap[$row->variation_id])) {
                            $sourceVariationAttributesMap[$row->variation_id] = [];
                        }
                        $sourceVariationAttributesMap[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'value_id' => $row->value_id,
                        ];
                    }
                }

                // Загружаем атрибуты вариаций получателя
                $targetVariationIds = $targetVariations->pluck('id')->toArray();
                $targetVariationAttributesMap = [];

                if (! empty($targetVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $targetVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'av.id as value_id'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($targetVariationAttributesMap[$row->variation_id])) {
                            $targetVariationAttributesMap[$row->variation_id] = [];
                        }
                        $targetVariationAttributesMap[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'value_id' => $row->value_id,
                        ];
                    }
                }

                // Функция для поиска соответствующей вариации получателя по характеристикам
                $findMatchingVariation = function ($sourceAttrs, $targetVariations, $targetVariationAttributesMap) use ($compareVariationAttributes) {
                    foreach ($targetVariations as $targetVariation) {
                        $targetAttrs = $targetVariationAttributesMap[$targetVariation->id] ?? [];
                        if ($compareVariationAttributes($sourceAttrs, $targetAttrs)) {
                            return $targetVariation->id;
                        }
                    }

                    return null;
                };

                // Переносим изображения основного товара
                $sourceMainImages = DB::table('shop_good_images')
                    ->where('good_id', $sourceGood->id)
                    ->whereNull('variation_id')
                    ->get();

                foreach ($sourceMainImages as $image) {
                    DB::table('shop_good_images')
                        ->where('id', $image->id)
                        ->update([
                            'good_id' => $targetGood->id,
                            'variation_id' => null,
                        ]);
                }

                // Переносим изображения вариаций
                foreach ($sourceVariations as $sourceVariation) {
                    $sourceAttrs = $sourceVariationAttributesMap[$sourceVariation->id] ?? [];
                    $matchingTargetVariationId = $findMatchingVariation($sourceAttrs, $targetVariations, $targetVariationAttributesMap);

                    $sourceVariationImages = DB::table('shop_good_images')
                        ->where('variation_id', $sourceVariation->id)
                        ->get();

                    foreach ($sourceVariationImages as $image) {
                        DB::table('shop_good_images')
                            ->where('id', $image->id)
                            ->update([
                                'good_id' => $targetGood->id,
                                'variation_id' => $matchingTargetVariationId,
                            ]);
                    }
                }

                $transferredItems[] = 'изображения';
            }

            // Удаление исходного товара, если указано
            if ($deleteSource) {
                $this->logAudit($sourceGood, 'deleted', $sourceGood->toArray(), null);
                $sourceGood->delete();
            }

            DB::commit();

            $message = 'Данные успешно перенесены: '.implode(', ', $transferredItems);
            if ($deleteSource) {
                $message .= '. Чсходный товар удален.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'transferred' => $transferredItems,
                    'delete_source' => $deleteSource,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ошибка переноса данных товаров: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка переноса данных: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Чзменить вариацию - перенос данных из товаров без вариаций в вариации товара с вариациями
     */
    public function changeVariation(Request $request): JsonResponse
    {

        // Сначала проверим основные поля
        $basicValidator = Validator::make($request->all(), [
            'good_with_variations_id' => 'required|exists:shop_goods,id',
            'goods_without_variations' => 'required|array|min:1',
            'goods_without_variations.*.update_description' => 'nullable|boolean',
            'goods_without_variations.*.update_short_description' => 'nullable|boolean',
            'goods_without_variations.*.update_slug' => 'nullable|boolean',
            'goods_without_variations.*.update_prices' => 'nullable|boolean',
            'goods_without_variations.*.selected_image_ids' => 'nullable|array',
            'goods_without_variations.*.selected_image_ids.*' => 'exists:shop_good_images,id',
        ]);

        if ($basicValidator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации основных полей',
                'errors' => $basicValidator->errors(),
            ], 422);
        }

        // Теперь проверим существование товаров и вариаций
        $validator = Validator::make($request->all(), [
            'goods_without_variations.*.id' => 'required|exists:shop_goods,id',
        ]);

        // Дополнительная валидация для variation_ids или variation_id
        $validator->after(function ($validator) use ($request) {
            $goodsWithoutVariations = $request->input('goods_without_variations', []);

            foreach ($goodsWithoutVariations as $index => $goodData) {
                if (! isset($goodData['variation_ids']) && ! isset($goodData['variation_id'])) {
                    $validator->errors()->add("goods_without_variations.{$index}.variation_ids", 'Поле variation_ids или variation_id обязательно для заполнения.');
                }

                if (isset($goodData['variation_ids'])) {
                    if (! is_array($goodData['variation_ids']) || empty($goodData['variation_ids'])) {
                        $validator->errors()->add("goods_without_variations.{$index}.variation_ids", 'Поле variation_ids должно быть непустым массивом.');
                    } else {
                        foreach ($goodData['variation_ids'] as $varIndex => $variationId) {
                            if (! is_numeric($variationId) || ! \App\Models\ShopGoodVariation::where('id', $variationId)->exists()) {
                                $validator->errors()->add("goods_without_variations.{$index}.variation_ids.{$varIndex}", 'Указанная вариация не существует.');
                            }
                        }
                    }
                }

                if (isset($goodData['variation_id']) && ! isset($goodData['variation_ids'])) {
                    if (! is_numeric($goodData['variation_id']) || ! \App\Models\ShopGoodVariation::where('id', $goodData['variation_id'])->exists()) {
                        $validator->errors()->add("goods_without_variations.{$index}.variation_id", 'Указанная вариация не существует.');
                    }
                }
            }
        });

        // Фильтруем только существующие товары
        $goodsWithoutVariations = $request->input('goods_without_variations', []);
        $validGoodsWithoutVariations = [];

        foreach ($goodsWithoutVariations as $goodData) {
            $goodId = $goodData['id'];
            if (\App\Models\ShopGood::where('id', $goodId)->exists()) {
                $validGoodsWithoutVariations[] = $goodData;
            }
        }

        // Проверяем, остались ли товары после фильтрации
        if (empty($validGoodsWithoutVariations)) {
            return response()->json([
                'success' => false,
                'message' => 'Ни один из указанных товаров не найден',
            ], 422);
        }

        // Обновляем данные запроса отфильтрованными товарами
        $request->merge(['goods_without_variations' => $validGoodsWithoutVariations]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации после фильтрации товаров',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodWithVariations = ShopGood::with(['variations'])->findOrFail($request->good_with_variations_id);
            $goodsWithoutVariations = $validGoodsWithoutVariations; // Чспользуем отфильтрованный массив

            // Проверяем, что товар действительно имеет вариации
            if ($goodWithVariations->variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Выбранный товар не имеет вариаций',
                ], 422);
            }

            $updatedVariations = [];
            $descriptionUpdateGoodId = null;
            $shortDescriptionUpdateGoodId = null;
            $slugUpdateGoodId = null;
            $transferredImagesInfo = []; // Чнформация о перенесенных изображениях

            // Сохраняем данные товаров-источников перед их удалением
            $sourceGoodsData = [];
            foreach ($goodsWithoutVariations as $goodData) {
                $goodId = $goodData['id'];
                $good = ShopGood::find($goodId);
                if ($good) {
                    $sourceGoodsData[$goodId] = [
                        'description' => $good->description,
                        'short_description' => $good->short_description,
                        'slug' => $good->slug,
                    ];
                }
            }

            // Обрабатываем каждый товар без вариаций
            foreach ($goodsWithoutVariations as $index => $goodData) {

                // Проверяем наличие variation_ids (новый формат) или variation_id (старый формат для обратной совместимости)
                if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                    $variationIds = $goodData['variation_ids'];
                } elseif (isset($goodData['variation_id'])) {
                    // Обратная совместимость со старым форматом
                    $variationIds = [$goodData['variation_id']];
                } else {
                    continue;
                }
                $goodId = $goodData['id'];
                $variationIds = $goodData['variation_ids']; // Теперь массив вариаций
                $updateDescription = $goodData['update_description'] ?? false;
                $updateShortDescription = $goodData['update_short_description'] ?? false;
                $updateSlug = $goodData['update_slug'] ?? false;
                $updatePrices = $goodData['update_prices'] ?? false;
                $selectedImageIds = $goodData['selected_image_ids'] ?? [];

                // Загружаем товар без вариаций
                $goodWithoutVariations = ShopGood::with(['images'])->findOrFail($goodId);

                // Проверяем, что товар действительно без вариаций
                if ($goodWithoutVariations->variations()->count() > 0) {
                    continue; // Пропускаем товары с вариациями
                }

                // Обрабатываем каждую вариацию
                foreach ($variationIds as $variationId) {
                    try {
                        // Проверяем, что вариация принадлежит товару с вариациями
                        $variation = ShopGoodVariation::where('id', $variationId)
                            ->where('good_id', $goodWithVariations->id)
                            ->firstOrFail();

                        // Сохраняем оригинальное название вариации для истории
                        $originalVariationName = $variation->name;

                        // Обновляем данные вариации данными из товара без вариаций
                        // НЕ переносим остатки - только основные данные и цены (по выбору)
                        $updateData = [
                            'name' => $goodWithoutVariations->name,
                            'sku' => $goodWithoutVariations->sku,
                        ];

                        // Переносим цены только если выбрана соответствующая опция
                        if ($updatePrices) {
                            $updateData['price'] = $goodWithoutVariations->price;
                            $updateData['sale_price'] = $goodWithoutVariations->sale_price;
                            $updateData['demping_price'] = $goodWithoutVariations->demping_price;
                        }

                        $variation->update($updateData);

                        $updatedVariations[] = $variation->id;
                    } catch (\Exception $e) {
                        // Продолжаем обработку других вариаций
                        continue;
                    }
                }

                // Копируем изображения товара в каждую вариацию
                // Это позволяет каждой вариации иметь свои собственные изображения
                if (! empty($variationIds)) {
                    // Получаем изображения товара - либо выбранные, либо все
                    $sourceImagesQuery = DB::table('shop_good_images')
                        ->where('good_id', $goodWithoutVariations->id)
                        ->whereNull('variation_id');

                    if (! empty($selectedImageIds)) {
                        $sourceImagesQuery->whereIn('id', $selectedImageIds);
                    }

                    $sourceImages = $sourceImagesQuery->get();

                    $imagesFound = $sourceImages->count();
                    $totalImagesCopied = 0;

                    // Копируем каждое изображение в каждую вариацию
                    foreach ($variationIds as $variationId) {
                        foreach ($sourceImages as $sourceImage) {
                            DB::table('shop_good_images')->insert([
                                'good_id' => null, // Убираем связь с товаром
                                'variation_id' => $variationId, // Привязываем к вариации
                                'file_path' => $sourceImage->file_path,
                                'alt_text' => $sourceImage->alt_text,
                                'is_main' => $sourceImage->is_main,
                                'sort_order' => $sourceImage->sort_order,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $totalImagesCopied++;
                        }
                    }

                    // Сохраняем информацию о скопированных изображениях
                    $transferredImagesInfo[] = [
                        'good_id' => $goodWithoutVariations->id,
                        'variation_ids' => $variationIds,
                        'images_found' => $imagesFound,
                        'images_copied' => $totalImagesCopied,
                        'selected_images' => ! empty($selectedImageIds),
                        'note' => ! empty($selectedImageIds)
                            ? 'Выбранные изображения скопированы в каждую вариацию'
                            : 'Все изображения скопированы в каждую вариацию',
                    ];
                }

                // Запоминаем товары для обновления полей основного товара
                if ($updateDescription) {
                    $descriptionUpdateGoodId = $goodWithoutVariations->id;
                }
                if ($updateShortDescription) {
                    $shortDescriptionUpdateGoodId = $goodWithoutVariations->id;
                }
                if ($updateSlug) {
                    $slugUpdateGoodId = $goodWithoutVariations->id;
                }
            }

            // Удаляем оригинальные изображения товара-источника (они уже скопированы в вариации)
            $goodIdsToDelete = array_column($goodsWithoutVariations, 'id');
            foreach ($goodIdsToDelete as $goodId) {
                // Удаляем изображения товара-источника, поскольку они скопированы в вариации
                DB::table('shop_good_images')
                    ->where('good_id', $goodId)
                    ->delete();
            }

            // Проверяем изображения вариаций перед удалением товаров
            $variationImagesBeforeDelete = [];
            foreach ($updatedVariations as $varId) {
                $variationImagesBeforeDelete[$varId] = DB::table('shop_good_images')
                    ->where('variation_id', $varId)
                    ->count();
            }

            // Удаляем товары без вариаций после переноса изображений
            foreach ($goodIdsToDelete as $goodId) {
                $goodToDelete = ShopGood::find($goodId);
                if ($goodToDelete) {
                    $this->logAudit($goodToDelete, 'deleted', $goodToDelete->toArray(), null);
                    $goodToDelete->delete();
                }
            }

            // Теперь обновляем поля основного товара (после удаления товаров-источников)
            $updateFields = [];
            $skippedFields = []; // Поля, которые не удалось обновить

            if ($descriptionUpdateGoodId && isset($sourceGoodsData[$descriptionUpdateGoodId])) {
                $updateFields['description'] = $sourceGoodsData[$descriptionUpdateGoodId]['description'];
            }

            if ($shortDescriptionUpdateGoodId && isset($sourceGoodsData[$shortDescriptionUpdateGoodId])) {
                $updateFields['short_description'] = $sourceGoodsData[$shortDescriptionUpdateGoodId]['short_description'];
            }

            if ($slugUpdateGoodId && isset($sourceGoodsData[$slugUpdateGoodId])) {
                $slug = $sourceGoodsData[$slugUpdateGoodId]['slug'];
                // Проверяем уникальность slug (теперь товары-источники удалены, так что slug должен быть свободен)
                $existingSlug = ShopGood::where('slug', $slug)
                    ->where('id', '!=', $goodWithVariations->id)
                    ->exists();

                if ($existingSlug) {
                    $skippedFields['slug'] = 'Slug "'.$slug.'" уже используется другим товаром';
                } else {
                    $updateFields['slug'] = $slug;
                }
            }

            // Обновляем основной товар, если есть поля для обновления
            if (! empty($updateFields)) {
                $goodWithVariations->update($updateFields);
            }

            // Финальная проверка изображений вариаций после копирования
            $variationImagesAfterCopy = [];
            foreach ($updatedVariations as $varId) {
                $variationImagesAfterCopy[$varId] = DB::table('shop_good_images')
                    ->where('variation_id', $varId)
                    ->count();
            }

            DB::commit();

            // Формируем детальную информацию об изображениях
            $imagesSummary = [];
            foreach ($transferredImagesInfo as $info) {
                $imagesSummary[] = [
                    'source_good_id' => $info['good_id'],
                    'variation_ids' => $info['variation_ids'] ?? [],
                    'images_found' => $info['images_found'],
                    'images_copied' => $info['images_copied'] ?? 0,
                    'note' => $info['note'] ?? '',
                ];

                // Добавляем информацию о финальном количестве изображений для каждой вариации
                if (! empty($info['variation_ids'])) {
                    foreach ($info['variation_ids'] as $varId) {
                        $imagesSummary[] = [
                            'variation_id' => $varId,
                            'images_after_copy' => $variationImagesAfterCopy[$varId] ?? 0,
                            'note' => 'Финальное количество изображений в вариации',
                        ];
                    }
                }
            }

            // Формируем сообщение с учетом пропущенных полей
            $message = 'Данные успешно перенесены в вариации. Обновлено вариаций: '.count($updatedVariations).'. Удалено товаров: '.count($goodIdsToDelete);

            if (! empty($skippedFields)) {
                $message .= '. Некоторые поля не удалось обновить: '.implode(', ', array_values($skippedFields));
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'updated_variations' => $updatedVariations,
                    'deleted_goods' => $goodIdsToDelete,
                    'images_transfer' => $imagesSummary,
                    'skipped_fields' => $skippedFields,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Ошибка изменения вариации: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения вариации: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Перенос медиа из вариаций в основной товар
     */
    public function transferMediaVarToMain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goods_ids' => 'required|array|min:1',
            'goods_ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodsIds = $request->input('goods_ids');
            $totalVariationsDeleted = 0;
            $totalImagesTransferred = 0;
            $processedGoods = [];

            foreach ($goodsIds as $goodId) {
                $good = ShopGood::with(['variations' => function ($query) {
                    $query->with('images');
                }])->findOrFail($goodId);

                // Проверяем, что товар имеет вариации
                if ($good->variations->isEmpty()) {
                    continue; // Пропускаем товары без вариаций
                }

                $goodImagesTransferred = 0;

                // Обрабатываем каждую вариацию
                foreach ($good->variations as $variation) {
                    // Переносим все изображения из вариации в основной товар
                    foreach ($variation->images as $image) {
                        // Меняем принадлежность изображения с вариации на товар
                        $image->update([
                            'good_id' => $good->id,
                            'variation_id' => null,
                        ]);
                        $goodImagesTransferred++;
                        $totalImagesTransferred++;
                    }

                    // Удаляем вариацию
                    $variation->delete();
                    $totalVariationsDeleted++;
                }

                $processedGoods[] = [
                    'id' => $good->id,
                    'name' => $good->name,
                    'variations_deleted' => $good->variations->count(),
                    'images_transferred' => $goodImagesTransferred,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Медиа успешно перенесено из вариаций в основной товар. Удалено вариаций: '.$totalVariationsDeleted.'. Перенесено изображений: '.$totalImagesTransferred,
                'data' => [
                    'processed_goods' => $processedGoods,
                    'total_variations_deleted' => $totalVariationsDeleted,
                    'total_images_transferred' => $totalImagesTransferred,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ошибка переноса медиа из вариаций: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка переноса медиа из вариаций: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить количество изображений в вариациях для указанных товаров
     */
    public function getVariationsImagesCount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $goodsIds = $request->input('ids');
            $result = [];

            foreach ($goodsIds as $goodId) {
                $imagesCount = DB::table('shop_good_images')
                    ->join('shop_good_variations', 'shop_good_images.variation_id', '=', 'shop_good_variations.id')
                    ->where('shop_good_variations.good_id', $goodId)
                    ->whereNull('shop_good_images.good_id') // Только изображения вариаций, не товара
                    ->count();

                $result[$goodId] = $imagesCount;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения количества изображений в вариациях: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения количества изображений в вариациях: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список уникальных поставщиков из товаров
     */
    public function getSuppliers(): JsonResponse
    {
        try {
            // Получаем поставщиков из основных товаров
            $suppliersFromGoods = ShopGood::whereNotNull('supplier')
                ->where('supplier', '!=', '')
                ->distinct()
                ->pluck('supplier')
                ->filter()
                ->toArray();

            // Получаем поставщиков из вариаций
            $suppliersFromVariations = ShopGoodVariation::whereNotNull('supplier')
                ->where('supplier', '!=', '')
                ->distinct()
                ->pluck('supplier')
                ->filter()
                ->toArray();

            // Объединяем и получаем уникальный список
            $allSuppliers = array_unique(array_merge($suppliersFromGoods, $suppliersFromVariations));

            // Сортируем по алфавиту
            sort($allSuppliers);

            return response()->json([
                'success' => true,
                'data' => array_values($allSuppliers), // array_values для переиндексации массива
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка поставщиков: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое создание вариаций из товаров
     * Преобразует несколько товаров в вариации одного главного товара
     */
    public function bulkCreateVariations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'main_good_id' => 'required|exists:shop_goods,id',
            'main_good_name' => 'nullable|string|max:255',
            'selected_attributes' => 'required|array|min:1',
            'selected_attributes.*' => 'exists:shop_variation_attributes,id',
            'goods_mapping' => 'required|array|min:1',
            'goods_mapping.*.good_id' => 'required|exists:shop_goods,id',
            'goods_mapping.*.attribute_values' => 'required|array',
            'goods_mapping.*.attribute_values.*.attribute_id' => 'nullable|exists:shop_variation_attributes,id',
            'goods_mapping.*.attribute_values.*.value_id' => 'nullable|exists:shop_variation_attribute_values,id',
            'goods_mapping.*.attribute_values.*.value' => 'nullable|string|max:255',
            'goods_mapping.*.attribute_values.*.attribute_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Дополнительная валидация: каждый attribute_value должен иметь либо attribute_id, либо attribute_name
        foreach ($request->goods_mapping as $index => $mapping) {
            foreach ($mapping['attribute_values'] as $valueIndex => $attrValue) {
                if (! isset($attrValue['attribute_id']) && ! isset($attrValue['attribute_name'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "Ошибка валидации: goods_mapping[{$index}].attribute_values[{$valueIndex}] должен содержать либо attribute_id, либо attribute_name",
                    ], 422);
                }
                if (! isset($attrValue['value_id']) && ! isset($attrValue['value'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "Ошибка валидации: goods_mapping[{$index}].attribute_values[{$valueIndex}] должен содержать либо value_id, либо value",
                    ], 422);
                }
            }
        }

        try {
            DB::beginTransaction();

            $mainGoodId = $request->main_good_id;
            $mainGoodName = $request->main_good_name;
            $selectedAttributes = $request->selected_attributes;
            $goodsMapping = $request->goods_mapping;

            // Загружаем главный товар
            $mainGood = ShopGood::with(['variations'])->findOrFail($mainGoodId);

            // Обновляем название главного товара, если указано
            if ($mainGoodName && trim($mainGoodName) !== '') {
                $mainGood->name = trim($mainGoodName);
                $mainGood->save();
            }

            // Проверяем, что все товары в списке существуют
            $goodsToConvert = array_column($goodsMapping, 'good_id');
            // Теперь главный товар может быть в списке - для него тоже создадим вариацию

            // Проверяем, что главный товар не имеет вариаций (опционально, можно разрешить)
            // if ($mainGood->variations()->count() > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Главный товар уже имеет вариации'
            //     ], 422);
            // }

            $createdVariations = 0;
            $skippedVariations = 0;
            $errors = [];

            // Получаем максимальный sort_order для вариаций главного товара
            $maxSortOrder = $mainGood->variations()->max('sort_order') ?? 0;

            // Обрабатываем каждый товар
            foreach ($goodsMapping as $mapping) {
                $sourceGoodId = $mapping['good_id'];
                $attributeValues = $mapping['attribute_values'];
                $isMainGood = ($sourceGoodId == $mainGoodId);

                // Проверяем, что все выбранные атрибуты присутствуют
                $mappingAttributeIds = [];
                foreach ($attributeValues as $attrValue) {
                    if (isset($attrValue['attribute_id'])) {
                        $mappingAttributeIds[] = $attrValue['attribute_id'];
                    } elseif (isset($attrValue['attribute_name'])) {
                        // Для временных атрибутов находим ID по имени
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attrValue['attribute_name'])
                            ->first();
                        if ($attribute) {
                            $mappingAttributeIds[] = $attribute->id;
                        }
                    }
                }
                $missingAttributes = array_diff($selectedAttributes, $mappingAttributeIds);
                if (! empty($missingAttributes)) {
                    $errors[] = "Товар ID {$sourceGoodId}: не указаны значения для всех выбранных атрибутов";

                    continue;
                }

                // Загружаем исходный товар
                $sourceGood = ShopGood::findOrFail($sourceGoodId);

                // Проверяем, нет ли уже вариации с такими же атрибутами
                $existingVariation = $this->findVariationByAttributes($mainGoodId, $attributeValues);
                if ($existingVariation) {
                    $skippedVariations++;
                    $errors[] = "Товар ID {$sourceGoodId}: вариация с такими атрибутами уже существует";
                    // Если это не главный товар, удаляем его
                    if (! $isMainGood) {
                        $sourceGood->delete();
                    }

                    continue;
                }

                // Создаем вариацию
                // Чспользуем название из goods_mapping, если передано, иначе название исходного товара
                $variationName = isset($mapping['name']) && ! empty($mapping['name'])
                    ? trim($mapping['name'])
                    : $sourceGood->name;

                $variation = \App\Models\ShopGoodVariation::create([
                    'good_id' => $mainGoodId,
                    'name' => $variationName, // Чспользуем название исходного товара из goods_mapping
                    'sku' => $sourceGood->sku,
                    'price' => $sourceGood->price,
                    'sale_price' => $sourceGood->sale_price,
                    'demping_price' => $sourceGood->demping_price,
                    'show_demping' => $sourceGood->show_demping,
                    'stock_quantity' => $sourceGood->stock_quantity,
                    'remote_stock_quantity' => $sourceGood->remote_stock_quantity,
                    'fast_remote_stock_quantity' => $sourceGood->fast_remote_stock_quantity,
                    'weight' => $sourceGood->weight,
                    'length' => $sourceGood->length,
                    'height' => $sourceGood->height,
                    'width' => $sourceGood->width,
                    'is_active' => $sourceGood->is_active,
                    'sort_order' => ++$maxSortOrder,
                ]);

                // Привязываем значения атрибутов к вариации
                foreach ($attributeValues as $attrValue) {
                    $attributeValueId = null;

                    // Если есть value_id, используем его
                    if (isset($attrValue['value_id']) && $attrValue['value_id']) {
                        $attributeValueId = $attrValue['value_id'];
                    }
                    // Если есть value (новое значение), создаем или находим значение атрибута
                    elseif (isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                        $attributeId = $attrValue['attribute_id'] ?? null;
                        $valueText = trim($attrValue['value']);

                        if ($attributeId) {
                            // Чщем существующее значение по тексту
                            $existingValue = DB::table('shop_variation_attribute_values')
                                ->where('attribute_id', $attributeId)
                                ->where('value', $valueText)
                                ->first();

                            if ($existingValue) {
                                $attributeValueId = $existingValue->id;
                            } else {
                                // Создаем новое значение атрибута
                                $attributeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                                    'attribute_id' => $attributeId,
                                    'value' => $valueText,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                    // Если есть attribute_name (для временных атрибутов из вариаций)
                    elseif (isset($attrValue['attribute_name']) && isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                        $attributeName = trim($attrValue['attribute_name']);
                        $valueText = trim($attrValue['value']);

                        // Находим атрибут по имени
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attributeName)
                            ->first();

                        if ($attribute) {
                            // Чщем существующее значение по тексту
                            $existingValue = DB::table('shop_variation_attribute_values')
                                ->where('attribute_id', $attribute->id)
                                ->where('value', $valueText)
                                ->first();

                            if ($existingValue) {
                                $attributeValueId = $existingValue->id;
                            } else {
                                // Создаем новое значение атрибута
                                $attributeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                                    'attribute_id' => $attribute->id,
                                    'value' => $valueText,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }

                    if ($attributeValueId) {
                        DB::table('shop_variation_attributes_values')->insert([
                            'variation_id' => $variation->id,
                            'attribute_value_id' => $attributeValueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Переносим изображения от исходного товара к вариации
                DB::table('shop_good_images')
                    ->where('good_id', $sourceGoodId)
                    ->whereNull('variation_id')
                    ->update([
                        'good_id' => null,
                        'variation_id' => $variation->id,
                    ]);

                // Удаляем исходный товар только если это не главный товар
                if (! $isMainGood) {
                    $sourceGood->delete();
                }

                $createdVariations++;
            }

            DB::commit();

            $message = "Успешно создано вариаций: {$createdVariations}";
            if ($skippedVariations > 0) {
                $message .= ", пропущено: {$skippedVariations}";
            }
            if (! empty($errors)) {
                $message .= '. Ошибки: '.implode('; ', array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= ' и еще '.(count($errors) - 5).' ошибок';
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'created_variations' => $createdVariations,
                    'skipped_variations' => $skippedVariations,
                    'errors' => $errors,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in bulkCreateVariations: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания вариаций: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Поиск вариации по атрибутам
     */
    private function findVariationByAttributes($goodId, $attributeValues): ?\App\Models\ShopGoodVariation
    {
        // Получаем все вариации товара
        $variations = \App\Models\ShopGoodVariation::where('good_id', $goodId)->get();

        if ($variations->isEmpty()) {
            return null;
        }

        // Сортируем значения атрибутов для сравнения
        $searchAttributeIds = [];
        $searchValueIds = [];
        foreach ($attributeValues as $attrValue) {
            if (isset($attrValue['attribute_id'])) {
                $searchAttributeIds[] = $attrValue['attribute_id'];
            } elseif (isset($attrValue['attribute_name'])) {
                // Для временных атрибутов находим ID по имени
                $attribute = DB::table('shop_variation_attributes')
                    ->where('name', $attrValue['attribute_name'])
                    ->first();
                if ($attribute) {
                    $searchAttributeIds[] = $attribute->id;
                }
            }

            if (isset($attrValue['value_id']) && $attrValue['value_id']) {
                $searchValueIds[] = $attrValue['value_id'];
            } elseif (isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                // Для новых значений нужно найти value_id по тексту
                $attributeId = null;
                if (isset($attrValue['attribute_id'])) {
                    $attributeId = $attrValue['attribute_id'];
                } elseif (isset($attrValue['attribute_name'])) {
                    $attribute = DB::table('shop_variation_attributes')
                        ->where('name', $attrValue['attribute_name'])
                        ->first();
                    if ($attribute) {
                        $attributeId = $attribute->id;
                    }
                }

                if ($attributeId) {
                    $value = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $attributeId)
                        ->where('value', trim($attrValue['value']))
                        ->first();
                    if ($value) {
                        $searchValueIds[] = $value->id;
                    }
                }
            }
        }
        sort($searchAttributeIds);
        sort($searchValueIds);

        // Проверяем каждую вариацию
        foreach ($variations as $variation) {
            $variationAttributeValues = DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->toArray();

            if (empty($variationAttributeValues)) {
                continue;
            }

            // Получаем attribute_id для каждого value_id
            $variationAttributes = DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->where('vav.variation_id', $variation->id)
                ->select('av.attribute_id', 'vav.attribute_value_id')
                ->get();

            $variationAttributeIds = $variationAttributes->pluck('attribute_id')->toArray();
            $variationValueIds = $variationAttributes->pluck('attribute_value_id')->toArray();

            sort($variationAttributeIds);
            sort($variationValueIds);

            // Сравниваем атрибуты и значения
            if ($searchAttributeIds === $variationAttributeIds && $searchValueIds === $variationValueIds) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * Проверить уникальность слага
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $slug = $request->input('slug');

        // Простая проверка существования товара со слагом (только проверка, без загрузки данных)
        $exists = ShopGood::where('slug', $slug)->exists();

        return response()->json([
            'success' => true,
            'available' => ! $exists,
            'message' => $exists ? 'Слаг уже используется' : 'Слаг доступен',
        ]);
    }

    /**
     * Слияние вариаций из нескольких товаров в один основной товар
     */
    public function mergeVariations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'main_good_id' => 'required|exists:shop_goods,id',
            'donor_good_ids' => 'required|array|min:1',
            'donor_good_ids.*' => 'exists:shop_goods,id|different:main_good_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $mainGoodId = $request->input('main_good_id');
            $donorGoodIds = $request->input('donor_good_ids');

            // Загружаем основной товар
            $mainGood = ShopGood::findOrFail($mainGoodId);

            // Загружаем товары-доноры с вариациями, изображениями и видео
            $donorGoods = ShopGood::with(['variations.images', 'variations.videos', 'variations'])->whereIn('id', $donorGoodIds)->get();

            if ($donorGoods->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товары-доноры не найдены',
                ], 404);
            }

            $totalVariationsMoved = 0;
            $totalImagesMoved = 0;
            $totalVideosMoved = 0;

            // Обрабатываем каждый товар-донор
            foreach ($donorGoods as $donorGood) {
                // Перемещаем все вариации из донора в основной товар
                $variations = $donorGood->variations;

                if ($variations->isEmpty()) {
                    // Если у товара-донора нет вариаций, просто удаляем его
                    // Сначала удаляем изображения товара
                    ShopGoodImage::where('good_id', $donorGood->id)
                        ->whereNull('variation_id')
                        ->delete();

                    // Удаляем видео товара
                    \App\Models\ShopGoodVideo::where('good_id', $donorGood->id)
                        ->whereNull('variation_id')
                        ->delete();

                    // Ч азрываем связи many-to-many перед удалением
                    $donorGood->categories()->detach();
                    $donorGood->brands()->detach();
                    $donorGood->tags()->detach();
                    $donorGood->properties()->detach();

                    // Удаляем товар-донор
                    $donorGood->delete();

                    continue;
                }

                foreach ($variations as $variation) {
                    // Обновляем good_id вариации на основной товар
                    // Поставщик (supplier) уже сохранен в поле вариации, он автоматически сохранится
                    $variation->good_id = $mainGoodId;
                    $variation->save();

                    $totalVariationsMoved++;

                    // Перемещаем изображения вариации
                    $variationImages = ShopGoodImage::where('variation_id', $variation->id)->get();
                    foreach ($variationImages as $image) {
                        // Обновляем good_id изображения на основной товар
                        $image->good_id = $mainGoodId;
                        $image->save();
                        $totalImagesMoved++;
                    }

                    // Перемещаем видео вариации (если есть)
                    $variationVideos = \App\Models\ShopGoodVideo::where('variation_id', $variation->id)->get();
                    foreach ($variationVideos as $video) {
                        $video->good_id = $mainGoodId;
                        $video->save();
                        $totalVideosMoved++;
                    }

                    // Атрибуты вариаций (shop_variation_attributes_values) привязаны только к variation_id,
                    // поэтому они автоматически останутся привязанными после обновления good_id
                }

                // Удаляем товар-донор (вариации уже перемещены, связи разорваны)
                // Сначала удаляем изображения товара (не вариаций, они уже перемещены)
                ShopGoodImage::where('good_id', $donorGood->id)
                    ->whereNull('variation_id')
                    ->delete();

                // Удаляем видео товара
                \App\Models\ShopGoodVideo::where('good_id', $donorGood->id)
                    ->whereNull('variation_id')
                    ->delete();

                // Ч азрываем связи many-to-many перед удалением
                $donorGood->categories()->detach();
                $donorGood->brands()->detach();
                $donorGood->tags()->detach();
                $donorGood->properties()->detach();

                // Удаляем товар-донор
                $donorGood->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Вариации успешно слиты',
                'data' => [
                    'variations_count' => $totalVariationsMoved,
                    'images_count' => $totalImagesMoved,
                    'videos_count' => $totalVideosMoved,
                    'deleted_goods_count' => count($donorGoodIds),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Ошибка слияния вариаций: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка слияния вариаций: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получение характеристик для удаления (только те, которые присутствуют у выбранных товаров)
     */
    public function getPropertiesForRemove(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|string', // Accept comma-separated string
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Convert comma-separated string to array of integers
            $goodIdsString = $request->input('good_ids', '');
            $goodIds = array_map('intval', explode(',', $goodIdsString));
            $goodIds = array_filter($goodIds); // Remove empty values

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходимо указать хотя бы один ID товара',
                ], 422);
            }

            // Проверяем, что все указанные ID товаров существуют
            $existingGoodIds = DB::table('shop_goods')
                ->whereIn('id', $goodIds)
                ->pluck('id')
                ->toArray();

            if (count($existingGoodIds) !== count($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некоторые указанные ID товаров не существуют',
                ], 422);
            }

            $search = $request->input('search', '');
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            // Получаем все характеристики и их значения для выбранных товаров
            $query = DB::table('shop_good_properties as gp')
                ->join('shop_properties as p', 'gp.property_id', '=', 'p.id')
                ->join('shop_property_values as pv', 'gp.shop_property_value_id', '=', 'pv.id')
                ->whereIn('gp.good_id', $goodIds)
                ->select(
                    'p.id as property_id',
                    'p.name as property_name',
                    'pv.id as property_value_id',
                    'pv.value as property_value_name',
                    DB::raw('COUNT(DISTINCT gp.good_id) as count')
                )
                ->groupBy('p.id', 'p.name', 'pv.id', 'pv.value')
                ->orderBy('p.name')
                ->orderBy('pv.value');

            // Применяем поиск если указан
            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('p.name', 'like', '%'.$search.'%')
                        ->orWhere('pv.value', 'like', '%'.$search.'%');
                });
            }

            // Получаем общее количество
            $totalCount = $query->count();

            // Применяем пагинацию
            $properties = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $properties,
                'total' => $totalCount,
                'page' => $page,
                'per_page' => $perPage,
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка при получении характеристик для удаления: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении характеристик',
            ], 500);
        }
    }

    /**
     * Массовое удаление характеристик у товаров
     */
    public function bulkRemoveProperties(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|array',
            'good_ids.*' => 'exists:shop_goods,id',
            'properties' => 'required|array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.property_value_id' => 'required|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodIds = $request->input('good_ids', []);
            $properties = $request->input('properties', []);
            $removedCount = 0;

            // Для каждого товара удаляем выбранные характеристики
            foreach ($goodIds as $goodId) {
                foreach ($properties as $property) {
                    $deleted = DB::table('shop_good_properties')
                        ->where('good_id', $goodId)
                        ->where('property_id', $property['property_id'])
                        ->where('shop_property_value_id', $property['property_value_id'])
                        ->delete();

                    if ($deleted > 0) {
                        $removedCount++;
                    }
                }
            }

            DB::commit();

            // Логируем действие для каждого товара
            foreach ($goodIds as $goodId) {
                $good = ShopGood::find($goodId);
                if ($good) {
                    $this->logAudit($good, 'bulk_remove_properties', [
                        'properties_removed' => $properties,
                        'removed_count' => $removedCount,
                    ], null);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Характеристики успешно удалены',
                'count' => $removedCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Ошибка при массовом удалении характеристик: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении характеристик',
            ], 500);
        }
    }
}
