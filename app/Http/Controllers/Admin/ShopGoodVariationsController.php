<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property as ShopProperty;
use App\Models\ShopGood;
use App\Models\ShopGoodProperty;
use App\Models\ShopGoodVariation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShopGoodVariationsController extends Controller
{
    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $variations = $good->variations()
            ->ordered()
            ->get();

        // РџРѕРґС‚СЏРіРёРІР°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№ РёР· РЅРѕРІРѕР№ СЃС…РµРјС‹
        $variationIds = $variations->pluck('id')->all();
        if (! empty($variationIds)) {
            $rows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                ->whereIn('vav.variation_id', $variationIds)
                ->select(
                    'vav.variation_id',
                    'a.id as attribute_id', 'a.name as attribute_name',
                    'av.id as value_id', 'av.value as value_value'
                )
                ->orderBy('a.name')
                ->get();

            $byVariation = [];
            foreach ($rows as $r) {
                $byVariation[$r->variation_id][] = [
                    'attribute' => ['id' => (int) $r->attribute_id, 'name' => $r->attribute_name],
                    'value' => ['id' => (int) $r->value_id, 'value' => $r->value_value],
                ];
            }

            foreach ($variations as $v) {
                $v->attributes = $byVariation[$v->id] ?? [];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $variations,
        ]);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РјРЅРѕР¶РµСЃС‚РІР° РІР°СЂРёР°С†РёР№ (РјР°СЃСЃРѕРІР°СЏ Р·Р°РіСЂСѓР·РєР°)
     */
    public function getBulkAttributes(Request $request): JsonResponse
    {
        try {
            $variationIds = $request->input('variation_ids', []);

            if (empty($variationIds) || ! is_array($variationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµ СѓРєР°Р·Р°РЅС‹ ID РІР°СЂРёР°С†РёР№',
                ], 400);
            }

            // РћРіСЂР°РЅРёС‡РёРІР°РµРј СЂР°Р·РјРµСЂ Р±Р°С‚С‡Р°
            $variationIds = array_slice($variationIds, 0, 5000);
            $variationIds = array_map('intval', $variationIds);
            $variationIds = array_filter($variationIds, function ($id) {
                return $id > 0;
            });

            if (empty($variationIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РІСЃРµС… РІР°СЂРёР°С†РёР№ РѕРґРЅРёРј Р·Р°РїСЂРѕСЃРѕРј
            $rows = DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                ->whereIn('vav.variation_id', $variationIds)
                ->select(
                    'vav.variation_id',
                    'a.id as attribute_id',
                    'a.name as attribute_name',
                    'av.id as value_id',
                    'av.value as value_value'
                )
                ->orderBy('a.name')
                ->get();

            // Р“СЂСѓРїРїРёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РїРѕ РІР°СЂРёР°С†РёСЏРј
            $byVariation = [];
            foreach ($rows as $r) {
                if (! isset($byVariation[$r->variation_id])) {
                    $byVariation[$r->variation_id] = [];
                }
                $byVariation[$r->variation_id][] = [
                    'id' => (int) $r->attribute_id,
                    'name' => $r->attribute_name,
                    'value' => $r->value_value,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $byVariation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЎРїСЂР°РІРѕС‡РЅРёРє Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёР№
     */
    public function listAttributes(): JsonResponse
    {
        $attributes = \Illuminate\Support\Facades\DB::table('shop_variation_attributes as a')
            ->leftJoin('shop_variation_attribute_values as av', 'av.attribute_id', '=', 'a.id')
            ->leftJoin('shop_variation_attributes_values as vav', 'vav.attribute_value_id', '=', 'av.id')
            ->groupBy('a.id', 'a.name', 'a.slug')
            ->orderBy('a.name')
            ->get([
                'a.id', 'a.name', 'a.slug',
                \Illuminate\Support\Facades\DB::raw('COUNT(vav.id) as usage_count'),
            ]);

        // РћРїСЂРµРґРµР»СЏРµРј С‚РёРї Р°С‚СЂРёР±СѓС‚Р° РЅР° РѕСЃРЅРѕРІРµ slug
        foreach ($attributes as $attr) {
            $slug = strtolower($attr->slug ?? '');
            if ($slug === 'color') {
                $attr->type = 'color';
            } elseif ($slug === 'select' || strpos($slug, 'select') !== false) {
                $attr->type = 'select';
            } else {
                $attr->type = '';
            }
        }

        return response()->json([
            'success' => true,
            'data' => $attributes,
        ]);
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ Р°С‚СЂРёР±СѓС‚ (РїРµСЂРµРёРјРµРЅРѕРІР°С‚СЊ)
     */
    public function updateAttribute(Request $request, $goodId, $attributeId): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'РќР°Р·РІР°РЅРёРµ Р°С‚СЂРёР±СѓС‚Р° РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ'], 422);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('name', $name)
            ->where('id', '!=', (int) $attributeId)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'РђС‚СЂРёР±СѓС‚ СЃ С‚Р°РєРёРј РЅР°Р·РІР°РЅРёРµРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚'], 422);
        }

        // РџРµСЂРµРіРµРЅРµСЂРёСЂСѓРµРј slug, РµСЃР»Рё РјРµРЅСЏРµРј РёРјСЏ
        $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-'.uniqid());
        $slug = $baseSlug;
        $i = 2;
        while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->where('id', '!=', (int) $attributeId)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int) $attributeId)
            ->update([
                'name' => $name,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => (int) $attributeId, 'name' => $name, 'slug' => $slug],
            'message' => 'РђС‚СЂРёР±СѓС‚ РѕР±РЅРѕРІР»РµРЅ',
        ]);
    }

    /**
     * РЈРґР°Р»РёС‚СЊ Р°С‚СЂРёР±СѓС‚ (РµСЃР»Рё РЅРµС‚ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ)
     */
    public function deleteAttribute(Request $request, $goodId, $attributeId): JsonResponse
    {
        // РџСЂРѕРІРµСЂСЏРµРј РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ
        $inUse = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->where('av.attribute_id', (int) $attributeId)
            ->exists();
        if ($inUse) {
            return response()->json(['success' => false, 'message' => 'РђС‚СЂРёР±СѓС‚ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РІ РІР°СЂРёР°С†РёСЏС… Рё РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ СѓРґР°Р»РµРЅ'], 409);
        }

        // РЈРґР°Р»СЏРµРј Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚Р° (РЅР° РІСЃСЏРєРёР№ СЃР»СѓС‡Р°Р№)
        \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int) $attributeId)
            ->delete();
        // РЈРґР°Р»СЏРµРј СЃР°Рј Р°С‚СЂРёР±СѓС‚
        \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int) $attributeId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'РђС‚СЂРёР±СѓС‚ СѓРґР°Р»РµРЅ',
        ]);
    }

    /**
     * РЎРїРёСЃРѕРє Р·РЅР°С‡РµРЅРёР№ РґР»СЏ Р°С‚СЂРёР±СѓС‚Р°
     */
    public function getAttributeValues($goodId, $attributeId): JsonResponse
    {
        // РЎРЅР°С‡Р°Р»Р° РїСЂРѕРІРµСЂРёРј, СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё Р°С‚СЂРёР±СѓС‚
        $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int) $attributeId)
            ->first();

        if (! $attribute) {
            return response()->json([
                'success' => false,
                'message' => 'РђС‚СЂРёР±СѓС‚ РЅРµ РЅР°Р№РґРµРЅ',
            ], 404);
        }

        $values = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int) $attributeId)
            ->select('id', 'value', 'color', 'image_path')
            ->orderBy('value')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $values,
        ]);
    }

    public function updateGlobalAttribute(Request $request, $attributeId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $attribute = DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->first();
            if (!$attribute) {
                return response()->json(['success' => false, 'message' => 'Атрибут не найден'], 404);
            }

            $name = trim($request->input('name'));
            
            // Проверка на дубликат имени
            $exists = DB::table('shop_variation_attributes')
                ->where('name', $name)
                ->where('id', '!=', (int) $attributeId)
                ->exists();
                
            if ($exists) {
                return response()->json(['success' => false, 'message' => 'Атрибут с таким названием уже существует'], 422);
            }

            // Перегенерация slug
            $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-' . uniqid());
            $slug = $baseSlug;
            $i = 2;
            while (DB::table('shop_variation_attributes')->where('slug', $slug)->where('id', '!=', (int) $attributeId)->exists()) {
                $slug = $baseSlug . '-' . $i;
                $i++;
            }

            DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->update([
                'name' => $name,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['id' => (int) $attributeId, 'name' => $name, 'slug' => $slug],
                'message' => 'Атрибут успешно обновлен'
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating global attribute: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при обновлении атрибута: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteGlobalAttribute($attributeId): JsonResponse
    {
        try {
            $attribute = DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->first();
            if (!$attribute) {
                return response()->json(['success' => false, 'message' => 'Атрибут не найден'], 404);
            }

            DB::beginTransaction();

            // Удаляем связи значений атрибута с вариациями
            $valueIds = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->pluck('id');
                
            if ($valueIds->isNotEmpty()) {
                DB::table('shop_variation_attributes_values')
                    ->whereIn('attribute_value_id', $valueIds)
                    ->delete();
            }

            // Удаляем значения атрибута
            DB::table('shop_variation_attribute_values')->where('attribute_id', (int) $attributeId)->delete();

            // Удаляем сам атрибут
            DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Атрибут и все его значения успешно удалены'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error deleting global attribute: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при удалении атрибута: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getGlobalAttributeValues($attributeId): JsonResponse
    {
        try {
            $attribute = DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->first();
            if (!$attribute) {
                return response()->json(['success' => false, 'message' => 'Атрибут не найден'], 404);
            }

            $values = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->orderBy('value')
                ->get();

            // Подсчитаем количество вариаций, использующих каждое значение
            $goodsCount = DB::table('shop_variation_attributes_values')
                ->whereIn('attribute_value_id', $values->pluck('id'))
                ->select('attribute_value_id', DB::raw('COUNT(variation_id) as goods_count'))
                ->groupBy('attribute_value_id')
                ->get()
                ->keyBy('attribute_value_id');

            foreach ($values as $val) {
                $val->goods_count = $goodsCount->has($val->id) ? $goodsCount->get($val->id)->goods_count : 0;
            }

            return response()->json([
                'success' => true,
                'data' => $values
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching global attribute values: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при загрузке значений атрибута: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createGlobalAttributeValue(Request $request, $attributeId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'value' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $newValue = trim($request->input('value'));

            $exists = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->where('value', $newValue)
                ->exists();

            if ($exists) {
                return response()->json(['success' => false, 'message' => 'Такое значение уже существует'], 422);
            }

            $id = DB::table('shop_variation_attribute_values')->insertGetId([
                'attribute_id' => (int) $attributeId,
                'value' => $newValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $id,
                    'value' => $newValue
                ]
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error creating global attribute value: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при создании значения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateGlobalAttributeValue(Request $request, $attributeId, $valueId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'value' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $value = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->where('id', (int) $valueId)
                ->first();

            if (!$value) {
                return response()->json(['success' => false, 'message' => 'Значение не найдено'], 404);
            }
            
            $newValue = trim($request->input('value'));
            
            $exists = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->where('value', $newValue)
                ->where('id', '!=', (int) $valueId)
                ->exists();
                
            if ($exists) {
                return response()->json(['success' => false, 'message' => 'Такое значение уже существует'], 422);
            }

            DB::table('shop_variation_attribute_values')
                ->where('id', (int) $valueId)
                ->update([
                    'value' => $newValue,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'data' => ['id' => (int) $valueId, 'attribute_id' => (int) $attributeId, 'value' => $newValue],
                'message' => 'Значение успешно обновлено'
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating global attribute value: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при обновлении значения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteGlobalAttributeValue($attributeId, $valueId): JsonResponse
    {
        try {
            $value = DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->where('id', (int) $valueId)
                ->first();

            if (!$value) {
                return response()->json(['success' => false, 'message' => 'Значение не найдено'], 404);
            }

            DB::beginTransaction();

            // Удаляем связи значения с вариациями
            DB::table('shop_variation_attributes_values')->where('attribute_value_id', (int) $valueId)->delete();

            // Удаляем само значение
            DB::table('shop_variation_attribute_values')->where('id', (int) $valueId)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Значение успешно удалено'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error deleting global attribute value: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при удалении значения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bulkDeleteGlobalAttributeValues(Request $request, $attributeId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'value_ids' => 'required|array',
                'value_ids.*' => 'integer|exists:shop_variation_attribute_values,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $valueIds = $request->input('value_ids');

            DB::beginTransaction();

            // Удаляем связи с вариациями
            DB::table('shop_variation_attributes_values')->whereIn('attribute_value_id', $valueIds)->delete();
            
            // Удаляем сами значения
            DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->whereIn('id', $valueIds)
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($valueIds) . ' значений успешно удалено'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error bulk deleting global attribute values: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при массовом удалении значений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ Р°С‚СЂРёР±СѓС‚ РІР°СЂРёР°С†РёР№ (РіР»РѕР±Р°Р»СЊРЅРѕ, Р±РµР· РїСЂРёРІСЏР·РєРё Рє С‚РѕРІР°СЂСѓ)
     */
    public function createAttributeGlobal(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'РќР°Р·РІР°РЅРёРµ Р°С‚СЂРёР±СѓС‚Р° РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ'], 422);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('name', $name)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'РђС‚СЂРёР±СѓС‚ СЃ С‚Р°РєРёРј РЅР°Р·РІР°РЅРёРµРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚'], 422);
        }

        // РћРїСЂРµРґРµР»СЏРµРј slug РЅР° РѕСЃРЅРѕРІРµ С‚РёРїР° РёР»Рё РЅР°Р·РІР°РЅРёСЏ
        $type = $request->input('type');
        $slug = '';

        if ($type === 'color') {
            // Р”Р»СЏ С‚РёРїР° color РёСЃРїРѕР»СЊР·СѓРµРј slug 'color'
            $slug = 'color';
            // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug 'color'
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = 'color-'.$i;
                $i++;
            }
        } elseif ($type === 'select') {
            // Р”Р»СЏ С‚РёРїР° select РґРѕР±Р°РІР»СЏРµРј РїСЂРµС„РёРєСЃ 'select-' Рє slug РёР· РЅР°Р·РІР°РЅРёСЏ
            $baseSlug = 'select-'.(\Illuminate\Support\Str::slug($name) ?: ('attr-'.uniqid()));
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$i;
                $i++;
            }
        } else {
            // Р”Р»СЏ РѕСЃС‚Р°Р»СЊРЅС‹С… С‚РёРїРѕРІ РіРµРЅРµСЂРёСЂСѓРµРј slug РёР· РЅР°Р·РІР°РЅРёСЏ
            $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-'.uniqid());
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$i;
                $i++;
            }
        }

        $id = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'name' => $name, 'slug' => $slug, 'type' => $type],
            'message' => 'РђС‚СЂРёР±СѓС‚ СЃРѕР·РґР°РЅ',
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ Р°С‚СЂРёР±СѓС‚ РІР°СЂРёР°С†РёР№
     */
    public function createAttribute(Request $request, $goodId): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'РќР°Р·РІР°РЅРёРµ Р°С‚СЂРёР±СѓС‚Р° РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ'], 422);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('name', $name)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'РђС‚СЂРёР±СѓС‚ СЃ С‚Р°РєРёРј РЅР°Р·РІР°РЅРёРµРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚'], 422);
        }

        // РћРїСЂРµРґРµР»СЏРµРј slug РЅР° РѕСЃРЅРѕРІРµ С‚РёРїР° РёР»Рё РЅР°Р·РІР°РЅРёСЏ
        $type = $request->input('type');
        $slug = '';

        if ($type === 'color') {
            // Р”Р»СЏ С‚РёРїР° color РёСЃРїРѕР»СЊР·СѓРµРј slug 'color'
            $slug = 'color';
            // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug 'color'
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = 'color-'.$i;
                $i++;
            }
        } elseif ($type === 'select') {
            // Р”Р»СЏ С‚РёРїР° select РґРѕР±Р°РІР»СЏРµРј РїСЂРµС„РёРєСЃ 'select-' Рє slug РёР· РЅР°Р·РІР°РЅРёСЏ
            $baseSlug = 'select-'.(\Illuminate\Support\Str::slug($name) ?: ('attr-'.uniqid()));
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$i;
                $i++;
            }
        } else {
            // Р”Р»СЏ РѕСЃС‚Р°Р»СЊРЅС‹С… С‚РёРїРѕРІ РіРµРЅРµСЂРёСЂСѓРµРј slug РёР· РЅР°Р·РІР°РЅРёСЏ
            $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-'.uniqid());
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$i;
                $i++;
            }
        }

        $id = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'name' => $name, 'slug' => $slug, 'type' => $type],
            'message' => 'РђС‚СЂРёР±СѓС‚ СЃРѕР·РґР°РЅ',
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
     */
    public function createAttributeValue(Request $request, $goodId, $attributeId): JsonResponse
    {
        $value = trim((string) $request->input('value'));
        if ($value === '') {
            return response()->json(['success' => false, 'message' => 'Р—РЅР°С‡РµРЅРёРµ РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ'], 422);
        }

        $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('id', (int) $attributeId)->first();
        if (! $attribute) {
            return response()->json(['success' => false, 'message' => 'РђС‚СЂРёР±СѓС‚ РЅРµ РЅР°Р№РґРµРЅ'], 404);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int) $attributeId)
            ->where('value', $value)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'РўР°РєРѕРµ Р·РЅР°С‡РµРЅРёРµ СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚'], 422);
        }

        $id = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')->insertGetId([
            'attribute_id' => (int) $attributeId,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'attribute_id' => (int) $attributeId, 'value' => $value],
            'message' => 'Р—РЅР°С‡РµРЅРёРµ РґРѕР±Р°РІР»РµРЅРѕ',
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РІР°СЂРёР°С†РёСЋ
     */
    public function store(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'attributes' => 'required|array',
            'attributes.*.attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'attributes.*.value_id' => 'required|integer|exists:shop_variation_attribute_values,id',
            'properties' => 'nullable|array',
            'properties.*.property_id' => 'nullable|exists:shop_properties,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Р˜СЃРїРѕР»СЊР·СѓРµРј Р°СЂС‚РёРєСѓР» СЂРѕРґРёС‚РµР»СЊСЃРєРѕРіРѕ С‚РѕРІР°СЂР° Р±РµР· РґРѕР±Р°РІР»РµРЅРёР№
            $variationSku = $request->get('sku') ?: $good->sku;
            $variationName = $request->get('name') ?: $good->name;

            // РџРѕРґРіРѕС‚Р°РІР»РёРІР°РµРј РґР°РЅРЅС‹Рµ РґР»СЏ СЃРѕР·РґР°РЅРёСЏ РІР°СЂРёР°С†РёРё
            $variationData = [
                'good_id' => $goodId,
                'name' => $variationName,
                'sku' => $variationSku,
                'price' => $request->get('price'),
                'sale_price' => $request->get('sale_price'),
                'stock_quantity' => $request->get('stock_quantity'),
                'weight' => $request->get('weight') ?: $good->weight,
                'length' => $request->get('length') ?: $good->length,
                'height' => $request->get('height') ?: $good->height,
                'width' => $request->get('width') ?: $good->width,
                'is_active' => true,
                'sort_order' => $good->variations()->max('sort_order') + 1,
            ];

            // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј remote_stock_quantity
            if ($request->has('remote_stock_quantity')) {
                $remoteStockValue = $request->get('remote_stock_quantity');
                $variationData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
            }

            // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј fast_remote_stock_quantity
            if ($request->has('fast_remote_stock_quantity')) {
                $fastRemoteStockValue = $request->get('fast_remote_stock_quantity');
                $variationData['fast_remote_stock_quantity'] = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
            }

            // РџСЂРѕРІРµСЂСЏРµРј РЅР° РґСѓР±Р»РёРєР°С‚С‹ РІР°СЂРёР°С†РёР№ РїРѕ РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
            if ($request->has('attributes') && is_array($request->get('attributes'))) {
                $requestAttributeValueIds = array_map(function ($attr) {
                    return (int) $attr['value_id'];
                }, $request->get('attributes'));
                sort($requestAttributeValueIds);

                // РџРѕР»СѓС‡Р°РµРј РІСЃРµ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
                $existingVariations = ShopGoodVariation::where('good_id', $goodId)->get();

                foreach ($existingVariations as $existingVariation) {
                    // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ РІР°СЂРёР°С†РёРё
                    $existingAttributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                        ->where('variation_id', $existingVariation->id)
                        ->pluck('attribute_value_id')
                        ->map(function ($id) {
                            return (int) $id;
                        })
                        ->toArray();
                    sort($existingAttributeValueIds);

                    // РЎСЂР°РІРЅРёРІР°РµРј РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
                    if ($requestAttributeValueIds === $existingAttributeValueIds) {
                        // Р¤РѕСЂРјРёСЂСѓРµРј СЃС‚СЂРѕРєСѓ РєРѕРјР±РёРЅР°С†РёРё РґР»СЏ РѕС‚РѕР±СЂР°Р¶РµРЅРёСЏ
                        $combinationParts = [];
                        foreach ($request->get('attributes') as $attr) {
                            $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
                                ->where('id', $attr['attribute_id'])
                                ->first();
                            $value = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                                ->where('id', $attr['value_id'])
                                ->first();
                            if ($attribute && $value) {
                                $combinationParts[] = $attribute->name.': '.$value->value;
                            }
                        }
                        $combination = implode(', ', $combinationParts);

                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'РўР°РєР°СЏ РІР°СЂРёР°С†РёСЏ СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚: '.$combination,
                        ], 422);
                    }
                }
            }

            // РЎРѕР·РґР°РµРј РІР°СЂРёР°С†РёСЋ
            $variation = ShopGoodVariation::create($variationData);

            // РЎРѕР·РґР°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё (РЅРѕРІР°СЏ СЃС…РµРјР°)
            if ($request->has('attributes') && is_array($request->get('attributes'))) {
                foreach ($request->get('attributes') as $attrData) {
                    if (isset($attrData['attribute_id']) && isset($attrData['value_id'])) {
                        DB::table('shop_variation_attributes_values')->insert([
                            'variation_id' => $variation->id,
                            'attribute_value_id' => (int) $attrData['value_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // РЎРѕР·РґР°РµРј СЃРІРѕР№СЃС‚РІР° РІР°СЂРёР°С†РёРё (СЃС‚Р°СЂР°СЏ СЃС…РµРјР°, РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё)
            if ($request->has('properties') && is_array($request->get('properties'))) {
                foreach ($request->get('properties') as $propertyData) {
                    if (isset($propertyData['property_id'])) {
                        ShopGoodProperty::create([
                            'variation_id' => $variation->id,
                            'property_id' => $propertyData['property_id'],
                        ]);
                    }
                }
            }

            DB::commit();

            // Р’ РЅРѕРІРѕР№ СЃС…РµРјРµ СЃРІРѕР№СЃС‚РІР° РЅРµ РїРѕРґРіСЂСѓР¶Р°РµРј

            return response()->json([
                'success' => true,
                'message' => 'Р’Р°СЂРёР°С†РёСЏ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅР°',
                'data' => $variation,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РІР°СЂРёР°С†РёРё: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РјРёРєСЃ РІР°СЂРёР°С†РёР№
     */
    public function storeBulk(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        // РќРѕРІР°СЏ СЃС…РµРјР°: РїСЂРёРЅРёРјР°РµРј attribute_groups [{ attribute_id, values[] }]
        $validator = Validator::make($request->all(), [
            'attribute_groups' => 'required|array|min:1',
            'attribute_groups.*.attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'attribute_groups.*.values' => 'required|array|min:1',
            'attribute_groups.*.values.*' => 'required|string|max:255',
            'sku_prefix' => 'nullable|string|max:255',
            'base_price' => 'nullable|numeric|min:0',
            'base_quantity' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // РЎРіРµРЅРµСЂРёСЂСѓРµРј РІСЃРµ РєРѕРјР±РёРЅР°С†РёРё Р·РЅР°С‡РµРЅРёР№ РїРѕ РіСЂСѓРїРїР°Рј
            $groups = $request->get('attribute_groups', []);

            $combinations = [[]];
            foreach ($groups as $group) {
                $next = [];
                foreach ($combinations as $combo) {
                    foreach ($group['values'] as $val) {
                        $next[] = array_merge($combo, [[
                            'attribute_id' => (int) $group['attribute_id'],
                            'value' => trim((string) $val),
                        ]]);
                    }
                }
                $combinations = $next;
            }

            $createdIds = [];
            $nextSortOrder = (int) $good->variations()->max('sort_order') + 1;
            $skuPrefix = $request->get('sku_prefix');
            $basePrice = $request->get('base_price');
            $baseQty = (int) ($request->get('base_quantity', 0));
            $idx = 0;

            foreach ($combinations as $combo) {
                $idx++;
                $variation = ShopGoodVariation::create([
                    'good_id' => $goodId,
                    'name' => $good->name,
                    'sku' => $skuPrefix ? ($skuPrefix.'-'.$idx) : $good->sku,
                    'price' => $basePrice ?? $good->price,
                    'sale_price' => null,
                    'weight' => $good->weight,
                    'length' => $good->length,
                    'height' => $good->height,
                    'width' => $good->width,
                    'stock_quantity' => $baseQty,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                ]);

                // РџСЂРёРІСЏР·С‹РІР°РµРј Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ Рє РІР°СЂРёР°С†РёРё
                foreach ($combo as $pair) {
                    $valRow = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $pair['attribute_id'])
                        ->where('value', $pair['value'])
                        ->first();
                    if (! $valRow) {
                        $valId = DB::table('shop_variation_attribute_values')->insertGetId([
                            'attribute_id' => $pair['attribute_id'],
                            'value' => $pair['value'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $valId = $valRow->id;
                    }

                    DB::table('shop_variation_attributes_values')->insert([
                        'variation_id' => $variation->id,
                        'attribute_value_id' => $valId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $createdIds[] = $variation->id;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Р’Р°СЂРёР°С†РёРё СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅС‹',
                'data' => $createdIds,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РІР°СЂРёР°С†РёСЋ
     */
    public function update(Request $request, $goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::where('good_id', $goodId)->findOrFail($variationId);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        // РџРѕРґРіРѕС‚Р°РІР»РёРІР°РµРј РґР°РЅРЅС‹Рµ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
        $updateData = $request->only([
            'name', 'sku', 'price', 'sale_price', 'stock_quantity', 'weight',
            'length', 'height', 'width', 'is_active',
        ]);

        // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј remote_stock_quantity - РІСЃРµРіРґР° РѕР±РЅРѕРІР»СЏРµРј, РґР°Р¶Рµ РµСЃР»Рё null
        $allRequestData = $request->all();
        if (isset($allRequestData['remote_stock_quantity'])) {
            $remoteStockValue = $allRequestData['remote_stock_quantity'];
            $updateData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
        }

        // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј fast_remote_stock_quantity - РІСЃРµРіРґР° РѕР±РЅРѕРІР»СЏРµРј, РґР°Р¶Рµ РµСЃР»Рё null
        if (isset($allRequestData['fast_remote_stock_quantity'])) {
            $fastRemoteStockValue = $allRequestData['fast_remote_stock_quantity'];
            $updateData['fast_remote_stock_quantity'] = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
        }

        $variation->update($updateData);

        // Р’ РЅРѕРІРѕР№ СЃС…РµРјРµ СЃРІРѕР№СЃС‚РІР° РЅРµ РїРѕРґРіСЂСѓР¶Р°РµРј

        return response()->json([
            'success' => true,
            'message' => 'Р’Р°СЂРёР°С†РёСЏ СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅР°',
            'data' => $variation,
        ]);
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё
     */
    public function updateAttributes(Request $request, $goodId, $variationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attributes' => 'required|array',
            'attributes.*.attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'attributes.*.value_id' => 'required|integer|exists:shop_variation_attribute_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $variation = ShopGoodVariation::where('good_id', $goodId)->findOrFail($variationId);
            $attributes = $request->get('attributes');

            foreach ($attributes as $attrData) {
                $attributeId = (int) $attrData['attribute_id'];
                $valueId = (int) $attrData['value_id'];

                // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ Р·РЅР°С‡РµРЅРёРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ Р°С‚СЂРёР±СѓС‚Сѓ
                $valueCheck = DB::table('shop_variation_attribute_values')
                    ->where('id', $valueId)
                    ->where('attribute_id', $attributeId)
                    ->exists();

                if (! $valueCheck) {
                    throw new \Exception("Р—РЅР°С‡РµРЅРёРµ ID {$valueId} РЅРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ Р°С‚СЂРёР±СѓС‚Сѓ ID {$attributeId}");
                }

                // Р˜С‰РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ СЃРІСЏР·СЊ РґР»СЏ СЌС‚РѕРіРѕ Р°С‚СЂРёР±СѓС‚Р°
                // РќР°Рј РЅСѓР¶РЅРѕ РЅР°Р№С‚Рё Р·Р°РїРёСЃСЊ РІ shop_variation_attributes_values, РєРѕС‚РѕСЂР°СЏ СЃСЃС‹Р»Р°РµС‚СЃСЏ РЅР° Р·РЅР°С‡РµРЅРёРµ,
                // РєРѕС‚РѕСЂРѕРµ РІ СЃРІРѕСЋ РѕС‡РµСЂРµРґСЊ СЃСЃС‹Р»Р°РµС‚СЃСЏ РЅР° СЌС‚РѕС‚ Р°С‚СЂРёР±СѓС‚.

                // РџРѕР»СѓС‡Р°РµРј С‚РµРєСѓС‰РёРµ Р·РЅР°С‡РµРЅРёСЏ РІР°СЂРёР°С†РёРё
                $currentValues = DB::table('shop_variation_attributes_values as vav')
                    ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                    ->where('vav.variation_id', $variation->id)
                    ->where('av.attribute_id', $attributeId)
                    ->select('vav.id', 'vav.attribute_value_id')
                    ->first();

                if ($currentValues) {
                    // Р•СЃР»Рё СЃРІСЏР·СЊ РµСЃС‚СЊ, РѕР±РЅРѕРІР»СЏРµРј Рµ‘
                    if ($currentValues->attribute_value_id != $valueId) {
                        DB::table('shop_variation_attributes_values')
                            ->where('id', $currentValues->id)
                            ->update([
                                'attribute_value_id' => $valueId,
                                'updated_at' => now(),
                            ]);
                    }
                } else {
                    // Р•СЃР»Рё СЃРІСЏР·Рё РЅРµС‚, СЃРѕР·РґР°РµРј РЅРѕРІСѓСЋ
                    DB::table('shop_variation_attributes_values')->insert([
                        'variation_id' => $variation->id,
                        'attribute_value_id' => $valueId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РђС‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё РѕР±РЅРѕРІР»РµРЅС‹',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЈРґР°Р»РёС‚СЊ РІР°СЂРёР°С†РёСЋ
     */
    public function destroy($goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::find($variationId);
        if (! $variation) {
            return response()->json([
                'success' => false,
                'message' => 'Р’Р°СЂРёР°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°',
            ], 404);
        }
        if ((int) $variation->good_id !== (int) $goodId) {
            return response()->json([
                'success' => false,
                'message' => 'Р’Р°СЂРёР°С†РёСЏ РЅРµ РѕС‚РЅРѕСЃРёС‚СЃСЏ Рє СѓРєР°Р·Р°РЅРЅРѕРјСѓ С‚РѕРІР°СЂСѓ',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // РЎРІРѕР№СЃС‚РІР° РІР°СЂРёР°С†РёРё РІ СЃС‚Р°СЂРѕР№ СЃС…РµРјРµ РѕС‚СЃСѓС‚СЃС‚РІСѓСЋС‚; Р°С‚СЂРёР±СѓС‚С‹ С…СЂР°РЅСЏС‚СЃСЏ РІ РґСЂСѓРіРѕР№ С‚Р°Р±Р»РёС†Рµ
            \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->delete();

            // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёСЋ
            $variation->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Р’Р°СЂРёР°С†РёСЏ СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅР°',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СѓРґР°Р»РµРЅРёСЏ РІР°СЂРёР°С†РёРё: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Склонировать вариацию
     *
     * @param Request $request
     * @param int $goodId
     * @param int $variationId
     * @return JsonResponse
     */
    public function clone(Request $request, $goodId, $variationId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        $originalVariation = ShopGoodVariation::where('good_id', $goodId)->findOrFail($variationId);

        try {
            \DB::beginTransaction();

            // 1. Клонируем саму вариацию
            $newVariation = $originalVariation->replicate(['sort_order']);
            
            // Если есть артикул, добавляем суффикс, чтобы избежать дубликатов
            if ($originalVariation->sku) {
                $newVariation->sku = $originalVariation->sku . '-copy-' . time();
            }
            
            $newVariation->sort_order = $good->variations()->max('sort_order') + 1;
            $newVariation->save();

            // 2. Копируем атрибуты (новая схема)
            $attributes = \DB::table('shop_variation_attributes_values')
                ->where('variation_id', $originalVariation->id)
                ->get();
            
            foreach ($attributes as $attr) {
                \DB::table('shop_variation_attributes_values')->insert([
                    'variation_id' => $newVariation->id,
                    'attribute_value_id' => $attr->attribute_value_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. Копируем изображения
            $sourceImages = \App\Models\ShopGoodImage::where('variation_id', $originalVariation->id)
                ->ordered()
                ->get();

            if ($sourceImages->isNotEmpty()) {
                $frontendPublicPath = frontend_public_path();
                
                foreach ($sourceImages as $sourceImage) {
                    try {
                        $sourcePath = $frontendPublicPath . '/' . $sourceImage->file_path;

                        if (file_exists($sourcePath)) {
                            // Генерируем новое имя файла
                            $pathInfo = pathinfo($sourceImage->file_path);
                            $extension = $pathInfo['extension'] ?? 'jpg';
                            $newFileName = 'variation_' . $newVariation->id . '_' . uniqid() . '.' . $extension;
                            $newRelativePath = ($pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] : 'images') . '/' . $newFileName;
                            $newFullPath = $frontendPublicPath . '/' . $newRelativePath;

                            // Убедимся, что директория существует
                            $directory = dirname($newFullPath);
                            if (!file_exists($directory)) {
                                mkdir($directory, 0755, true);
                            }

                            // Копируем файл
                            if (copy($sourcePath, $newFullPath)) {
                                \App\Models\ShopGoodImage::create([
                                    'good_id' => $sourceImage->good_id,
                                    'variation_id' => $newVariation->id,
                                    'file_path' => $newRelativePath,
                                    'alt_text' => $sourceImage->alt_text,
                                    'is_main' => $sourceImage->is_main,
                                    'sort_order' => $sourceImage->sort_order,
                                ]);
                            }
                        }
                    } catch (\Exception $imgEx) {
                        \Log::warning("Failed to copy image during variation clone", [
                            'variation_id' => $newVariation->id,
                            'image_id' => $sourceImage->id,
                            'error' => $imgEx->getMessage()
                        ]);
                    }
                }
            }

            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Вариация успешно склонирована',
                'data' => $newVariation,
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error cloning variation: ' . $e->getMessage(), [
                'good_id' => $goodId,
                'variation_id' => $variationId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка клонирования вариации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџСЂРѕРІРµСЂРёС‚СЊ РґСѓР±Р»РёСЂРѕРІР°РЅРёРµ РєРѕРјР±РёРЅР°С†РёРё СЃРІРѕР№СЃС‚РІ
     */
    public function checkDuplicate(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'properties' => 'required|array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            // 'properties.*.value' => 'required|string|max:255' // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $properties = $request->get('properties', []);

            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР° (СЃРІРѕР№СЃС‚РІР° Р±РѕР»СЊС€Рµ РЅРµ РіСЂСѓР·РёРј РёР· shop_good_properties)
            $existingVariations = ShopGoodVariation::where('good_id', $goodId)
                ->get();

            // РџСЂРѕРІРµСЂСЏРµРј РєР°Р¶РґСѓСЋ СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ РІР°СЂРёР°С†РёСЋ
            foreach ($existingVariations as $variation) {
                // $variationProperties = $variation->properties->pluck('value', 'property_id')->toArray(); // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ
                // $requestProperties = collect($properties)->pluck('value', 'property_id')->toArray(); // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ

                // РЎСЂР°РІРЅРёРІР°РµРј РєРѕРјР±РёРЅР°С†РёРё
                // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ - Р±СѓРґРµС‚ РёСЃРїСЂР°РІР»РµРЅРѕ РІ СЃР»РµРґСѓСЋС‰РµРј С€Р°РіРµ
                /*
                if ($this->comparePropertyCombinations($variationProperties, $requestProperties)) {
                    // Р¤РѕСЂРјРёСЂСѓРµРј СЃС‚СЂРѕРєСѓ РєРѕРјР±РёРЅР°С†РёРё РґР»СЏ РѕС‚РѕР±СЂР°Р¶РµРЅРёСЏ
                    $combinationParts = [];
                    foreach ($properties as $property) {
                        $propertyModel = ShopProperty::find($property['property_id']);
                        $combinationParts[] = $propertyModel->name . ': ' . $property['value'];
                    }
                    $combination = implode(', ', $combinationParts);

                    return response()->json([
                        'success' => true,
                        'is_duplicate' => true,
                        'combination' => $combination
                    ]);
                }
                */
            }

            return response()->json([
                'success' => true,
                'is_duplicate' => false,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРѕРІРµСЂРєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р”РѕР±Р°РІРёС‚СЊ Р°С‚СЂРёР±СѓС‚ РєРѕ РІСЃРµРј РІР°СЂРёР°С†РёСЏРј С‚РѕРІР°СЂР°
     */
    public function addAttributeToAll(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'value_id' => 'required|integer|exists:shop_variation_attribute_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $good = ShopGood::findOrFail($goodId);
            $attributeId = (int) $request->get('attribute_id');
            $valueId = (int) $request->get('value_id');

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ Р·РЅР°С‡РµРЅРёРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ Р°С‚СЂРёР±СѓС‚Сѓ
            $value = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                ->where('id', $valueId)
                ->where('attribute_id', $attributeId)
                ->first();

            if (! $value) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р—РЅР°С‡РµРЅРёРµ РЅРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ РІС‹Р±СЂР°РЅРЅРѕРјСѓ Р°С‚СЂРёР±СѓС‚Сѓ',
                ], 422);
            }

            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РЈ С‚РѕРІР°СЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№',
                ], 422);
            }

            DB::beginTransaction();

            $addedCount = 0;
            foreach ($variations as $variation) {
                // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ РґРѕР±Р°РІР»РµРЅ Р»Рё СѓР¶Рµ СЌС‚РѕС‚ Р°С‚СЂРёР±СѓС‚ Рє РІР°СЂРёР°С†РёРё
                $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                    ->where('variation_id', $variation->id)
                    ->where('attribute_value_id', $valueId)
                    ->exists();

                if (! $exists) {
                    // РџСЂРѕРІРµСЂСЏРµРј, РЅРµС‚ Р»Рё Сѓ РІР°СЂРёР°С†РёРё РґСЂСѓРіРѕРіРѕ Р·РЅР°С‡РµРЅРёСЏ СЌС‚РѕРіРѕ Р°С‚СЂРёР±СѓС‚Р°
                    $otherValue = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->where('vav.variation_id', $variation->id)
                        ->where('av.attribute_id', $attributeId)
                        ->first();

                    if ($otherValue) {
                        // Р—Р°РјРµРЅСЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ
                        \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                            ->where('variation_id', $variation->id)
                            ->where('attribute_value_id', $otherValue->id)
                            ->update(['attribute_value_id' => $valueId]);
                    } else {
                        // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ
                        \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')->insert([
                            'variation_id' => $variation->id,
                            'attribute_value_id' => $valueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $addedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "РђС‚СЂРёР±СѓС‚ СѓСЃРїРµС€РЅРѕ РґРѕР±Р°РІР»РµРЅ Рє {$addedCount} РІР°СЂРёР°С†РёСЏРј",
                'data' => ['added_count' => $addedCount],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РґРѕР±Р°РІР»РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚Р°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р”РѕР±Р°РІРёС‚СЊ СЃРІРѕР№СЃС‚РІРѕ РєРѕ РІСЃРµРј РІР°СЂРёР°С†РёСЏРј С‚РѕРІР°СЂР°
     */
    public function addProperty(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'property_id' => 'required|exists:shop_properties,id',
            'values' => 'required|array',
            'values.*' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $propertyId = $request->get('property_id');
            $values = $request->get('values', []);

            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РЈ С‚РѕРІР°СЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№',
                ], 400);
            }

            // Р”РѕР±Р°РІР»СЏРµРј СЃРІРѕР№СЃС‚РІРѕ Рє РєР°Р¶РґРѕР№ РІР°СЂРёР°С†РёРё
            foreach ($variations as $variation) {
                if (isset($values[$variation->id])) {
                    ShopGoodProperty::create([
                        'variation_id' => $variation->id,
                        'property_id' => $propertyId,
                        'value' => $values[$variation->id],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'РЎРІРѕР№СЃС‚РІРѕ СѓСЃРїРµС€РЅРѕ РґРѕР±Р°РІР»РµРЅРѕ РєРѕ РІСЃРµРј РІР°СЂРёР°С†РёСЏРј',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РґРѕР±Р°РІР»РµРЅРёСЏ СЃРІРѕР№СЃС‚РІР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЈРґР°Р»РёС‚СЊ Р°С‚СЂРёР±СѓС‚ РёР· РІСЃРµС… РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂР°
     */
    public function removeProperty(Request $request, $goodId): JsonResponse
    {
        // РџРѕРґРґРµСЂР¶РёРІР°РµРј РѕР±Р° РІР°СЂРёР°РЅС‚Р° РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё
        $attributeId = $request->get('attribute_id') ?: $request->get('property_id');

        $validator = Validator::make(['attribute_id' => $attributeId], [
            'attribute_id' => 'required|exists:shop_variation_attributes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РЈ С‚РѕРІР°СЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№',
                ], 400);
            }

            $variationIds = $variations->pluck('id')->toArray();

            // РЈРґР°Р»СЏРµРј Р°С‚СЂРёР±СѓС‚ РёР· РІСЃРµС… РІР°СЂРёР°С†РёР№ (РЅРѕРІР°СЏ СЃС…РµРјР°)
            // РќР°С…РѕРґРёРј РІСЃРµ Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚Р°
            $attributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int) $attributeId)
                ->pluck('id')
                ->toArray();

            if (! empty($attributeValueIds)) {
                // РЈРґР°Р»СЏРµРј СЃРІСЏР·Рё РІР°СЂРёР°С†РёР№ СЃ СЌС‚РёРјРё Р·РЅР°С‡РµРЅРёСЏРјРё
                $deletedCount = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                    ->whereIn('variation_id', $variationIds)
                    ->whereIn('attribute_value_id', $attributeValueIds)
                    ->delete();
            } else {
                $deletedCount = 0;
            }

            // РџСЂРѕРІРµСЂСЏРµРј РЅР° РґСѓР±Р»РёРєР°С‚С‹ РїРѕСЃР»Рµ СѓРґР°Р»РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚Р°
            $duplicates = $this->findDuplicateVariations($goodId);

            $responseData = [
                'success' => true,
                'message' => "РђС‚СЂРёР±СѓС‚ СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅ РёР· {$deletedCount} РІР°СЂРёР°С†РёР№",
                'duplicates' => $duplicates,
            ];

            if (! empty($duplicates)) {
                $responseData['has_duplicates'] = true;
                $responseData['duplicate_count'] = count($duplicates);
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СѓРґР°Р»РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚Р°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р˜Р·РјРµРЅРёС‚СЊ РїРѕСЂСЏРґРѕРє РІР°СЂРёР°С†РёР№
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'variations' => 'required|array',
            'variations.*.id' => 'required|exists:shop_good_variations,id',
            'variations.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->get('variations') as $variationData) {
            ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationData['id'])
                ->update(['sort_order' => $variationData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'РџРѕСЂСЏРґРѕРє РІР°СЂРёР°С†РёР№ РѕР±РЅРѕРІР»РµРЅ',
        ]);
    }

    /**
     * Р“РµРЅРµСЂРёСЂРѕРІР°С‚СЊ РІСЃРµ РІРѕР·РјРѕР¶РЅС‹Рµ РєРѕРјР±РёРЅР°С†РёРё СЃРІРѕР№СЃС‚РІ
     */
    private function generateCombinations($properties)
    {
        if (empty($properties)) {
            return [];
        }

        $combinations = [[]];

        // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ - Р±СѓРґРµС‚ РёСЃРїСЂР°РІР»РµРЅРѕ РІ СЃР»РµРґСѓСЋС‰РµРј С€Р°РіРµ
        /*
        foreach ($properties as $property) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($property['values'] as $value) {
                    $newCombinations[] = array_merge($combination, [
                        [
                            'property_id' => $property['property_id'],
                            'value' => $value
                        ]
                    ]);
                }
            }
            $combinations = $newCombinations;
        }
        */

        return $combinations;
    }

    /**
     * РЎСЂР°РІРЅРёС‚СЊ РєРѕРјР±РёРЅР°С†РёРё СЃРІРѕР№СЃС‚РІ
     */
    private function comparePropertyCombinations($existing, $requested)
    {
        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РєРѕР»РёС‡РµСЃС‚РІРѕ СЃРІРѕР№СЃС‚РІ СЃРѕРІРїР°РґР°РµС‚
        if (count($existing) !== count($requested)) {
            return false;
        }

        // РќРѕСЂРјР°Р»РёР·СѓРµРј Р·РЅР°С‡РµРЅРёСЏ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ (РїСЂРёРІРѕРґРёРј Рє РЅРёР¶РЅРµРјСѓ СЂРµРіРёСЃС‚СЂСѓ Рё СѓР±РёСЂР°РµРј РїСЂРѕР±РµР»С‹)
        $normalizedExisting = [];
        foreach ($existing as $propertyId => $value) {
            $normalizedExisting[$propertyId] = mb_strtolower(trim($value), 'UTF-8');
        }

        $normalizedRequested = [];
        foreach ($requested as $propertyId => $value) {
            $normalizedRequested[$propertyId] = mb_strtolower(trim($value), 'UTF-8');
        }

        // РџСЂРѕРІРµСЂСЏРµРј РєР°Р¶РґРѕРµ СЃРІРѕР№СЃС‚РІРѕ
        foreach ($normalizedRequested as $propertyId => $value) {
            if (! isset($normalizedExisting[$propertyId]) ||
                $normalizedExisting[$propertyId] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * РќР°Р№С‚Рё РґСѓР±Р»РёРєР°С‚С‹ РІР°СЂРёР°С†РёР№ РїРѕ РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
     */
    private function findDuplicateVariations($goodId): array
    {
        $variations = ShopGoodVariation::where('good_id', $goodId)->get();

        if ($variations->isEmpty()) {
            return [];
        }

        // Р“СЂСѓРїРїРёСЂСѓРµРј РІР°СЂРёР°С†РёРё РїРѕ РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
        $variationsByAttributes = [];

        foreach ($variations as $variation) {
            // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё
            $attributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            sort($attributeValueIds);
            $key = implode(',', $attributeValueIds);

            if (! isset($variationsByAttributes[$key])) {
                $variationsByAttributes[$key] = [];
            }

            $variationsByAttributes[$key][] = [
                'id' => $variation->id,
                'sku' => $variation->sku,
                'name' => $variation->name,
                'price' => $variation->price,
                'stock_quantity' => $variation->stock_quantity,
            ];
        }

        // РќР°С…РѕРґРёРј РіСЂСѓРїРїС‹ СЃ РґСѓР±Р»РёРєР°С‚Р°РјРё (Р±РѕР»СЊС€Рµ РѕРґРЅРѕР№ РІР°СЂРёР°С†РёРё)
        $duplicates = [];
        foreach ($variationsByAttributes as $key => $group) {
            if (count($group) > 1) {
                $duplicates[] = $group;
            }
        }

        return $duplicates;
    }

    /**
     * РњР°СЃСЃРѕРІРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РІР°СЂРёР°С†РёР№
     */
    public function bulkUpdate(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'variation_ids' => 'required|array',
            'variation_ids.*' => 'exists:shop_good_variations,id',
            'action' => 'required|in:delete,change_stock,change_remote_stock,change_price,change_sale_price,change_demping_price,activate,deactivate,enable_demping,disable_demping,update_dimensions',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $variationIds = $request->get('variation_ids');
            $action = $request->get('action');
            $data = $request->get('data', []);

            $variations = ShopGoodVariation::where('good_id', $goodId)
                ->whereIn('id', $variationIds)
                ->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р’Р°СЂРёР°С†РёРё РЅРµ РЅР°Р№РґРµРЅС‹',
                ], 404);
            }

            $updatedCount = 0;

            switch ($action) {
                case 'delete':
                    $variations->each(function ($variation) {
                        $variation->delete();
                    });
                    $updatedCount = $variations->count();
                    break;

                case 'change_stock':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (int) $data['value'] : 0;

                    foreach ($variations as $variation) {
                        $currentStock = (int) ($variation->stock_quantity ?? 0);
                        if ($type === 'set') {
                            $variation->stock_quantity = $value;
                        } elseif ($type === 'add') {
                            $variation->stock_quantity = $currentStock + $value;
                        } elseif ($type === 'subtract') {
                            $variation->stock_quantity = max(0, $currentStock - $value);
                        }
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_remote_stock':
                    $remoteStockValue = $data['value'] ?? null;
                    foreach ($variations as $variation) {
                        $variation->remote_stock_quantity = $remoteStockValue ? (string) $remoteStockValue : null;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];

                    foreach ($variations as $variation) {
                        $currentPrice = (float) ($variation->price ?? 0);
                        if ($type === 'set') {
                            $variation->price = max(0, $value);
                        } elseif ($type === 'add') {
                            if ($isPercent) {
                                $variation->price = max(0, $currentPrice + ($currentPrice * $value / 100));
                            } else {
                                $variation->price = max(0, $currentPrice + $value);
                            }
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $variation->price = max(0, $currentPrice - ($currentPrice * $value / 100));
                            } else {
                                $variation->price = max(0, $currentPrice - $value);
                            }
                        }
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_sale_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];

                    foreach ($variations as $variation) {
                        $basePrice = (float) ($variation->price ?? 0);
                        if ($type === 'clear') {
                            $variation->sale_price = null;
                        } elseif ($type === 'set') {
                            $variation->sale_price = max(0, $value);
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $variation->sale_price = max(0, $basePrice - ($basePrice * $value / 100));
                            } else {
                                $variation->sale_price = max(0, $basePrice - $value);
                            }
                        }
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'update_dimensions':
                    foreach ($variations as $variation) {
                        $updateData = [];

                        if (isset($data['width']) && $data['width'] !== null && $data['width'] !== '') {
                            $updateData['width'] = (float) $data['width'];
                        }
                        if (isset($data['height']) && $data['height'] !== null && $data['height'] !== '') {
                            $updateData['height'] = (float) $data['height'];
                        }
                        if (isset($data['length']) && $data['length'] !== null && $data['length'] !== '') {
                            $updateData['length'] = (float) $data['length'];
                        }
                        if (isset($data['weight']) && $data['weight'] !== null && $data['weight'] !== '') {
                            $updateData['weight'] = (float) $data['weight'];
                        }

                        if (! empty($updateData)) {
                            $variation->fill($updateData);
                            $variation->save();
                            $updatedCount++;
                        }
                    }
                    break;

                case 'change_demping_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];
                    $activateDemping = isset($data['activate_demping']) && $data['activate_demping'];

                    foreach ($variations as $variation) {
                        $basePrice = (float) ($variation->price ?? 0);
                        if ($type === 'clear') {
                            $variation->demping_price = null;
                        } elseif ($type === 'set') {
                            $variation->demping_price = max(0, $value);
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $variation->demping_price = max(0, $basePrice - ($basePrice * $value / 100));
                            } else {
                                $variation->demping_price = max(0, $basePrice - $value);
                            }
                        }

                        if ($activateDemping) {
                            $variation->show_demping = true;
                        }

                        // РћР±СЂР°Р±РѕС‚РєР° РїРѕР»СЏ show_demping
                        if (isset($data['show_demping'])) {
                            $variation->show_demping = (bool) $data['show_demping'];
                        }

                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'activate':
                    foreach ($variations as $variation) {
                        $variation->is_active = true;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'deactivate':
                    foreach ($variations as $variation) {
                        $variation->is_active = false;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'enable_demping':
                    foreach ($variations as $variation) {
                        $variation->show_demping = true;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'disable_demping':
                    foreach ($variations as $variation) {
                        $variation->show_demping = false;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "РћР±РЅРѕРІР»РµРЅРѕ РІР°СЂРёР°С†РёР№: {$updatedCount}",
                'data' => [
                    'updated_count' => $updatedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РјР°СЃСЃРѕРІРѕРіРѕ РѕР±РЅРѕРІР»РµРЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р“Р»РѕР±Р°Р»СЊРЅРѕРµ РјР°СЃСЃРѕРІРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РІР°СЂРёР°С†РёР№ (Р±РµР· РїСЂРёРІСЏР·РєРё Рє С‚РѕРІР°СЂСѓ)
     * РџРѕРґРґРµСЂР¶РёРІР°РµС‚ РґРІР° РІР°СЂРёР°РЅС‚Р°:
     * 1. РџРµСЂРµРґР°С‡Р° variation_ids - РѕР±РЅРѕРІР»РµРЅРёРµ РєРѕРЅРєСЂРµС‚РЅС‹С… РІР°СЂРёР°С†РёР№
     * 2. РџРµСЂРµРґР°С‡Р° good_ids - РїРѕР»СѓС‡РµРЅРёРµ РІСЃРµС… РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂРѕРІ Рё РёС… РѕР±РЅРѕРІР»РµРЅРёРµ
     */
    public function globalBulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'variation_ids' => 'nullable|array',
            'variation_ids.*' => 'exists:shop_good_variations,id',
            'good_ids' => 'nullable|array',
            'good_ids.*' => 'exists:shop_goods,id',
            'action' => 'required|in:delete,change_stock,change_remote_stock,change_price,change_sale_price,change_demping_price,activate,deactivate,enable_demping,disable_demping',
            'data' => 'nullable|array',
        ]);

        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РїРµСЂРµРґР°РЅ С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РјР°СЃСЃРёРІРѕРІ
        if (! $request->has('variation_ids') && ! $request->has('good_ids')) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => ['variation_ids' => ['РќРµРѕР±С…РѕРґРёРјРѕ РїРµСЂРµРґР°С‚СЊ variation_ids РёР»Рё good_ids']],
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $action = $request->get('action');
            $data = $request->get('data', []);

            // Р•СЃР»Рё РїРµСЂРµРґР°РЅС‹ good_ids, СЃРЅР°С‡Р°Р»Р° РїРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё СЌС‚РёС… С‚РѕРІР°СЂРѕРІ
            if ($request->has('good_ids')) {
                $goodIds = $request->get('good_ids');
                $variationIds = ShopGoodVariation::whereIn('good_id', $goodIds)
                    ->pluck('id')
                    ->toArray();
            } else {
                $variationIds = $request->get('variation_ids');
            }

            if (empty($variationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р’Р°СЂРёР°С†РёРё РЅРµ РЅР°Р№РґРµРЅС‹',
                ], 404);
            }

            $variations = ShopGoodVariation::whereIn('id', $variationIds)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р’Р°СЂРёР°С†РёРё РЅРµ РЅР°Р№РґРµРЅС‹',
                ], 404);
            }

            $updatedCount = 0;

            switch ($action) {
                case 'delete':
                    $variations->each(function ($variation) {
                        $variation->delete();
                    });
                    $updatedCount = $variations->count();
                    break;

                case 'change_stock':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (int) $data['value'] : 0;

                    foreach ($variations as $variation) {
                        $currentStock = (int) ($variation->stock_quantity ?? 0);
                        if ($type === 'set') {
                            $variation->stock_quantity = $value;
                        } elseif ($type === 'add') {
                            $variation->stock_quantity = $currentStock + $value;
                        } elseif ($type === 'subtract') {
                            $variation->stock_quantity = max(0, $currentStock - $value);
                        }
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_remote_stock':
                    $remoteStockValue = $data['value'] ?? null;
                    foreach ($variations as $variation) {
                        $variation->remote_stock_quantity = $remoteStockValue ? (string) $remoteStockValue : null;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];

                    foreach ($variations as $variation) {
                        $currentPrice = (float) ($variation->price ?? 0);
                        if ($type === 'set') {
                            $variation->price = round(max(0, $value));
                        } elseif ($type === 'add') {
                            if ($isPercent) {
                                $variation->price = round(max(0, $currentPrice + ($currentPrice * $value / 100)));
                            } else {
                                $variation->price = round(max(0, $currentPrice + $value));
                            }
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $variation->price = round(max(0, $currentPrice - ($currentPrice * $value / 100)));
                            } else {
                                $variation->price = round(max(0, $currentPrice - $value));
                            }
                        }
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_sale_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];

                    foreach ($variations as $variation) {
                        $basePrice = (float) ($variation->price ?? 0);
                        $currentSalePrice = (float) ($variation->sale_price ?? 0);
                        $newSalePrice = null;

                        if ($type === 'clear') {
                            $newSalePrice = null;
                        } elseif ($type === 'set') {
                            $newSalePrice = round(max(0, $value));
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $newSalePrice = round(max(0, $basePrice - ($basePrice * $value / 100)));
                            } else {
                                $newSalePrice = round(max(0, $basePrice - $value));
                            }
                        } elseif ($type === 'subtract_from_sale') {
                            if ($currentSalePrice > 0) {
                                if ($isPercent) {
                                    $newSalePrice = round(max(0, $currentSalePrice - ($currentSalePrice * $value / 100)));
                                } else {
                                    $newSalePrice = round(max(0, $currentSalePrice - $value));
                                }
                            } else {
                                continue;
                            }
                        } elseif ($type === 'add_to_sale') {
                            if ($currentSalePrice > 0) {
                                if ($isPercent) {
                                    $newSalePrice = round(max(0, $currentSalePrice + ($currentSalePrice * $value / 100)));
                                } else {
                                    $newSalePrice = round(max(0, $currentSalePrice + $value));
                                }
                            } else {
                                continue;
                            }
                        }


                        $variation->sale_price = $newSalePrice;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'change_demping_price':
                    $type = $data['type'] ?? 'set';
                    $value = isset($data['value']) ? (float) $data['value'] : 0;
                    $isPercent = isset($data['is_percent']) && $data['is_percent'];
                    $activateDemping = isset($data['activate_demping']) && $data['activate_demping'];

                    foreach ($variations as $variation) {
                        $basePrice = (float) ($variation->price ?? 0);
                        if ($type === 'clear') {
                            $variation->demping_price = null;
                        } elseif ($type === 'set') {
                            $variation->demping_price = round(max(0, $value));
                        } elseif ($type === 'subtract') {
                            if ($isPercent) {
                                $variation->demping_price = round(max(0, $basePrice - ($basePrice * $value / 100)));
                            } else {
                                $variation->demping_price = round(max(0, $basePrice - $value));
                            }
                        }

                        if ($activateDemping) {
                            $variation->show_demping = true;
                        }

                        // РћР±СЂР°Р±РѕС‚РєР° РїРѕР»СЏ show_demping
                        if (isset($data['show_demping'])) {
                            $variation->show_demping = (bool) $data['show_demping'];
                        }

                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'activate':
                    foreach ($variations as $variation) {
                        $variation->is_active = true;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'deactivate':
                    foreach ($variations as $variation) {
                        $variation->is_active = false;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'enable_demping':
                    foreach ($variations as $variation) {
                        $variation->show_demping = true;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;

                case 'disable_demping':
                    foreach ($variations as $variation) {
                        $variation->show_demping = false;
                        $variation->save();
                        $updatedCount++;
                    }
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "РћР±РЅРѕРІР»РµРЅРѕ РІР°СЂРёР°С†РёР№: {$updatedCount}",
                'data' => [
                    'updated_count' => $updatedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РјР°СЃСЃРѕРІРѕРіРѕ РѕР±РЅРѕРІР»РµРЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ ID РІР°СЂРёР°С†РёР№ РґР»СЏ СЃРїРёСЃРєР° С‚РѕРІР°СЂРѕРІ
     */
    public function getVariationIdsByGoods(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|array',
            'good_ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $goodIds = $request->get('good_ids');

            $variations = ShopGoodVariation::whereIn('good_id', $goodIds)
                ->select('id', 'good_id')
                ->get();

            $result = [
                'variation_ids' => $variations->pluck('id')->toArray(),
                'variations_by_good' => $variations->groupBy('good_id')->map(function ($group) {
                    return $group->pluck('id')->toArray();
                })->toArray(),
                'total_count' => $variations->count(),
                'goods_count' => count($goodIds),
            ];

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РќР°Р№С‚Рё РІР°СЂРёР°С†РёРё СЃ РЅСѓР»РµРІС‹Рј РѕСЃС‚Р°С‚РєРѕРј Рё Р±РµР· РјРµРґРёР°
     */
    public function findZeroStockNoMediaVariations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|array',
            'good_ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $goodIds = $request->get('good_ids');

            // РќР°С…РѕРґРёРј РІР°СЂРёР°С†РёРё СЃ СѓСЃР»РѕРІРёСЏРјРё:
            // - stock_quantity = 0
            // - remote_stock_quantity IS NULL OR remote_stock_quantity = 0
            // - fast_remote_stock_quantity IS NULL OR fast_remote_stock_quantity = 0
            // Р’СЃРµ РІР°СЂРёР°С†РёРё СЃ 0 РѕСЃС‚Р°С‚РєРѕРј (СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё РёР»Рё Р±РµР·)
            $variations = ShopGoodVariation::whereIn('good_id', $goodIds)
                ->where('stock_quantity', 0)
                ->where(function ($query) {
                    $query->whereNull('remote_stock_quantity')
                        ->orWhere('remote_stock_quantity', '0')
                        ->orWhere('remote_stock_quantity', '');
                })
                ->where(function ($query) {
                    $query->whereNull('fast_remote_stock_quantity')
                        ->orWhere('fast_remote_stock_quantity', '0')
                        ->orWhere('fast_remote_stock_quantity', '');
                })
                ->with(['good:id,name,sku', 'images']) // Р—Р°РіСЂСѓР¶Р°РµРј РґР°РЅРЅС‹Рµ С‚РѕРІР°СЂР° Рё РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РґР»СЏ РѕС‚РѕР±СЂР°Р¶РµРЅРёСЏ
                ->select([
                    'id',
                    'good_id',
                    'name',
                    'sku',
                    'stock_quantity',
                    'remote_stock_quantity',
                    'fast_remote_stock_quantity',
                ])
                ->get();

            $result = [
                'variations' => $variations->map(function ($variation) {
                    return [
                        'id' => $variation->id,
                        'good_id' => $variation->good_id,
                        'good_name' => $variation->good->name ?? 'вЂ”',
                        'sku' => $variation->sku,
                        'stock' => $variation->stock_quantity,
                        'remote_stock' => $variation->remote_stock_quantity,
                        'fast_remote_stock' => $variation->fast_remote_stock_quantity,
                        'images_count' => $variation->images->count(),
                    ];
                }),
                'count' => $variations->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕРёСЃРєР° РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }
}
