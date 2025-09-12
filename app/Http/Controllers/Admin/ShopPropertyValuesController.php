<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property as ShopProperty;
use App\Models\Shop\PropertyValue as ShopPropertyValue;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        return response()->json([
            'success' => true,
            'values' => $values
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
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $value = ShopPropertyValue::create([
            'property_id' => $propertyId,
            'value' => $request->get('value'),
            'color' => $request->get('color'),
            'sort_order' => $request->get('sort_order', 0),
            'is_active' => $request->get('is_active', true)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Значение свойства успешно создано',
            'data' => $value
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
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $value->update($request->only(['value', 'color', 'sort_order', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'Значение свойства успешно обновлено',
            'data' => $value
        ]);
    }

    /**
     * Удалить значение свойства
     */
    public function destroy($propertyId, $valueId): JsonResponse
    {
        $value = ShopPropertyValue::where('property_id', $propertyId)
            ->findOrFail($valueId);

        $value->delete();

        return response()->json([
            'success' => true,
            'message' => 'Значение свойства успешно удалено'
        ]);
    }
}