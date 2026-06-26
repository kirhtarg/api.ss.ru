<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopVariationAttributesController extends Controller
{
    /**
     * Получить атрибуты вариаций с учетом фильтров
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Получаем базовый запрос товаров с учетом фильтров
            $goodsQuery = $this->buildGoodsQuery($request);
            $goodsIds = $goodsQuery->pluck('id');

            // Получаем ВСЕ атрибуты и их значения, используемые в товарах (даже если их нет в текущей выборке)
            // Но подсчитываем количество только для текущей выборки

            // 1. Сначала получаем список всех используемых атрибутов и значений
            $allAttributesQuery = DB::table('shop_variation_attributes as a')
                ->join('shop_variation_attribute_values as av', 'av.attribute_id', '=', 'a.id')
                ->select(
                    'a.id as attribute_id',
                    'a.name as attribute_name',
                    // 'a.type as attribute_type', // Убрано, так как поле type отсутствует
                    'av.id as value_id',
                    'av.value as value_value',
                    'av.color as value_color'
                )
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('shop_variation_attributes_values as vav')
                        ->join('shop_good_variations as v', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_goods as g', 'g.id', '=', 'v.good_id')
                        ->whereRaw('vav.attribute_value_id = av.id')
                        ->where('v.is_active', true)
                        ->where('g.is_active', true);
                });

            $allAttributesData = $allAttributesQuery
                ->orderBy('a.name')
                ->orderBy('av.value')
                ->get();

            // 2. Получаем counts для текущей выборки товаров (если есть товары)
            $counts = [];
            if ($goodsIds->isNotEmpty()) {
                $countsData = DB::table('shop_variation_attributes_values as vav')
                    ->join('shop_good_variations as v', 'v.id', '=', 'vav.variation_id')
                    ->whereIn('v.good_id', $goodsIds)
                    ->where('v.is_active', true)
                    ->select(
                        'vav.attribute_value_id',
                        DB::raw('COUNT(DISTINCT v.good_id) as count')
                    )
                    ->groupBy('vav.attribute_value_id')
                    ->get();

                foreach ($countsData as $row) {
                    $counts[$row->attribute_value_id] = $row->count;
                }
            }

            // Группируем результаты по атрибутам
            $result = [];
            foreach ($allAttributesData as $row) {
                if (! isset($result[$row->attribute_id])) {
                    $result[$row->attribute_id] = [
                        'id' => $row->attribute_id,
                        'name' => $row->attribute_name,
                        // 'type' => $row->attribute_type,
                        'values' => [],
                    ];
                }

                $result[$row->attribute_id]['values'][] = [
                    'id' => $row->value_id,
                    'value' => $row->value_value,
                    'color' => $row->value_color,
                    'count' => $counts[$row->value_id] ?? 0,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => array_values($result),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки атрибутов вариаций: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Построить запрос товаров с учетом фильтров (копия из ShopPropertiesController)
     */
    private function buildGoodsQuery(Request $request)
    {
        $query = ShopGood::where('is_active', true);

        // Фильтр по категориям
        if ($request->filled('categories')) {
            $categoryIds = is_array($request->categories)
                ? $request->categories
                : explode(',', $request->categories);
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('shop_categories.id', $categoryIds);
            });
        }

        // Фильтр по одной категории
        if ($request->filled('category_id')) {
            $categoryId = $request->get('category_id');
            $query->whereHas('categories', function ($q) use ($categoryId) {
                $q->where('shop_categories.id', $categoryId);
            });
        }

        // Фильтр по брендам
        if ($request->filled('brands')) {
            $brandIds = is_array($request->brands)
                ? $request->brands
                : explode(',', $request->brands);
            $query->whereHas('brands', function ($q) use ($brandIds) {
                $q->whereIn('shop_brands.id', $brandIds);
            });
        }

        // Фильтр по цене
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Фильтр по поиску
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $this->applyPropertyFilters($query, $request);

        // Фильтрация по атрибутам вариаций (для корректного подсчета при выборе нескольких фильтров)
        if ($request->has('attributes')) {
            $attributes = $request->input('attributes');
            if (is_array($attributes) && ! empty($attributes)) {
                foreach ($attributes as $attributeId => $values) {
                    if (is_array($values) && ! empty($values)) {
                        $query->whereHas('variations', function ($q) use ($attributeId, $values) {
                            $q->where('is_active', true)
                                ->whereHas('attributeValues', function ($avQ) use ($attributeId, $values) {
                                    $avQ->where('attribute_id', $attributeId)
                                        ->whereIn('value', $values);
                                });
                        });
                    }
                }
            }
        }

        $this->applyStockFilter($query, $request->get('stock_filter'));

        return $query;
    }

    private function applyPropertyFilters($query, Request $request): void
    {
        $properties = $request->input('properties', []);
        if (! is_array($properties) || empty($properties)) {
            return;
        }

        foreach ($properties as $propertyId => $values) {
            $values = is_array($values) ? array_filter($values) : array_filter([$values]);
            if (empty($values)) {
                continue;
            }

            $valueIds = \App\Models\Shop\PropertyValue::where('property_id', $propertyId)
                ->whereIn('value', $values)
                ->pluck('id')
                ->toArray();

            if (empty($valueIds)) {
                $query->whereRaw('1 = 0');
                continue;
            }

            $query->whereHas('properties', function ($q) use ($propertyId, $valueIds) {
                $q->where('shop_properties.id', $propertyId)
                    ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
            });
        }
    }

    private function applyStockFilter($query, $stockFilter): void
    {
        if (! $stockFilter || $stockFilter === 'all') {
            return;
        }

        if ($stockFilter === 'preorder') {
            $query->where(function ($q) {
                $q->where('is_preorder', true)
                    ->orWhere('is_preorder', 1);
            });
            return;
        }

        if ($stockFilter === 'in_stock' || $stockFilter === 'with_stock') {
            $query->where(function ($mainQuery) {
                $mainQuery->where(function ($noVariationsQuery) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where(function ($stockQuery) {
                            $this->applyStockPresentCondition($stockQuery);
                        });
                })->orWhereHas('variations', function ($variationQuery) {
                    $variationQuery->where('is_active', true)
                        ->where(function ($stockQuery) {
                            $this->applyStockPresentCondition($stockQuery);
                        });
                });
            });
            return;
        }

        if ($stockFilter === 'out_of_stock') {
            $query->whereDoesntHave('variations', function ($variationQuery) {
                $variationQuery->where('is_active', true)
                    ->where(function ($stockQuery) {
                        $this->applyStockPresentCondition($stockQuery);
                    });
            })->where(function ($stockQuery) {
                $this->applyStockEmptyCondition($stockQuery);
            });
        }
    }

    private function applyStockPresentCondition($query): void
    {
        $query->where('stock_quantity', '>', 0)
            ->orWhere(function ($remoteQuery) {
                $remoteQuery->whereNotNull('remote_stock_quantity')
                    ->where('remote_stock_quantity', '!=', '0')
                    ->where('remote_stock_quantity', '!=', '')
                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
            })
            ->orWhere(function ($fastRemoteQuery) {
                $fastRemoteQuery->whereNotNull('fast_remote_stock_quantity')
                    ->where('fast_remote_stock_quantity', '!=', '0')
                    ->where('fast_remote_stock_quantity', '!=', '')
                    ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
            });
    }

    private function applyStockEmptyCondition($query): void
    {
        $query->where(function ($stockQuery) {
            $stockQuery->whereNull('stock_quantity')
                ->orWhere('stock_quantity', '<=', 0);
        })->where(function ($remoteQuery) {
            $remoteQuery->whereNull('remote_stock_quantity')
                ->orWhere('remote_stock_quantity', '=', '0')
                ->orWhere('remote_stock_quantity', '=', '')
                ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
        })->where(function ($fastRemoteQuery) {
            $fastRemoteQuery->whereNull('fast_remote_stock_quantity')
                ->orWhere('fast_remote_stock_quantity', '=', '0')
                ->orWhere('fast_remote_stock_quantity', '=', '')
                ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
        });
    }
}
