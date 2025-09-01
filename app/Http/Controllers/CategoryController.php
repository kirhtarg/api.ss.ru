<?php

namespace App\Http\Controllers;

use App\Models\ShopCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    /**
     * Получить список всех категорий
     */
    public function index(): JsonResponse
    {
        try {
            $categories = ShopCategory::with('parent')
                ->ordered()
                ->get();

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
    public function active(): JsonResponse
    {
        try {
            $categories = ShopCategory::with('parent')
                ->active()
                ->ordered()
                ->get();

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
            $category = ShopCategory::with(['parent', 'children'])->find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

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
                'sort_order' => 'integer|min:0',
                'parent_id' => 'nullable|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category = ShopCategory::create($request->all());

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
                'sort_order' => 'integer|min:0',
                'parent_id' => 'nullable|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category->update($request->all());

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
}
