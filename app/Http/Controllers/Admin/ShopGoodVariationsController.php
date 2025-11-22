<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopGoodProperty;
use App\Models\Shop\Property as ShopProperty;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ShopGoodVariationsController extends Controller
{
    /**
     * Получить вариации товара
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        $variations = $good->variations()
            ->ordered()
            ->get();

        // Подтягиваем атрибуты вариаций из новой схемы
        $variationIds = $variations->pluck('id')->all();
        if (!empty($variationIds)) {
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
                    'attribute' => ['id' => (int)$r->attribute_id, 'name' => $r->attribute_name],
                    'value' => ['id' => (int)$r->value_id, 'value' => $r->value_value],
                ];
            }

            foreach ($variations as $v) {
                $v->attributes = $byVariation[$v->id] ?? [];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $variations
        ]);
    }

    /**
     * Справочник атрибутов вариаций
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
                \Illuminate\Support\Facades\DB::raw('COUNT(vav.id) as usage_count')
            ]);
        
        // Определяем тип атрибута на основе slug
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
     * Обновить атрибут (переименовать)
     */
    public function updateAttribute(Request $request, $goodId, $attributeId): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Название атрибута обязательно'], 422);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('name', $name)
            ->where('id', '!=', (int)$attributeId)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Атрибут с таким названием уже существует'], 422);
        }

        // Перегенерируем slug, если меняем имя
        $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-' . uniqid());
        $slug = $baseSlug;
        $i = 2;
        while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->where('id', '!=', (int)$attributeId)->exists()) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int)$attributeId)
            ->update([
                'name' => $name,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => (int)$attributeId, 'name' => $name, 'slug' => $slug],
            'message' => 'Атрибут обновлен'
        ]);
    }

    /**
     * Удалить атрибут (если нет использования)
     */
    public function deleteAttribute(Request $request, $goodId, $attributeId): JsonResponse
    {
        // Проверяем использование
        $inUse = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->where('av.attribute_id', (int)$attributeId)
            ->exists();
        if ($inUse) {
            return response()->json(['success' => false, 'message' => 'Атрибут используется в вариациях и не может быть удален'], 409);
        }

        // Удаляем значения атрибута (на всякий случай)
        \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int)$attributeId)
            ->delete();
        // Удаляем сам атрибут
        \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int)$attributeId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Атрибут удален'
        ]);
    }

    /**
     * Список значений для атрибута
     */
    public function getAttributeValues($goodId, $attributeId): JsonResponse
    {
        // Сначала проверим, существует ли атрибут
        $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
            ->where('id', (int)$attributeId)
            ->first();

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Атрибут не найден'
            ], 404);
        }

        $values = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int)$attributeId)
            ->select('id', 'value')
            ->orderBy('value')
            ->get();


        return response()->json([
            'success' => true,
            'data' => $values,
        ]);
    }

    /**
     * Создать новый атрибут вариаций
     */
    public function createAttribute(Request $request, $goodId): JsonResponse
    {
        $name = trim((string)$request->input('name'));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Название атрибута обязательно'], 422);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('name', $name)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Атрибут с таким названием уже существует'], 422);
        }

        // Определяем slug на основе типа или названия
        $type = $request->input('type');
        $slug = '';
        
        if ($type === 'color') {
            // Для типа color используем slug 'color'
            $slug = 'color';
            // Проверяем уникальность slug 'color'
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = 'color-' . $i;
                $i++;
            }
        } elseif ($type === 'select') {
            // Для типа select добавляем префикс 'select-' к slug из названия
            $baseSlug = 'select-' . (\Illuminate\Support\Str::slug($name) ?: ('attr-' . uniqid()));
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i;
                $i++;
            }
        } else {
            // Для остальных типов генерируем slug из названия
            $baseSlug = \Illuminate\Support\Str::slug($name) ?: ('attr-' . uniqid());
            $slug = $baseSlug;
            $i = 2;
            while (\Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i;
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
            'message' => 'Атрибут создан'
        ]);
    }

    /**
     * Создать новое значение атрибута
     */
    public function createAttributeValue(Request $request, $goodId, $attributeId): JsonResponse
    {
        $value = trim((string)$request->input('value'));
        if ($value === '') {
            return response()->json(['success' => false, 'message' => 'Значение обязательно'], 422);
        }


        $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')->where('id', (int)$attributeId)->first();
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Атрибут не найден'], 404);
        }

        $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
            ->where('attribute_id', (int)$attributeId)
            ->where('value', $value)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Такое значение уже существует'], 422);
        }

        $id = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')->insertGetId([
            'attribute_id' => (int)$attributeId,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'attribute_id' => (int)$attributeId, 'value' => $value],
            'message' => 'Значение добавлено'
        ]);
    }

    /**
     * Создать вариацию
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
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Используем артикул родительского товара без добавлений
            $variationSku = $request->get('sku') ?: $good->sku;
            $variationName = $request->get('name') ?: $good->name;

            // Подготавливаем данные для создания вариации
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
                'sort_order' => $good->variations()->max('sort_order') + 1
            ];
            
            // Явно обрабатываем remote_stock_quantity
            if ($request->has('remote_stock_quantity')) {
                $remoteStockValue = $request->get('remote_stock_quantity');
                $variationData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string)$remoteStockValue;
            }

            // Проверяем на дубликаты вариаций по комбинации атрибутов
            if ($request->has('attributes') && is_array($request->get('attributes'))) {
                $requestAttributeValueIds = array_map(function($attr) {
                    return (int)$attr['value_id'];
                }, $request->get('attributes'));
                sort($requestAttributeValueIds);
                
                // Получаем все существующие вариации товара
                $existingVariations = ShopGoodVariation::where('good_id', $goodId)->get();
                
                foreach ($existingVariations as $existingVariation) {
                    // Получаем атрибуты существующей вариации
                    $existingAttributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                        ->where('variation_id', $existingVariation->id)
                        ->pluck('attribute_value_id')
                        ->map(function($id) {
                            return (int)$id;
                        })
                        ->toArray();
                    sort($existingAttributeValueIds);
                    
                    // Сравниваем комбинации атрибутов
                    if ($requestAttributeValueIds === $existingAttributeValueIds) {
                        // Формируем строку комбинации для отображения
                        $combinationParts = [];
                        foreach ($request->get('attributes') as $attr) {
                            $attribute = \Illuminate\Support\Facades\DB::table('shop_variation_attributes')
                                ->where('id', $attr['attribute_id'])
                                ->first();
                            $value = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                                ->where('id', $attr['value_id'])
                                ->first();
                            if ($attribute && $value) {
                                $combinationParts[] = $attribute->name . ': ' . $value->value;
                            }
                        }
                        $combination = implode(', ', $combinationParts);
                        
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Такая вариация уже существует: ' . $combination
                        ], 422);
                    }
                }
            }

            // Создаем вариацию
            $variation = ShopGoodVariation::create($variationData);

            // Создаем атрибуты вариации (новая схема)
            if ($request->has('attributes') && is_array($request->get('attributes'))) {
                foreach ($request->get('attributes') as $attrData) {
                    if (isset($attrData['attribute_id']) && isset($attrData['value_id'])) {
                        DB::table('shop_variation_attributes_values')->insert([
                            'variation_id' => $variation->id,
                            'attribute_value_id' => (int)$attrData['value_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Создаем свойства вариации (старая схема, для обратной совместимости)
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

            // В новой схеме свойства не подгружаем

            return response()->json([
                'success' => true,
                'message' => 'Вариация успешно создана',
                'data' => $variation
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
     * Создать микс вариаций
     */
    public function storeBulk(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        // Новая схема: принимаем attribute_groups [{ attribute_id, values[] }]
        $validator = Validator::make($request->all(), [
            'attribute_groups' => 'required|array|min:1',
            'attribute_groups.*.attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'attribute_groups.*.values' => 'required|array|min:1',
            'attribute_groups.*.values.*' => 'required|string|max:255',
            'sku_prefix' => 'nullable|string|max:255',
            'base_price' => 'nullable|numeric|min:0',
            'base_quantity' => 'nullable|integer|min:0'
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

            // Сгенерируем все комбинации значений по группам
            $groups = $request->get('attribute_groups', []);
            
            $combinations = [[]];
            foreach ($groups as $group) {
                $next = [];
                foreach ($combinations as $combo) {
                    foreach ($group['values'] as $val) {
                        $next[] = array_merge($combo, [[
                            'attribute_id' => (int)$group['attribute_id'],
                            'value' => trim((string)$val),
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
                    'sku' => $skuPrefix ? ($skuPrefix . '-' . $idx) : $good->sku,
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

                // Привязываем значения атрибутов к вариации
                foreach ($combo as $pair) {
                    $valRow = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $pair['attribute_id'])
                        ->where('value', $pair['value'])
                        ->first();
                    if (!$valRow) {
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
                'message' => 'Вариации успешно созданы',
                'data' => $createdIds
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания вариаций: ' . $e->getMessage()
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
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Подготавливаем данные для обновления
        $updateData = $request->only([
            'name', 'sku', 'price', 'sale_price', 'stock_quantity', 'weight', 
            'depth', 'height', 'width', 'is_active'
        ]);
        
        // Явно обрабатываем remote_stock_quantity - всегда обновляем, даже если null
        $allRequestData = $request->all();
        if (isset($allRequestData['remote_stock_quantity'])) {
            $remoteStockValue = $allRequestData['remote_stock_quantity'];
            $updateData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string)$remoteStockValue;
        }
        
        $variation->update($updateData);

        // В новой схеме свойства не подгружаем

        return response()->json([
            'success' => true,
            'message' => 'Вариация успешно обновлена',
            'data' => $variation
        ]);
    }

    /**
     * Удалить вариацию
     */
    public function destroy($goodId, $variationId): JsonResponse
    {
        $variation = ShopGoodVariation::find($variationId);
        if (!$variation) {
            return response()->json([
                'success' => false,
                'message' => 'Вариация не найдена'
            ], 404);
        }
        if ((int)$variation->good_id !== (int)$goodId) {
            return response()->json([
                'success' => false,
                'message' => 'Вариация не относится к указанному товару'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Свойства вариации в старой схеме отсутствуют; атрибуты хранятся в другой таблице
            \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->delete();

            // Удаляем вариацию
            $variation->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Вариация успешно удалена'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления вариации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить дублирование комбинации свойств
     */
    public function checkDuplicate(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'properties' => 'required|array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            // 'properties.*.value' => 'required|string|max:255' // Временно отключено
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $properties = $request->get('properties', []);
            
        // Получаем все вариации товара (свойства больше не грузим из shop_good_properties)
        $existingVariations = ShopGoodVariation::where('good_id', $goodId)
            ->get();

            // Проверяем каждую существующую вариацию
            foreach ($existingVariations as $variation) {
                // $variationProperties = $variation->properties->pluck('value', 'property_id')->toArray(); // Временно отключено
                // $requestProperties = collect($properties)->pluck('value', 'property_id')->toArray(); // Временно отключено
                
                // Сравниваем комбинации
                // Временно отключено - будет исправлено в следующем шаге
                /*
                if ($this->comparePropertyCombinations($variationProperties, $requestProperties)) {
                    // Формируем строку комбинации для отображения
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
                'is_duplicate' => false
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки дублирования: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Добавить атрибут ко всем вариациям товара
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
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $good = ShopGood::findOrFail($goodId);
            $attributeId = (int)$request->get('attribute_id');
            $valueId = (int)$request->get('value_id');
            
            // Проверяем, что значение принадлежит атрибуту
            $value = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                ->where('id', $valueId)
                ->where('attribute_id', $attributeId)
                ->first();
            
            if (!$value) {
                return response()->json([
                    'success' => false,
                    'message' => 'Значение не принадлежит выбранному атрибуту'
                ], 422);
            }
            
            // Получаем все вариации товара
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();
            
            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'У товара нет вариаций'
                ], 422);
            }
            
            DB::beginTransaction();
            
            $addedCount = 0;
            foreach ($variations as $variation) {
                // Проверяем, не добавлен ли уже этот атрибут к вариации
                $exists = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                    ->where('variation_id', $variation->id)
                    ->where('attribute_value_id', $valueId)
                    ->exists();
                
                if (!$exists) {
                    // Проверяем, нет ли у вариации другого значения этого атрибута
                    $otherValue = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->where('vav.variation_id', $variation->id)
                        ->where('av.attribute_id', $attributeId)
                        ->first();
                    
                    if ($otherValue) {
                        // Заменяем существующее значение
                        \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                            ->where('variation_id', $variation->id)
                            ->where('attribute_value_id', $otherValue->id)
                            ->update(['attribute_value_id' => $valueId]);
                    } else {
                        // Добавляем новое значение
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
                'message' => "Атрибут успешно добавлен к {$addedCount} вариациям",
                'data' => ['added_count' => $addedCount]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления атрибута: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Добавить свойство ко всем вариациям товара
     */
    public function addProperty(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'property_id' => 'required|exists:shop_properties,id',
            'values' => 'required|array',
            'values.*' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $propertyId = $request->get('property_id');
            $values = $request->get('values', []);

            // Получаем все вариации товара
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'У товара нет вариаций'
                ], 400);
            }

            // Добавляем свойство к каждой вариации
            foreach ($variations as $variation) {
                if (isset($values[$variation->id])) {
                    ShopGoodProperty::create([
                        'variation_id' => $variation->id,
                        'property_id' => $propertyId,
                        'value' => $values[$variation->id]
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно добавлено ко всем вариациям'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления свойства: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить атрибут из всех вариаций товара
     */
    public function removeProperty(Request $request, $goodId): JsonResponse
    {
        // Поддерживаем оба варианта для обратной совместимости
        $attributeId = $request->get('attribute_id') ?: $request->get('property_id');
        
        $validator = Validator::make(['attribute_id' => $attributeId], [
            'attribute_id' => 'required|exists:shop_variation_attributes,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Получаем все вариации товара
            $variations = ShopGoodVariation::where('good_id', $goodId)->get();

            if ($variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'У товара нет вариаций'
                ], 400);
            }

            $variationIds = $variations->pluck('id')->toArray();

            // Удаляем атрибут из всех вариаций (новая схема)
            // Находим все значения атрибута
            $attributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attribute_values')
                ->where('attribute_id', (int)$attributeId)
                ->pluck('id')
                ->toArray();

            if (!empty($attributeValueIds)) {
                // Удаляем связи вариаций с этими значениями
                $deletedCount = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                    ->whereIn('variation_id', $variationIds)
                    ->whereIn('attribute_value_id', $attributeValueIds)
                    ->delete();
            } else {
                $deletedCount = 0;
            }

            // Проверяем на дубликаты после удаления атрибута
            $duplicates = $this->findDuplicateVariations($goodId);
            
            $responseData = [
                'success' => true,
                'message' => "Атрибут успешно удален из {$deletedCount} вариаций",
                'duplicates' => $duplicates
            ];
            
            if (!empty($duplicates)) {
                $responseData['has_duplicates'] = true;
                $responseData['duplicate_count'] = count($duplicates);
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления атрибута: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить порядок вариаций
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'variations' => 'required|array',
            'variations.*.id' => 'required|exists:shop_good_variations,id',
            'variations.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->get('variations') as $variationData) {
            ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationData['id'])
                ->update(['sort_order' => $variationData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок вариаций обновлен'
        ]);
    }

    /**
     * Генерировать все возможные комбинации свойств
     */
    private function generateCombinations($properties)
    {
        if (empty($properties)) {
            return [];
        }

        $combinations = [[]];

        // Временно отключено - будет исправлено в следующем шаге
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
     * Сравнить комбинации свойств
     */
    private function comparePropertyCombinations($existing, $requested)
    {
        // Проверяем, что количество свойств совпадает
        if (count($existing) !== count($requested)) {
            return false;
        }

        // Нормализуем значения для сравнения (приводим к нижнему регистру и убираем пробелы)
        $normalizedExisting = [];
        foreach ($existing as $propertyId => $value) {
            $normalizedExisting[$propertyId] = mb_strtolower(trim($value), 'UTF-8');
        }

        $normalizedRequested = [];
        foreach ($requested as $propertyId => $value) {
            $normalizedRequested[$propertyId] = mb_strtolower(trim($value), 'UTF-8');
        }

        // Проверяем каждое свойство
        foreach ($normalizedRequested as $propertyId => $value) {
            if (!isset($normalizedExisting[$propertyId]) || 
                $normalizedExisting[$propertyId] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Найти дубликаты вариаций по комбинации атрибутов
     */
    private function findDuplicateVariations($goodId): array
    {
        $variations = ShopGoodVariation::where('good_id', $goodId)->get();
        
        if ($variations->isEmpty()) {
            return [];
        }

        // Группируем вариации по комбинации атрибутов
        $variationsByAttributes = [];
        
        foreach ($variations as $variation) {
            // Получаем атрибуты вариации
            $attributeValueIds = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->map(function($id) {
                    return (int)$id;
                })
                ->toArray();
            
            sort($attributeValueIds);
            $key = implode(',', $attributeValueIds);
            
            if (!isset($variationsByAttributes[$key])) {
                $variationsByAttributes[$key] = [];
            }
            
            $variationsByAttributes[$key][] = [
                'id' => $variation->id,
                'sku' => $variation->sku,
                'name' => $variation->name,
                'price' => $variation->price,
                'stock_quantity' => $variation->stock_quantity
            ];
        }

        // Находим группы с дубликатами (больше одной вариации)
        $duplicates = [];
        foreach ($variationsByAttributes as $key => $group) {
            if (count($group) > 1) {
                $duplicates[] = $group;
            }
        }

        return $duplicates;
    }

}