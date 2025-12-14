<?php

namespace App\Http\Controllers;

use App\Models\ShopCategory;
use App\Models\Shop\Property;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /**
     * Получить список всех категорий
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $withRelations = ['parent'];
            if (Schema::hasTable('shop_category_extra_menus')) {
                $withRelations[] = 'extraMenu';
            }
            $query = ShopCategory::with($withRelations);

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            // Фильтр по slug (для проверки уникальности)
            if ($request->filled('slug')) {
                $slug = $request->get('slug');
                $query->where('slug', $slug);
            }

            // Фильтр по статусу
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Фильтр по parent_id (для получения только корневых категорий)
            if ($request->has('parent_id')) {
                $parentId = $request->get('parent_id');
                if ($parentId === 'null' || $parentId === null) {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', $parentId);
                }
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'sort_order');
            $sortDirection = $request->get('sort_direction', 'asc');
            
            if (in_array($sortBy, ['name', 'created_at', 'sort_order'])) {
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->ordered();
            }

            $categories = $query->get();

            // Добавляем счетчики характеристик для каждой категории
            $categories = $categories->map(function ($category) {
                $propertiesCount = DB::table('shop_category_property')
                    ->where('category_id', $category->id)
                    ->count();
                
                $category->properties_count = $propertiesCount;
                return $category;
            });

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить активные категории
     */
    public function active(Request $request): JsonResponse
    {
        try {
            $withRelations = ['parent'];
            if (Schema::hasTable('shop_category_extra_menus')) {
                $withRelations[] = 'extraMenu';
            }
            $query = ShopCategory::with($withRelations)->active();

            // Поиск
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            $categories = $query->ordered()->get();

            // Добавляем счетчики характеристик для каждой категории
            $categories = $categories->map(function ($category) {
                $propertiesCount = DB::table('shop_category_property')
                    ->where('category_id', $category->id)
                    ->count();
                
                $category->properties_count = $propertiesCount;
                return $category;
            });

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении активных категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить категорию по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $withRelations = ['parent', 'children'];
            if (Schema::hasTable('shop_category_extra_menus')) {
                $withRelations[] = 'extraMenu';
            }
            $category = ShopCategory::with($withRelations)->find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Добавляем счетчик характеристик
            $category->properties_count = DB::table('shop_category_property')
                ->where('category_id', $category->id)
                ->count();

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новую категорию
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'slug' => 'nullable|string|max:255|unique:shop_categories,slug',
                'is_active' => 'boolean',
                'is_main' => 'boolean',
                'in_catalog' => 'boolean',
                'in_figure' => 'boolean',
                'in_figure_img' => 'nullable|string',
                'in_figure_text' => 'nullable|string',
                'sort_order' => 'integer|min:0',
                'parent_id' => 'nullable|integer|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Подготавливаем данные для создания
            $data = $request->all();
            
            // Обрабатываем parent_id - если передано null или пустая строка, устанавливаем null
            if (isset($data['parent_id']) && ($data['parent_id'] === '' || $data['parent_id'] === null)) {
                $data['parent_id'] = null;
            }

            // Автоматически генерируем slug из названия, если не передан
            if (empty($data['slug'])) {
                $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
                
                // Проверяем уникальность slug
                $counter = 1;
                $originalSlug = $data['slug'];
                while (ShopCategory::where('slug', $data['slug'])->exists()) {
                    $data['slug'] = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }

            $category = ShopCategory::create($data);

            // Загружаем связанные данные для ответа
            $category->load('parent');

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $category
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить категорию
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $category = ShopCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'slug' => 'nullable|string|max:255|unique:shop_categories,slug,' . $id,
                'is_active' => 'boolean',
                'is_main' => 'boolean',
                'in_catalog' => 'boolean',
                'in_figure' => 'boolean',
                'in_figure_img' => 'nullable|string',
                'in_figure_text' => 'nullable|string',
                'sort_order' => 'integer|min:0',
                'parent_id' => 'nullable|integer|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Подготавливаем данные для обновления
            $data = $request->all();
            
            // Обрабатываем parent_id - если передано null или пустая строка, устанавливаем null
            if (isset($data['parent_id']) && ($data['parent_id'] === '' || $data['parent_id'] === null)) {
                $data['parent_id'] = null;
            }

            // Автоматически генерируем slug из названия, если не передан и изменилось название
            if (empty($data['slug']) && isset($data['name']) && $data['name'] !== $category->name) {
                $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
                
                // Проверяем уникальность slug
                $counter = 1;
                $originalSlug = $data['slug'];
                while (ShopCategory::where('slug', $data['slug'])->where('id', '!=', $id)->exists()) {
                    $data['slug'] = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }

            $category->update($data);

            // Загружаем связанные данные для ответа
            $category->load('parent');

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно обновлена',
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить категорию
     */
    public function destroy($id): JsonResponse
    {
        try {
            $category = ShopCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Проверяем, есть ли дочерние категории
            if ($category->children()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить категорию, у которой есть дочерние категории'
                ], 400);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно удалена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Сортировать категории по алфавиту
     */
    public function sortAlphabetically(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'direction' => 'required|in:asc,desc'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $direction = $request->input('direction');
            
            // Получаем все категории и сортируем по названию
            $categories = ShopCategory::orderBy('name', $direction)->get();
            
            // Обновляем sort_order для каждой категории
            foreach ($categories as $index => $category) {
                $category->update(['sort_order' => $index]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Категории отсортированы по алфавиту',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при сортировке категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузить изображение категории
     */
    public function uploadImage(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('image');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('categories', $fileName, 'public');

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => [
                    'path' => $path,
                    'url' => Storage::url($path)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при загрузке изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Импорт категорий из файла
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'categories' => 'required|array',
                'categories.*.name' => 'required|string|max:255',
                'categories.*.slug' => 'required|string|max:255',
                'categories.*.description' => 'nullable|string',
                'categories.*.parent' => 'nullable|string',
                'categories.*.image_url' => 'nullable|url'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $categoriesData = $request->input('categories');
            $created = 0;
            $updated = 0;
            $errors = [];

            // Получаем путь к фронтенду
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $imagesPath = $frontendPath . '/public/images/categories';

            foreach ($categoriesData as $index => $categoryData) {
                try {
                    // Проверяем, существует ли категория с таким именем
                    $existingCategory = ShopCategory::where('name', $categoryData['name'])->first();

                    // Ищем родительскую категорию по имени
                    $parentId = null;
                    if (!empty($categoryData['parent'])) {
                        $parentCategory = ShopCategory::where('name', $categoryData['parent'])->first();
                        if ($parentCategory) {
                            $parentId = $parentCategory->id;
                        }
                    }

                    // Подготавливаем данные
                    $data = [
                        'name' => $categoryData['name'],
                        'slug' => $categoryData['slug'],
                        'description' => $categoryData['description'] ?? null,
                        'parent_id' => $parentId,
                        'is_active' => true
                    ];

                    if ($existingCategory) {
                        // Обновляем существующую категорию
                        $existingCategory->slug = $categoryData['slug'];
                        $existingCategory->description = $categoryData['description'] ?? $existingCategory->description;
                        $existingCategory->parent_id = $parentId ?? $existingCategory->parent_id;

                        // Загружаем изображение, если есть ссылка
                        if (!empty($categoryData['image_url'])) {
                            try {
                                $imageData = file_get_contents($categoryData['image_url']);
                                if ($imageData !== false) {
                                    // Создаем директорию, если её нет
                                    if (!is_dir($imagesPath)) {
                                        mkdir($imagesPath, 0755, true);
                                    }

                                    // Определяем расширение
                                    $extension = 'png';
                                    $imageInfo = @getimagesizefromstring($imageData);
                                    if ($imageInfo !== false) {
                                        $mimeType = $imageInfo['mime'];
                                        if ($mimeType === 'image/jpeg') $extension = 'jpg';
                                        elseif ($mimeType === 'image/gif') $extension = 'gif';
                                        elseif ($mimeType === 'image/webp') $extension = 'webp';
                                    }

                                    // Сохраняем изображение
                                    $fileName = 'category_' . $existingCategory->id . '.' . $extension;
                                    $filePath = $imagesPath . '/' . $fileName;
                                    file_put_contents($filePath, $imageData);

                                    // Обновляем путь к изображению
                                    $existingCategory->image = 'images/categories/' . $fileName;
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Категория \"{$categoryData['name']}\": ошибка загрузки изображения - {$e->getMessage()}";
                            }
                        }

                        $existingCategory->save();
                        $updated++;
                    } else {
                        // Создаем новую категорию
                        $newCategory = ShopCategory::create($data);

                        // Загружаем изображение, если есть ссылка
                        if (!empty($categoryData['image_url'])) {
                            try {
                                $imageData = file_get_contents($categoryData['image_url']);
                                if ($imageData !== false) {
                                    // Создаем директорию, если её нет
                                    if (!is_dir($imagesPath)) {
                                        mkdir($imagesPath, 0755, true);
                                    }

                                    // Определяем расширение
                                    $extension = 'png';
                                    $imageInfo = @getimagesizefromstring($imageData);
                                    if ($imageInfo !== false) {
                                        $mimeType = $imageInfo['mime'];
                                        if ($mimeType === 'image/jpeg') $extension = 'jpg';
                                        elseif ($mimeType === 'image/gif') $extension = 'gif';
                                        elseif ($mimeType === 'image/webp') $extension = 'webp';
                                    }

                                    // Сохраняем изображение
                                    $fileName = 'category_' . $newCategory->id . '.' . $extension;
                                    $filePath = $imagesPath . '/' . $fileName;
                                    file_put_contents($filePath, $imageData);

                                    // Обновляем путь к изображению
                                    $newCategory->image = 'images/categories/' . $fileName;
                                    $newCategory->save();
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Категория \"{$categoryData['name']}\": ошибка загрузки изображения - {$e->getMessage()}";
                            }
                        }

                        $created++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Строка " . ($index + 1) . " (\"{$categoryData['name']}\"): {$e->getMessage()}";
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => $errors,
                    'total' => count($categoriesData)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при импорте категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Батч-добавление категорий к родительской категории (только обновляет parent_id)
     */
    public function addToParent(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'category_ids' => 'required|array|min:1',
                'category_ids.*' => 'required|integer|exists:shop_categories,id',
                'parent_id' => 'required|integer|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $categoryIds = $request->input('category_ids');
            $parentId = $request->input('parent_id');

            // Проверяем, что родительская категория не находится среди обновляемых категорий
            if (in_array($parentId, $categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская категория не может быть среди обновляемых категорий'
                ], 422);
            }

            // Проверяем, что не создается циклическая зависимость
            $parentCategory = ShopCategory::find($parentId);
            if ($parentCategory) {
                $parentPath = $this->getCategoryPath($parentCategory);
                foreach ($categoryIds as $categoryId) {
                    if (in_array($categoryId, $parentPath)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Невозможно создать циклическую зависимость'
                        ], 422);
                    }
                }
            }

            // Обновляем parent_id для всех категорий одним запросом
            $updated = ShopCategory::whereIn('id', $categoryIds)
                ->update(['parent_id' => $parentId]);

            return response()->json([
                'success' => true,
                'message' => "Успешно добавлено категорий к родительской категории: {$updated}",
                'data' => [
                    'updated' => $updated,
                    'total' => count($categoryIds)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при добавлении категорий к родительской категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Батч-обновление категорий
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'category_ids' => 'required|array|min:1',
                'category_ids.*' => 'required|integer|exists:shop_categories,id',
                'parent_id' => 'required|integer|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $categoryIds = $request->input('category_ids');
            $parentId = $request->input('parent_id');

            // Проверяем, что родительская категория не находится среди обновляемых категорий
            if (in_array($parentId, $categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская категория не может быть среди обновляемых категорий'
                ], 422);
            }

            // Проверяем, что не создается циклическая зависимость
            $parentCategory = ShopCategory::find($parentId);
            if ($parentCategory) {
                $parentPath = $this->getCategoryPath($parentCategory);
                foreach ($categoryIds as $categoryId) {
                    if (in_array($categoryId, $parentPath)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Невозможно создать циклическую зависимость'
                        ], 422);
                    }
                }
            }

            // Обновляем parent_id для всех категорий одним запросом
            $updated = ShopCategory::whereIn('id', $categoryIds)
                ->update(['parent_id' => $parentId]);

            return response()->json([
                'success' => true,
                'message' => "Успешно обновлено категорий: {$updated}",
                'data' => [
                    'updated' => $updated,
                    'total' => count($categoryIds)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при батч-обновлении категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить путь категории (все родительские категории)
     */
    private function getCategoryPath(ShopCategory $category): array
    {
        $path = [$category->id];
        $current = $category;

        while ($current->parent_id) {
            $current = ShopCategory::find($current->parent_id);
            if (!$current) {
                break;
            }
            $path[] = $current->id;
        }

        return $path;
    }

    /**
     * Получить дерево категорий
     */
    public function tree(): JsonResponse
    {
        try {
            $categories = ShopCategory::with('children')
                ->whereNull('parent_id')
                ->active()
                ->ordered()
                ->get();

            // Рекурсивно строим дерево
            $buildTree = function ($categories) use (&$buildTree) {
                return $categories->map(function ($category) use ($buildTree) {
                    $children = ShopCategory::where('parent_id', $category->id)
                        ->active()
                        ->ordered()
                        ->get();
                    
                    $category->children = $children->isEmpty() ? [] : $buildTree($children);
                    
                    // Добавляем счетчик характеристик
                    $category->properties_count = DB::table('shop_category_property')
                        ->where('category_id', $category->id)
                        ->count();
                    
                    return $category;
                });
            };

            $tree = $buildTree($categories);

            return response()->json([
                'success' => true,
                'categories' => $tree
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения дерева категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить характеристики категории
     */
    public function getProperties($id): JsonResponse
    {
        try {
            $category = ShopCategory::findOrFail($id);
            $properties = $category->properties()->get();
            
            return response()->json([
                'success' => true,
                'properties' => $properties
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения характеристик: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить характеристики нескольких категорий с их значениями (оптимизированный запрос)
     * Принимает массив ID категорий через параметр ids[] или ids
     */
    public function getCategoriesProperties(Request $request): JsonResponse
    {
        try {
            // Получаем ID категорий из запроса
            $categoryIds = [];
            if ($request->has('ids')) {
                $ids = $request->input('ids');
                if (is_array($ids)) {
                    $categoryIds = array_map('intval', $ids);
                } else {
                    $categoryIds = [intval($ids)];
                }
            } elseif ($request->has('ids[]')) {
                $ids = $request->input('ids[]');
                if (is_array($ids)) {
                    $categoryIds = array_map('intval', $ids);
                } else {
                    $categoryIds = [intval($ids)];
                }
            }

            if (empty($categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID категорий'
                ], 400);
            }

            // Фильтруем только валидные ID
            $categoryIds = array_filter($categoryIds, function($id) {
                return $id > 0;
            });

            if (empty($categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некорректные ID категорий'
                ], 400);
            }

            // Оптимизированный запрос: получаем все уникальные характеристики категорий одним запросом
            // с eager loading значений
            $properties = Property::whereHas('categories', function($query) use ($categoryIds) {
                $query->whereIn('shop_categories.id', $categoryIds);
            })
            ->with(['activeValues' => function($query) {
                $query->orderBy('sort_order')->orderBy('value');
            }])
            ->orderBy('name')
            ->get();

            // Формируем результат с информацией о категориях, к которым привязана каждая характеристика
            $result = $properties->map(function($property) use ($categoryIds) {
                // Получаем ID категорий, к которым привязана эта характеристика
                $propertyCategoryIds = DB::table('shop_category_property')
                    ->where('property_id', $property->id)
                    ->whereIn('category_id', $categoryIds)
                    ->pluck('category_id')
                    ->toArray();

                // Получаем названия категорий
                $categoryNames = ShopCategory::whereIn('id', $propertyCategoryIds)
                    ->pluck('name')
                    ->toArray();

                return [
                    'id' => $property->id,
                    'name' => $property->name,
                    'slug' => $property->slug,
                    'property_type' => $property->property_type,
                    'description' => $property->description,
                    'category_ids' => $propertyCategoryIds,
                    'category_names' => $categoryNames,
                    'values' => $property->activeValues->map(function($value) {
                        return [
                            'id' => $value->id,
                            'value' => $value->value,
                            'color' => $value->color,
                            'sort_order' => $value->sort_order
                        ];
                    })->toArray()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения характеристик категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Привязать характеристики к категории
     */
    public function syncProperties(Request $request, $id): JsonResponse
    {
        // Разрешаем пустой массив - это означает удаление всех характеристик
        $validator = Validator::make($request->all(), [
            'property_ids' => 'nullable|array',
            'property_ids.*' => 'required_with:property_ids|integer|exists:shop_properties,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $category = ShopCategory::findOrFail($id);
            // Если property_ids не передан или null, используем пустой массив (удаление всех связей)
            $propertyIds = $request->input('property_ids', []);
            $category->properties()->sync($propertyIds);
            
            $properties = $category->properties()->get();
            
            // Обновляем счетчик характеристик
            $category->properties_count = $properties->count();
            
            return response()->json([
                'success' => true,
                'message' => 'Характеристики успешно привязаны',
                'properties' => $properties,
                'properties_count' => $category->properties_count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка привязки характеристик: ' . $e->getMessage()
            ], 500);
        }
    }
}
