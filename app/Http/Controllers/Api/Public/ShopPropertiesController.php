<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopPropertiesController extends Controller
{
    /**
     * Получить свойства с учетом фильтров
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Получаем базовый запрос товаров с учетом фильтров
            $goodsQuery = $this->buildGoodsQuery($request);
            
            // Получаем ID товаров, которые соответствуют фильтрам
            $goodsIds = $goodsQuery->pluck('id');
            
            if ($goodsIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
            
            // Получаем свойства, которые есть у отфильтрованных товаров
            $properties = Property::where('is_active', true)
                ->whereHas('goodProperties', function ($query) use ($goodsIds) {
                    $query->whereIn('good_id', $goodsIds);
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(function ($property) use ($goodsIds) {
                    // Получаем уникальные значения для этого свойства из связанной таблицы shop_property_values
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

                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                        'slug' => $property->slug,
                        'property_type' => $property->property_type,
                        'values' => $values,
                        'count' => count($values) // Добавляем счетчик значений
                    ];
                })
                ->filter(function ($property) {
                    // Фильтруем только те свойства, у которых есть значения (count > 0)
                    return $property['count'] > 0;
                })
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
     */
    public function getValues(Request $request, Property $property): JsonResponse
    {
        try {
            // Получаем базовый запрос товаров с учетом фильтров
            $goodsQuery = $this->buildGoodsQuery($request);
            
            // Получаем ID товаров, которые соответствуют фильтрам
            $goodsIds = $goodsQuery->pluck('id');
            
            if ($goodsIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
            
            // Получаем уникальные значения для этого свойства из связанной таблицы shop_property_values
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
            
            return response()->json([
                'success' => true,
                'data' => $values
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
            Log::info('Filtering properties by category_id: ' . $categoryId);
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
            Log::info('Filtering properties by brand_id: ' . $brandId);
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
