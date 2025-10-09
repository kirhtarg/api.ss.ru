<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopBrand;
use App\Models\ShopTag;
use App\Models\ShopProperty;
use App\Models\ShopCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ShopGoodsController extends Controller
{
    /**
     * Получить список товаров с фильтрацией и пагинацией
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopGood::with([
            'categories:id,name',
            'brands:id,name',
            'tags:id,name,color',
            'properties:id,name,slug',
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
            'variations:id,good_id,name,price,sale_price,stock_quantity,is_active'
        ])->withCount('variations');

        // Загружаем pivot данные для свойств (поддерживаем разные схемы: value или shop_property_value_id)
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $query->with(['properties' => function($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->search($search);
        }

        // Фильтр по категории
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Фильтр по бренду
        if ($request->filled('brand_id')) {
            $query->byBrand($request->get('brand_id'));
        }

        // Фильтр по тегу
        if ($request->filled('tag_id')) {
            $query->byTag($request->get('tag_id'));
        }

        // Фильтр по цене
        if ($request->filled('min_price') || $request->filled('max_price')) {
            $query->priceRange($request->get('min_price'), $request->get('max_price'));
        }

        // Фильтр по рейтингу
        if ($request->filled('min_rating')) {
            $query->rating($request->get('min_rating'));
        }

        // Фильтр по наличию
        if ($request->filled('in_stock')) {
            $inStock = $request->get('in_stock');
            if ($inStock === 'true') {
                $query->where('stock_quantity', '>', 0);
            } elseif ($inStock === 'false') {
                $query->where('stock_quantity', '=', 0);
            } elseif ($inStock === 'low') {
                $query->where('stock_quantity', '>', 0)
                      ->where('stock_quantity', '<', 3);
            }
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

        // Фильтр по статусу
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortBy, ['name', 'price', 'rating', 'stock_quantity', 'created_at', 'sort_order'])) {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Пагинация
        $perPage = $request->get('per_page', 20);
        $perPage = in_array($perPage, [10, 20, 50, 100]) ? $perPage : 20;

        $goods = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $goods->items(),
            'pagination' => [
                'current_page' => $goods->currentPage(),
                'last_page' => $goods->lastPage(),
                'per_page' => $goods->perPage(),
                'total' => $goods->total(),
                'from' => $goods->firstItem(),
                'to' => $goods->lastItem()
            ]
        ]);
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
            'variations:id,good_id,name,description,price,sale_price,stock_quantity,sku,is_active',
            'stock:id,good_id,warehouse_id,quantity,reserved_quantity,min_quantity',
            'stock.warehouse:id,name',
            'prices:id,good_id,price_type_id,price,sale_price',
            'prices.priceType:id,name,multiplier'
        ])->findOrFail($id);

        // Загружаем дополнительные данные для свойств товара
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $good->load(['properties' => function($query) use ($hasValueCol) {
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
            'data' => $good
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
            'sku' => 'required|string|max:255|unique:shop_goods,sku',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
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
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id'
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

            $good = ShopGood::create($request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'sort_order'
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
                    if (!empty($property['shop_property_value_id'])) {
                        $propertyValueId = $property['shop_property_value_id'];
                    }
                    // Если есть value, ищем или создаем запись в shop_property_values
                    elseif (!empty($property['value'])) {
                        $propertyValue = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $property['property_id'],
                            'value' => trim($property['value'])
                        ], [
                            'is_active' => true,
                            'sort_order' => 0
                        ]);
                        $propertyValueId = $propertyValue->id;
                    }
                    
                    // Привязываем свойство только если есть propertyValueId
                    if ($propertyValueId) {
                        $good->properties()->attach($property['property_id'], [
                            'shop_property_value_id' => $propertyValueId
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
                'data' => $good->load(['categories', 'brands', 'tags', 'properties'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить товар
     */
    public function update(Request $request, $id): JsonResponse
    {
        Log::info('Update request received for good ID: ' . $id);
        Log::info('Request data:', $request->all());
        Log::info('Properties in request:', $request->get('properties', []));
        
        $good = ShopGood::findOrFail($id);
        $oldValues = $good->toArray();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_goods', 'slug')->ignore($id)],
            'sku' => ['required', 'string', 'max:255', Rule::unique('shop_goods', 'sku')->ignore($id)],
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
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
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id'
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

            $good->update($request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'sort_order'
            ]));

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
                Log::info('Properties data received (count): ' . count($incoming));

                $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
                $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
                $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');
                Log::info('shop_good_properties schema:', [
                    'has_value' => $hasValueCol,
                    'has_shop_property_value_id' => $hasShopValueIdCol,
                    'has_variation_id' => $hasVariationIdCol,
                ]);

                // Очистим существующие свойства товара (только базовые, если есть колонка variation_id)
                $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                if ($hasVariationIdCol) {
                    $deleteQuery->whereNull('variation_id');
                }
                $deleted = $deleteQuery->delete();
                Log::info('Deleted base properties for good', ['good_id' => $good->id, 'deleted' => $deleted]);

                foreach ($incoming as $property) {
                    if (empty($property['property_id'])) {
                        Log::warning('Skipping property without property_id:', $property);
                        continue;
                    }

                    $propertyId = (int) $property['property_id'];

                    // Режим через справочник значений
                    if ($hasShopValueIdCol) {
                        $propertyValueId = null;
                        if (!empty($property['shop_property_value_id'])) {
                            $propertyValueId = (int) $property['shop_property_value_id'];
                        } elseif (!empty($property['value'])) {
                            $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                                'property_id' => $propertyId,
                                'value' => trim($property['value'])
                            ], [
                                'is_active' => true,
                                'sort_order' => 0
                            ]);
                            $propertyValueId = (int) $pv->id;
                        }

                        if ($propertyValueId) {
                            DB::table('shop_good_properties')->updateOrInsert(
                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                [
                                    'shop_property_value_id' => $propertyValueId,
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                            Log::info('Upsert property (ref mode)', [
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'shop_property_value_id' => $propertyValueId
                            ]);
                            $lastSyncData[$propertyId] = ['shop_property_value_id' => $propertyValueId];
                        } else {
                            Log::warning('Skipping property without resolvable value/id (ref mode)', $property);
                        }
                    }
                    // Режим хранения прямого текста значения
                    elseif ($hasValueCol) {
                        $textValue = null;
                        if (!empty($property['value'])) {
                            $textValue = trim($property['value']);
                        } elseif (!empty($property['shop_property_value_id'])) {
                            $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                            $textValue = $found ? $found->value : null;
                        }

                        if ($textValue !== null && $textValue !== '') {
                            DB::table('shop_good_properties')->updateOrInsert(
                                ['good_id' => $good->id, 'property_id' => $propertyId],
                                [
                                    'value' => $textValue,
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                            Log::info('Upsert property (text mode)', [
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'value' => $textValue
                            ]);
                            $lastSyncData[$propertyId] = ['value' => $textValue];
                        } else {
                            Log::warning('Skipping property without resolvable text value (text mode)', $property);
                        }
                    } else {
                        Log::error('shop_good_properties has neither value nor shop_property_value_id column.');
                    }
                }
                Log::info('Final properties sync summary', [
                    'good_id' => $good->id,
                    'count' => count($lastSyncData),
                    'data' => $lastSyncData
                ]);
            }

            // Аудит
            $this->logAudit($good, 'updated', $oldValues, $good->fresh()->toArray());

            DB::commit();

            // Подтверждаем результат: возвращаем свойства с pivot
            $good->load(['properties' => function($q){
                $q->withPivot('shop_property_value_id');
            }]);

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно обновлен',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
                'debug' => [
                    'attached_count' => isset($lastSyncData) ? count($lastSyncData) : 0,
                    'attached' => $lastSyncData,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить характеристики товара отдельным эндпоинтом
     */
    public function updateProperties(Request $request, $id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'properties' => 'required|array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.value' => 'nullable|string|max:255',
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id'
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

            $incoming = $request->get('properties', []);
            Log::info('updateProperties: received', ['good_id' => $good->id, 'count' => count($incoming)]);

            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
            $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

            // Очистим только базовые свойства
            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
            if ($hasVariationIdCol) {
                $deleteQuery->whereNull('variation_id');
            }
            $deleted = $deleteQuery->delete();
            Log::info('updateProperties: deleted previous', ['good_id' => $good->id, 'deleted' => $deleted]);

            $lastSyncData = [];
            foreach ($incoming as $property) {
                if (empty($property['property_id'])) {
                    continue;
                }
                $propertyId = (int) $property['property_id'];

                if ($hasShopValueIdCol) {
                    $propertyValueId = null;
                    if (!empty($property['shop_property_value_id'])) {
                        $propertyValueId = (int) $property['shop_property_value_id'];
                    } elseif (!empty($property['value'])) {
                        $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $propertyId,
                            'value' => trim($property['value'])
                        ], [
                            'is_active' => true,
                            'sort_order' => 0
                        ]);
                        $propertyValueId = (int) $pv->id;
                    }
                    if ($propertyValueId) {
                        DB::table('shop_good_properties')->updateOrInsert(
                            ['good_id' => $good->id, 'property_id' => $propertyId],
                            [
                                'shop_property_value_id' => $propertyValueId,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $lastSyncData[$propertyId] = ['shop_property_value_id' => $propertyValueId];
                    }
                } elseif ($hasValueCol) {
                    $textValue = null;
                    if (!empty($property['value'])) {
                        $textValue = trim($property['value']);
                    } elseif (!empty($property['shop_property_value_id'])) {
                        $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                        $textValue = $found ? $found->value : null;
                    }
                    if ($textValue !== null && $textValue !== '') {
                        DB::table('shop_good_properties')->updateOrInsert(
                            ['good_id' => $good->id, 'property_id' => $propertyId],
                            [
                                'value' => $textValue,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $lastSyncData[$propertyId] = ['value' => $textValue];
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойства обновлены',
                'data' => [
                    'attached' => $lastSyncData,
                    'count' => count($lastSyncData)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления свойств: ' . $e->getMessage()
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
                'message' => 'Товар успешно удален'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Массовое обновление товаров
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:shop_goods,id',
            'action' => 'required|in:activate,deactivate,delete,update_categories,update_brands,update_tags',
            'data' => 'nullable|array'
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

            $ids = $request->get('ids');
            $action = $request->get('action');
            $data = $request->get('data', []);

            $goods = ShopGood::whereIn('id', $ids)->get();

            foreach ($goods as $good) {
                $oldValues = $good->toArray();

                switch ($action) {
                    case 'activate':
                        $good->update(['is_active' => true]);
                        break;
                    case 'deactivate':
                        $good->update(['is_active' => false]);
                        break;
                    case 'delete':
                        $good->delete();
                        break;
                    case 'update_categories':
                        if (isset($data['category_ids'])) {
                            $good->categories()->sync($data['category_ids']);
                        }
                        break;
                    case 'update_brands':
                        if (isset($data['brand_ids'])) {
                            $good->brands()->sync($data['brand_ids']);
                        }
                        break;
                    case 'update_tags':
                        if (isset($data['tag_ids'])) {
                            $good->tags()->sync($data['tag_ids']);
                        }
                        break;
                }

                // Аудит
                if ($action !== 'delete') {
                    $this->logAudit($good, 'bulk_' . $action, $oldValues, $good->fresh()->toArray());
                } else {
                    $this->logAudit($good, 'bulk_deleted', $oldValues, null);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Массовое обновление выполнено успешно'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка массового обновления: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить данные для фильтров
     */
    public function filters(): JsonResponse
    {
        $categories = ShopCategory::active()->ordered()->get(['id', 'name']);
        $brands = ShopBrand::active()->ordered()->get(['id', 'name']);
        $tags = ShopTag::active()->ordered()->get(['id', 'name', 'color']);
        $properties = ShopProperty::active()->ordered()->get(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'tags' => $tags,
                'properties' => $properties
            ]
        ]);
    }

    /**
     * Создать новую категорию
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $category = ShopCategory::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopCategory::max('sort_order') + 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $category
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый бренд
     */
    public function createBrand(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $brand = ShopBrand::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopBrand::max('sort_order') + 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Бренд успешно создан',
                'data' => $brand
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания бренда: ' . $e->getMessage()
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
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageUrl = $request->input('imageUrl');
            $storagePath = $request->input('storagePath', '/images/shop/goods'); // Исправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');


            // Валидация URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный формат URL'
                ], 400);
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            
            if (!in_array($extension, $imageExtensions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неподдерживаемый формат изображения'
                ], 400);
            }

            // Генерация имени файла
            if ($naming === 'original') {
                // Используем оригинальное имя файла
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName . '.' . $extension;
                
                // Очищаем имя файла от недопустимых символов
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                // Используем хеш
                $hash = hash('sha256', $imageUrl);
                $fileName = $hash . '.' . $extension;
            }
            
            // Полный путь для сохранения
            $fullPath = $storagePath . '/' . $fileName;
            $storageFullPath = storage_path('app/public' . $fullPath);
            
            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!\App\Helpers\StorageHelper::createDirectory($directory)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать директорию для изображения'
                ], 500);
            }

            // Скачиваем изображение с помощью cURL для обхода SSL проблем
            
            $downloadResult = $this->downloadImageWithCurl($imageUrl);
            
            if (!$downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось скачать изображение: ' . ($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}")
                ], 400);
            }
            
            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл слишком большой (максимум 30MB)'
                ], 400);
            }

            // Сохраняем файл
            $saveResult = file_put_contents($storageFullPath, $imageData);
            if ($saveResult === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить файл'
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
                    'optimized' => $optimize
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка скачивания изображения: ' . $e->getMessage()
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
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageUrls = $request->input('imageUrls');
            $storagePath = $request->input('storagePath', '/images/shop/goods'); // Исправляем путь по умолчанию
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');


            $results = [];
            $errors = [];
            $skipped = [];

            // Обрабатываем изображения последовательно (для стабильности)
            foreach ($imageUrls as $index => $imageUrl) {
                $response = $this->downloadSingleImage(
                    $imageUrl,
                    $storagePath,
                    $optimize,
                    $naming,
                    $resize,
                    $width,
                    $height,
                    $index
                );

                if ($response['success']) {
                    $results[$response['originalUrl']] = $response['path'];
                    
                    if (isset($response['skipped']) && $response['skipped']) {
                        $skipped[] = $response['originalUrl'];
                    } else {
                    }
                } else {
                    $errors[] = [
                        'url' => $response['originalUrl'],
                        'error' => $response['error']
                    ];
                }
            }


            return response()->json([
                'success' => true,
                'data' => [
                    'paths' => $results,
                    'errors' => $errors,
                    'skipped' => $skipped,
                    'total' => count($imageUrls),
                    'successful' => count($results),
                    'skipped_count' => count($skipped),
                    'failed' => count($errors)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка пакетной загрузки изображений: ' . $e->getMessage()
            ], 500);
        }
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
            'height' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $image = $request->file('image');
            $path = $request->input('path');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            // Путь для сохранения на фронтенд
            $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
            $fullPath = $frontendPublicPath . $path;
            $dir = dirname($fullPath);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
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
                    'size' => filesize($fullPath)
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения изображения: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Загрузка одного изображения (вспомогательный метод для пакетной загрузки)
     */
    private function downloadSingleImage($imageUrl, $storagePath, $optimize, $naming, $resize, $width, $height, $index)
    {
        try {

            // Валидация URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Неверный формат URL'
                ];
            }

            // Проверка формата изображения
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            
            if (!in_array($extension, $imageExtensions)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Неподдерживаемый формат изображения'
                ];
            }

            // Генерация имени файла
            if ($naming === 'original') {
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName . '.' . $extension;
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                $hash = hash('sha256', $imageUrl . $index); // Добавляем индекс для уникальности
                $fileName = $hash . '.' . $extension;
            }
            
            // Полный путь для сохранения на фронтенд
            $fullPath = $storagePath . '/' . $fileName;
            $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
            $storageFullPath = $frontendPublicPath . $fullPath;
            
            // Проверяем, существует ли файл уже
            if (file_exists($storageFullPath)) {
                
                return [
                    'success' => true,
                    'originalUrl' => $imageUrl,
                    'path' => $fullPath,
                    'skipped' => true // Флаг, что файл был пропущен
                ];
            }
            
            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!\App\Helpers\StorageHelper::createDirectory($directory)) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Не удалось создать директорию для изображения'
                ];
            }

            // Скачиваем изображение с помощью cURL для обхода SSL проблем
            $downloadResult = $this->downloadImageWithCurl($imageUrl);
            
            if (!$downloadResult['success']) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Не удалось скачать изображение: ' . ($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}")
                ];
            }
            
            $imageData = $downloadResult['data'];

            // Проверка размера файла (максимум 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Файл слишком большой (максимум 30MB)'
                ];
            }

            // Сохраняем файл
            file_put_contents($storageFullPath, $imageData);

            // Обработка изображения
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($storageFullPath, $resize, $width, $height);
            }


            return [
                'success' => true,
                'originalUrl' => $imageUrl,
                'path' => $fullPath
            ];

        } catch (\Exception $e) {
            
            return [
                'success' => false,
                'originalUrl' => $imageUrl,
                'error' => 'Ошибка: ' . $e->getMessage()
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
            if (!$imageInfo) {
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
        // Сначала пробуем cURL с агрессивными настройками SSL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imageUrl);
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
            'success' => $imageData !== false && $httpCode === 200
        ];
    }

    /**
     * Fallback метод для скачивания изображений через wget
     */
    private function downloadImageWithWget($imageUrl)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'image_download_');
        
        try {
            // Используем wget с отключенной проверкой SSL
            $wgetCommand = "wget --no-check-certificate --timeout=30 --tries=1 -O '{$tempFile}' " . escapeshellarg($imageUrl) . " 2>&1";
            $output = shell_exec($wgetCommand);
            
            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $imageData = file_get_contents($tempFile);
                unlink($tempFile);
                
                
                return [
                    'data' => $imageData,
                    'http_code' => 200,
                    'error' => null,
                    'success' => true
                ];
            } else {
                unlink($tempFile);
                
                return [
                    'data' => false,
                    'http_code' => 0,
                    'error' => 'wget failed: ' . $output,
                    'success' => false
                ];
            }
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            
            return [
                'data' => false,
                'http_code' => 0,
                'error' => 'wget exception: ' . $e->getMessage(),
                'success' => false
            ];
        }
    }

    /**
     * Обработка изображения с различными типами изменения размера
     */
    private function processImage($filePath, $resize, $width, $height)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (!$imageInfo) {
                return;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            // Если размеры не заданы, используем оригинальные
            if (!$width || !$height) {
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
            
            if (!$sourceImage) {
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
            \Illuminate\Support\Facades\Log::warning('Ошибка обработки изображения: ' . $e->getMessage());
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
        return $setting ? (int)$setting->value : 500;
    }
    
    /**
     * Получить системную высоту изображений товаров
     */
    private function getSystemImageHeight()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_height')->first();
        return $setting ? (int)$setting->value : 500;
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
            'data.*' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
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
                'item' => $item
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results
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
            'user_agent' => request()->userAgent()
        ]);
    }

    /**
     * Изменение размера изображения
     */
    private function resizeImageFile($imagePath, $width, $height, $resizeType)
    {
        try {
            if (!file_exists($imagePath)) {
                return false;
            }

            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
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

            if (!$sourceImage) {
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

            if (!$newImage) {
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
}
