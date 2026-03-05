<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property as ShopProperty;
use App\Models\Shop\PropertyValue as ShopPropertyValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShopPropertyValuesController extends Controller
{
    /**
     * Получить значения свойства
     */
    public function index(Request $request, $propertyId): JsonResponse
    {
        $property = ShopProperty::findOrFail($propertyId);

        $values = $property->values()
            ->ordered()
            ->get();

        // Добавляем счетчики товаров для каждого значения
        $values = $values->map(function ($value) {
            $goodsCount = DB::table('shop_good_properties')
                ->where('property_id', $value->property_id)
                ->where('shop_property_value_id', $value->id)
                ->whereNotNull('good_id')
                ->distinct('good_id')
                ->count('good_id');

            $value->goods_count = $goodsCount;

            return $value;
        });

        return response()->json([
            'success' => true,
            'data' => $values->pluck('value')->toArray(),
            'values' => $values->toArray(),
        ]);
    }

    /**
     * Проверить существование значения свойства
     */
    public function check(Request $request, $propertyId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $exists = ShopPropertyValue::where('property_id', $propertyId)
            ->where('value', $request->get('value'))
            ->exists();

        return response()->json([
            'success' => true,
            'exists' => $exists,
        ]);
    }

    /**
     * Создать значение свойства
     */
    public function store(Request $request, $propertyId): JsonResponse
    {
        $property = ShopProperty::findOrFail($propertyId);

        $validator = Validator::make($request->all(), [
            'value' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $value = ShopPropertyValue::create([
            'property_id' => $propertyId,
            'value' => $request->get('value'),
            'color' => $request->get('color'),
            'sort_order' => $request->get('sort_order', 0),
            'is_active' => $request->get('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Значение свойства успешно создано',
            'data' => $value,
            'value' => $value->value, // Добавляем значение для совместимости с фронтендом
        ], 201);
    }

    /**
     * Обновить значение свойства
     */
    public function update(Request $request, $propertyId, $valueId): JsonResponse
    {
        $value = ShopPropertyValue::where('property_id', $propertyId)
            ->findOrFail($valueId);

        $validator = Validator::make($request->all(), [
            'value' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $value->update($request->only(['value', 'color', 'sort_order', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'Значение свойства успешно обновлено',
            'data' => $value,
        ]);
    }

    /**
     * Получить количество товаров с данным значением характеристики
     */
    public function getGoodsCount($propertyId, $valueId): JsonResponse
    {
        try {
            $goodsCount = DB::table('shop_good_properties')
                ->where('property_id', $propertyId)
                ->where('shop_property_value_id', $valueId)
                ->whereNotNull('good_id')
                ->distinct('good_id')
                ->count('good_id');

            return response()->json([
                'success' => true,
                'count' => $goodsCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения количества товаров: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить значение свойства
     */
    public function destroy($propertyId, $valueId): JsonResponse
    {
        $value = ShopPropertyValue::where('property_id', $propertyId)
            ->findOrFail($valueId);

        // Проверяем, есть ли товары с этим значением характеристики
        $goodsCount = DB::table('shop_good_properties')
            ->where('property_id', $propertyId)
            ->where('shop_property_value_id', $valueId)
            ->whereNotNull('good_id')
            ->distinct('good_id')
            ->count('good_id');

        if ($goodsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить значение характеристики',
                'has_goods' => true,
                'goods_count' => $goodsCount,
                'error' => "У значения характеристики есть привязанные товары ({$goodsCount}). При удалении значение будет удалено у всех этих товаров.",
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Удаляем связи с товарами (если есть)
            DB::table('shop_good_properties')
                ->where('property_id', $propertyId)
                ->where('shop_property_value_id', $valueId)
                ->delete();

            // Удаляем значение
            $value->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Значение свойства успешно удалено',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления значения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Принудительно удалить значение свойства (даже если есть привязанные товары)
     */
    public function forceDestroy($propertyId, $valueId): JsonResponse
    {
        $value = ShopPropertyValue::where('property_id', $propertyId)
            ->findOrFail($valueId);

        try {
            DB::beginTransaction();

            // Удаляем связи с товарами
            DB::table('shop_good_properties')
                ->where('property_id', $propertyId)
                ->where('shop_property_value_id', $valueId)
                ->delete();

            // Удаляем значение
            $value->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Значение свойства успешно удалено',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления значения: '.$e->getMessage(),
            ], 500);
        }
    }
}
