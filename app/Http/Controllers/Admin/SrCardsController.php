<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SrCard;
use App\Models\SrCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SrCardsController extends Controller
{
    /**
     * Получить список карт
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = SrCard::with('categories');

            // Фильтр по категории
            if ($request->filled('category_id')) {
                $query->whereHas('categories', function ($q) use ($request) {
                    $q->where('sr_categories.id', $request->category_id);
                });
            }

            $cards = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $cards
            ]);
        } catch (\Exception $e) {
            Log::error('SrCardsController::index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения карт: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать карту
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string|max:500',
                'category_ids' => 'nullable|array',
                'category_ids.*' => 'exists:sr_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $card = SrCard::create([
                'name' => $request->name,
                'description' => $request->description,
                'image' => $request->image
            ]);

            // Привязываем категории
            if ($request->filled('category_ids')) {
                $card->categories()->sync($request->category_ids);
            }

            $card->load('categories');

            return response()->json([
                'success' => true,
                'message' => 'Карта успешно создана',
                'data' => $card
            ], 201);
        } catch (\Exception $e) {
            Log::error('SrCardsController::store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания карты: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить карту
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $card = SrCard::find($id);

            if (!$card) {
                return response()->json([
                    'success' => false,
                    'message' => 'Карта не найдена'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string|max:500',
                'category_ids' => 'nullable|array',
                'category_ids.*' => 'exists:sr_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Обновляем основные поля
            $card->update($request->only(['name', 'description', 'image']));

            // Обновляем категории, если переданы
            if ($request->has('category_ids')) {
                $card->categories()->sync($request->category_ids ?? []);
            }

            $card->load('categories');

            return response()->json([
                'success' => true,
                'message' => 'Карта успешно обновлена',
                'data' => $card
            ]);
        } catch (\Exception $e) {
            Log::error('SrCardsController::update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления карты: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить карту
     */
    public function destroy($id): JsonResponse
    {
        try {
            $card = SrCard::find($id);

            if (!$card) {
                return response()->json([
                    'success' => false,
                    'message' => 'Карта не найдена'
                ], 404);
            }

            // Удаляем изображение, если оно есть
            if ($card->image) {
                $frontendPublicPath = frontend_public_path();
                $imagePath = $frontendPublicPath . '/' . ltrim($card->image, '/');
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                }
            }

            $card->delete();

            return response()->json([
                'success' => true,
                'message' => 'Карта успешно удалена'
            ]);
        } catch (\Exception $e) {
            Log::error('SrCardsController::destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления карты: ' . $e->getMessage()
            ], 500);
        }
    }
}
