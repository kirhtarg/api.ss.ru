<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SrCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SrCategoriesController extends Controller
{
    /**
     * Получить список категорий
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $categories = SrCategory::withCount('cards as cards_count')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('SrCategoriesController::index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения категорий: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать категорию
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'image' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category = SrCategory::create([
                'name' => $request->name,
                'description' => $request->description,
                'icon' => $request->icon,
                'image' => $request->image
            ]);

            $category->loadCount('cards');

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $category
            ], 201);
        } catch (\Exception $e) {
            Log::error('SrCategoriesController::store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить категорию
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $category = SrCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'image' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category->update($request->only(['name', 'description', 'icon', 'image']));
            $category->loadCount('cards');

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно обновлена',
                'data' => $category
            ]);
        } catch (\Exception $e) {
            Log::error('SrCategoriesController::update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления категории: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить категорию
     */
    public function destroy($id): JsonResponse
    {
        try {
            $category = SrCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Удаляем изображение, если оно есть
            if ($category->image) {
                $frontendPublicPath = frontend_public_path();
                $imagePath = $frontendPublicPath . '/' . ltrim($category->image, '/');
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                }
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно удалена'
            ]);
        } catch (\Exception $e) {
            Log::error('SrCategoriesController::destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления категории: ' . $e->getMessage()
            ], 500);
        }
    }
}
