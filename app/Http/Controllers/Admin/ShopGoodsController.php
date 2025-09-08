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
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
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
            'category_ids.*' => 'exists:shop_categories,id',
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
            'category_ids.*' => 'exists:shop_categories,id',
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
            $storagePath = $request->input('storagePath');
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
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Скачиваем изображение
            $imageData = file_get_contents($imageUrl);
            if ($imageData === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось скачать изображение'
                ], 400);
            }

            // Проверка размера файла (максимум 10MB)
            if (strlen($imageData) > 10 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл слишком большой (максимум 10MB)'
                ], 400);
            }

            // Сохраняем файл
            file_put_contents($storageFullPath, $imageData);

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
            'imageUrls' => 'required|array|min:1|max:50', // Максимум 50 изображений за раз
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
            $storagePath = $request->input('storagePath');
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            $results = [];
            $errors = [];

            // Обрабатываем изображения параллельно
            $promises = [];
            foreach ($imageUrls as $index => $imageUrl) {
                $promises[] = $this->downloadSingleImage(
                    $imageUrl,
                    $storagePath,
                    $optimize,
                    $naming,
                    $resize,
                    $width,
                    $height,
                    $index
                );
            }

            // Выполняем все запросы параллельно
            $responses = $promises;
            foreach ($responses as $response) {
                if ($response['success']) {
                    $results[$response['originalUrl']] = $response['path'];
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
                    'total' => count($imageUrls),
                    'successful' => count($results),
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
            
            // Полный путь для сохранения
            $fullPath = $storagePath . '/' . $fileName;
            $storageFullPath = storage_path('app/public' . $fullPath);
            
            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Скачиваем изображение
            $imageData = file_get_contents($imageUrl);
            if ($imageData === false) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Не удалось скачать изображение'
                ];
            }

            // Проверка размера файла (максимум 10MB)
            if (strlen($imageData) > 10 * 1024 * 1024) {
                return [
                    'success' => false,
                    'originalUrl' => $imageUrl,
                    'error' => 'Файл слишком большой (максимум 10MB)'
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
}
