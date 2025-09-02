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
            'properties:id,name',
            'mainImage:id,good_id,file_path,is_main',
            'variations:id,good_id,name,price,sale_price,stock_quantity,is_active'
        ]);

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
            $query->inStock();
        }

        // Фильтр по статусу
        if ($request->has('is_active')) {
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
            'videos:id,good_id,variation_id,video_path,video_url,title,is_main,sort_order',
            'variations:id,good_id,name,description,price,sale_price,stock_quantity,sku,is_active,sort_order',
            'variations.attributeValues:id,value,color',
            'variations.attributeValues.attribute:id,name',
            'stock:id,good_id,warehouse_id,quantity,reserved_quantity,min_quantity',
            'stock.warehouse:id,name',
            'prices:id,good_id,price_type_id,price,sale_price',
            'prices.priceType:id,name,multiplier'
        ])->findOrFail($id);

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
            'category_ids.*' => 'exists:categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'exists:shop_properties,id',
            'properties.*.value' => 'required|string'
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
                    $good->properties()->attach($property['property_id'], [
                        'value' => $property['value']
                    ]);
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
            'category_ids.*' => 'exists:categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'exists:shop_properties,id',
            'properties.*.value' => 'required|string'
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

            // Обновление свойств
            if ($request->has('properties')) {
                $good->properties()->detach();
                foreach ($request->get('properties', []) as $property) {
                    $good->properties()->attach($property['property_id'], [
                        'value' => $property['value']
                    ]);
                }
            }

            // Аудит
            $this->logAudit($good, 'updated', $oldValues, $good->fresh()->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Товар успешно обновлен',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties'])
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
        $properties = ShopProperty::filterable()->ordered()->get(['id', 'name']);

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
     * Логирование аудита
     */
    private function logAudit($good, $action, $oldValues, $newValues)
    {
        $good->audit()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }
}
