<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopVariationAttribute;
use App\Models\ShopVariationAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShopGoodVariationsController extends Controller
{
    /**
     * Получить вариации товара
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        $variations = $good->variations()
            ->with(['attributeValues.attribute'])
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $variations
        ]);
    }

    /**
     * Получить вариацию по ID
     */
    public function show($goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::where('good_id', $goodId)
            ->with(['attributeValues.attribute', 'images', 'videos'])
            ->findOrFail($variationId);

        return response()->json([
            'success' => true,
            'data' => $variation
        ]);
    }

    /**
     * Создать новую вариацию
     */
    public function store(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'sku' => 'nullable|string|max:255|unique:shop_good_variations,sku',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'attribute_value_ids' => 'array',
            'attribute_value_ids.*' => 'exists:shop_variation_attribute_values,id'
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

            $variation = $good->variations()->create($request->only([
                'name', 'description', 'short_description', 'price', 'sale_price',
                'stock_quantity', 'width', 'height', 'depth', 'weight', 'sku',
                'is_active', 'sort_order'
            ]));

            // Привязка значений атрибутов
            if ($request->filled('attribute_value_ids')) {
                $variation->attributeValues()->attach($request->get('attribute_value_ids'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Вариация успешно создана',
                'data' => $variation->load(['attributeValues.attribute'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания вариации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить вариацию
     */
    public function update(Request $request, $goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::where('good_id', $goodId)->findOrFail($variationId);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('shop_good_variations', 'sku')->ignore($variationId)],
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'attribute_value_ids' => 'array',
            'attribute_value_ids.*' => 'exists:shop_variation_attribute_values,id'
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

            $variation->update($request->only([
                'name', 'description', 'short_description', 'price', 'sale_price',
                'stock_quantity', 'width', 'height', 'depth', 'weight', 'sku',
                'is_active', 'sort_order'
            ]));

            // Обновление значений атрибутов
            if ($request->has('attribute_value_ids')) {
                $variation->attributeValues()->sync($request->get('attribute_value_ids', []));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Вариация успешно обновлена',
                'data' => $variation->load(['attributeValues.attribute'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления вариации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить вариацию
     */
    public function destroy($goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::where('good_id', $goodId)->findOrFail($variationId);
        $variation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Вариация успешно удалена'
        ]);
    }

    /**
     * Получить атрибуты и их значения для создания вариаций
     */
    public function attributes(): JsonResponse
    {
        $attributes = ShopVariationAttribute::active()
            ->with(['values' => function ($query) {
                $query->active()->ordered();
            }])
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }
}
