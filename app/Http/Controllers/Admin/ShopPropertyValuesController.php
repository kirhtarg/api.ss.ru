<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property as ShopProperty;
use App\Models\Shop\PropertyValue as ShopPropertyValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Объединить значения характеристик
     */
    public function merge(Request $request, $propertyId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array|min:2',
                'ids.*' => 'integer|exists:shop_property_values,id',
                'new_value' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $sourceIds = $request->get('ids');
            $newValueName = trim($request->get('new_value'));

            DB::beginTransaction();

            // 1. Найти или создать целевое значение
            $targetValue = ShopPropertyValue::where('property_id', $propertyId)
                ->where('value', $newValueName)
                ->first();

            if (! $targetValue) {
                // Берем настройки первого из объединяемых значений (цвет и т.д.)
                $firstSource = ShopPropertyValue::find($sourceIds[0]);
                $targetValue = ShopPropertyValue::create([
                    'property_id' => $propertyId,
                    'value' => $newValueName,
                    'color' => $firstSource->color,
                    'sort_order' => $firstSource->sort_order,
                    'is_active' => true,
                ]);
            }

            // 2. Обновить все товары, где используются старые значения
            foreach ($sourceIds as $sourceId) {
                if ($sourceId == $targetValue->id) {
                    continue;
                }

                // Находим все записи в shop_good_properties с этим sourceId
                $relations = DB::table('shop_good_properties')
                    ->where('property_id', $propertyId)
                    ->where('shop_property_value_id', $sourceId)
                    ->get();

                foreach ($relations as $rel) {
                    // Проверяем, есть ли у этого же товара (good_id или variation_id) уже targetValue
                    $query = DB::table('shop_good_properties')
                        ->where('property_id', $propertyId)
                        ->where('shop_property_value_id', $targetValue->id);

                    if ($rel->good_id) {
                        $query->where('good_id', $rel->good_id);
                    } else {
                        $query->whereNull('good_id');
                    }

                    $exists = $query->exists();

                    if (! $exists) {
                        // Если нет - обновляем на новое
                        DB::table('shop_good_properties')
                            ->where('id', $rel->id)
                            ->update(['shop_property_value_id' => $targetValue->id]);
                    } else {
                        // Если есть - просто удаляем старую связь
                        DB::table('shop_good_properties')
                            ->where('id', $rel->id)
                            ->delete();
                    }
                }

                // 3. Удалить старое значение (если это не целевое)
                ShopPropertyValue::where('id', $sourceId)->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Значения успешно объединены',
                'target_value' => [
                    'id' => $targetValue->id,
                    'value' => $targetValue->value,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ошибка объединения значений характеристик: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка объединения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить статистику перед объединением
     */
    public function mergeStats(Request $request, $propertyId): JsonResponse
    {
        try {
            $ids = $request->get('ids', []);
            if (empty($ids)) {
                return response()->json(['success' => true, 'count' => 0]);
            }

            $count = DB::table('shop_good_properties')
                ->where('property_id', $propertyId)
                ->whereIn('shop_property_value_id', $ids)
                ->distinct()
                ->count('good_id');

            return response()->json([
                'success' => true,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения статистики объединения: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: '.$e->getMessage(),
            ], 500);
        }
    }
}
