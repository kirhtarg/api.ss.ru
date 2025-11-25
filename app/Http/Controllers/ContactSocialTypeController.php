<?php

namespace App\Http\Controllers;

use App\Models\ContactSocialType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ContactSocialTypeController extends Controller
{
    /**
     * Получить список всех типов социальных сетей
     */
    public function index(): JsonResponse
    {
        $types = ContactSocialType::orderBy('social')->get();

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Создать новый тип социальной сети
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'social' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $type = ContactSocialType::create([
                'social' => $request->social,
                'icon' => $request->icon ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Тип социальной сети успешно создан',
                'data' => $type
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании типа социальной сети: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить тип социальной сети
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = ContactSocialType::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Тип социальной сети не найден'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'social' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $type->update([
                'social' => $request->social,
                'icon' => $request->icon ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Тип социальной сети успешно обновлен',
                'data' => $type
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении типа социальной сети: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить тип социальной сети
     */
    public function destroy(int $id): JsonResponse
    {
        $type = ContactSocialType::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Тип социальной сети не найден'
            ], 404);
        }

        try {
            $type->delete();

            return response()->json([
                'success' => true,
                'message' => 'Тип социальной сети успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении типа социальной сети: ' . $e->getMessage()
            ], 500);
        }
    }
}

