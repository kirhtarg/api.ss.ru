<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopPropertiesController extends Controller
{
    /**
     * Получить свойства с учетом фильтров
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Проверяем, нужно ли возвращать только свойства, привязанные к категории через shop_category_property
            $onlyCategoryProperties = $request->filled('only_category_properties') 
                && ($request->get('only_category_properties') == '1' || $request->get('only_category_properties') === true);
            
            // Получаем базовый запрос товаров с учетом фильтров
            $goodsQuery = $this->buildGoodsQuery($request);
            $goodsIds = $goodsQuery->pluck('id');
            
            // Если запрашиваются свойства категории, не проверяем наличие товаров
            // Возвращаем все свойства категории, даже если у них нет товаров
            if (!$onlyCategoryProperties && $goodsIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
            
            // Строим запрос свойств
            // Если запрашиваются свойства категории, не фильтруем по наличию товаров
            // Возвращаем все свойства категории, даже если у них нет товаров
            if ($onlyCategoryProperties && $request->filled('categories')) {
                $categoryIds = is_array($request->categories) 
                    ? $request->categories 
                    : explode(',', $request->categories);
                
                // Получаем только свойства, привязанные к категории через shop_category_property
                $propertyIds = DB::table('shop_category_property')
                    ->whereIn('category_id', $categoryIds)
                    ->pluck('property_id')
                    ->unique()
                    ->toArray();
                
                if (empty($propertyIds)) {
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
                
                // Фильтруем только привязанные свойства (без фильтрации по наличию товаров)
                $propertiesQuery = Property::where('is_active', true)
                    ->whereIn('id', $propertyIds);
            } else {
                // Для обычного запроса фильтруем по наличию товаров
                $propertiesQuery = Property::where('is_active', true)
                    ->whereHas('goodProperties', function ($query) use ($goodsIds) {
                        $query->whereIn('good_id', $goodsIds);
                    });
            }
            
            // Получаем свойства
            $properties = $propertiesQuery
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(function ($property) use ($goodsIds, $onlyCategoryProperties) {
                    // Получаем уникальные значения для этого свойства из связанной таблицы shop_property_values
                    $values = [];
                    
                    if ($goodsIds->isNotEmpty()) {
                        // Если есть товары, получаем значения из товаров
                        $values = DB::table('shop_good_properties')
                            ->join('shop_property_values', 'shop_property_values.id', '=', 'shop_good_properties.shop_property_value_id')
                            ->where('shop_good_properties.property_id', $property->id)
                            ->whereIn('shop_good_properties.good_id', $goodsIds)
                            ->whereNotNull('shop_good_properties.shop_property_value_id')
                            ->whereNotNull('shop_property_values.value')
                            ->where('shop_property_values.value', '<>', '')
                            ->distinct()
                            ->orderBy('shop_property_values.value')
                            ->pluck('shop_property_values.value')
                            ->toArray();
                    }
                    
                    // Если запрашиваются свойства категории, получаем ВСЕ возможные значения свойства
                    // даже если у них нет товаров (count = 0)
                    if ($onlyCategoryProperties && empty($values)) {
                        $values = DB::table('shop_property_values')
                            ->where('property_id', $property->id)
                            ->whereNotNull('value')
                            ->where('value', '<>', '')
                            ->orderBy('value')
                            ->pluck('value')
                            ->toArray();
                    }

                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                        'slug' => $property->slug,
                        'property_type' => $property->property_type,
                        'values' => $values,
                        'count' => count($values) // Добавляем счетчик значений
                    ];
                })
                // УБРАНА фильтрация по count > 0 - теперь возвращаем все свойства, даже с 0 товаров
                // Это нужно для отображения всех характеристик категории, даже если у них нет товаров
                ->values(); // Сбрасываем ключи массива
            
            return response()->json([
                'success' => true,
                'data' => $properties
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки свойств: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Получить значения конкретного свойства с учетом фильтров
     * Возвращает все значения свойства для категории, даже если нет товаров с такими значениями
     * С счетчиками товаров для каждого значения
     */
    public function getValues(Request $request, Property $property): JsonResponse
    {
        try {
            // Получаем базовый запрос товаров с учетом фильтров
            $goodsQuery = $this->buildGoodsQuery($request);
            
            // Получаем ID товаров, которые соответствуют фильтрам
            $goodsIds = $goodsQuery->pluck('id');
            
            // Проверяем, нужно ли возвращать все значения для категории
            $onlyCategoryProperties = $request->filled('only_category_properties') 
                && ($request->get('only_category_properties') == '1' || $request->get('only_category_properties') === true);
            
            // Если запрашиваются значения для категории, получаем все значения свойства
            if ($onlyCategoryProperties && $request->filled('categories')) {
                $categoryIds = is_array($request->categories) 
                    ? $request->categories 
                    : explode(',', $request->categories);
                
                // Получаем все значения свойства, которые используются в товарах этой категории
                // или могут быть использованы (из shop_property_values)
                $allValues = DB::table('shop_property_values')
                    ->where('property_id', $property->id)
                    ->whereNotNull('value')
                    ->where('value', '<>', '')
                    ->orderBy('value')
                    ->get();
                
                // Для каждого значения считаем количество товаров с учетом фильтров
                $valuesWithCounts = $allValues->map(function ($propertyValue) use ($property, $goodsIds) {
                    $count = 0;
                    
                    if ($goodsIds->isNotEmpty()) {
                        $count = DB::table('shop_good_properties')
                            ->where('property_id', $property->id)
                            ->where('shop_property_value_id', $propertyValue->id)
                            ->whereIn('good_id', $goodsIds)
                            ->distinct('good_id')
                            ->count('good_id');
                    }
                    
                    return [
                        'value' => $propertyValue->value,
                        'count' => $count
                    ];
                })->toArray();
                
                return response()->json([
                    'success' => true,
                    'data' => $valuesWithCounts
                ]);
            }
            
            // Старая логика: возвращаем только значения, которые есть в товарах
            if ($goodsIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
            
            // Получаем уникальные значения для этого свойства из связанной таблицы shop_property_values
            // с подсчетом количества товаров для каждого значения
            $valuesWithCounts = DB::table('shop_good_properties')
                ->join('shop_property_values', 'shop_property_values.id', '=', 'shop_good_properties.shop_property_value_id')
                ->where('shop_good_properties.property_id', $property->id)
                ->whereIn('shop_good_properties.good_id', $goodsIds)
                ->whereNotNull('shop_good_properties.shop_property_value_id')
                ->whereNotNull('shop_property_values.value')
                ->where('shop_property_values.value', '<>', '')
                ->select('shop_property_values.value', DB::raw('COUNT(DISTINCT shop_good_properties.good_id) as count'))
                ->groupBy('shop_property_values.value')
                ->orderBy('shop_property_values.value')
                ->get()
                ->map(function ($item) {
                    return [
                        'value' => $item->value,
                        'count' => $item->count
                    ];
                })
                ->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $valuesWithCounts
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки значений свойства: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Построить запрос товаров с учетом фильтров
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
        
        // Фильтр по одной категории (для совместимости)
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
        
        // Фильтр по одному бренду (для совместимости)
        if ($request->filled('brand_id')) {
            $brandId = $request->get('brand_id');
            $query->whereHas('brands', function ($q) use ($brandId) {
                $q->where('shop_brands.id', $brandId);
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
        
        return $query;
    }
}
