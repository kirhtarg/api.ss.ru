<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShopLabelsController extends Controller
{
    /**
     * Получить список лейблов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopLabel::query();

        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortBy, ['name', 'created_at', 'sort_order'])) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $labels = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $labels->items(),
            'pagination' => [
                'current_page' => $labels->currentPage(),
                'last_page' => $labels->lastPage(),
                'per_page' => $labels->perPage(),
                'total' => $labels->total(),
            ],
        ]);
    }

    /**
     * Получить лейбл по ID
     */
    public function show($id): JsonResponse
    {
        $label = ShopLabel::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $label,
        ]);
    }

    /**
     * Создать новый лейбл
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $label = ShopLabel::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Лейбл успешно создан',
            'data' => $label,
        ], 201);
    }

    /**
     * Обновить лейбл
     */
    public function update(Request $request, $id): JsonResponse
    {
        $label = ShopLabel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $label->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Лейбл успешно обновлен',
            'data' => $label,
        ]);
    }

    /**
     * Удалить лейбл
     */
    public function destroy($id): JsonResponse
    {
        $label = ShopLabel::findOrFail($id);
        $label->delete();

        return response()->json([
            'success' => true,
            'message' => 'Лейбл успешно удален',
        ]);
    }

    /**
     * Получить все лейблы для селекта
     */
    public function all(): JsonResponse
    {
        $labels = ShopLabel::ordered()->get(['id', 'name', 'color']);

        return response()->json([
            'success' => true,
            'data' => $labels,
        ]);
    }
}
