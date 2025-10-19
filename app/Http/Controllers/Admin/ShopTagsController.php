<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopTag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShopTagsController extends Controller
{
    /**
     * Получить список тегов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopTag::query();

        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Фильтр по статусу
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortBy, ['name', 'created_at', 'sort_order'])) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $tags = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $tags->items(),
            'pagination' => [
                'current_page' => $tags->currentPage(),
                'last_page' => $tags->lastPage(),
                'per_page' => $tags->perPage(),
                'total' => $tags->total()
            ]
        ]);
    }

    /**
     * Получить тег по ID
     */
    public function show($id): JsonResponse
    {
        $tag = ShopTag::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $tag
        ]);
    }

    /**
     * Создать новый тег
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'slug' => 'nullable|string|max:255|unique:shop_tags,slug',
            'is_active' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $tag = ShopTag::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Тег успешно создан',
            'data' => $tag
        ], 201);
    }

    /**
     * Обновить тег
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tag = ShopTag::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_tags', 'slug')->ignore($id)],
            'is_active' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $tag->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Тег успешно обновлен',
            'data' => $tag
        ]);
    }

    /**
     * Удалить тег
     */
    public function destroy($id): JsonResponse
    {
        $tag = ShopTag::findOrFail($id);
        $tag->delete();

        return response()->json([
            'success' => true,
            'message' => 'Тег успешно удален'
        ]);
    }

    /**
     * Получить все активные теги для селекта
     */
    public function active(): JsonResponse
    {
        $tags = ShopTag::active()->ordered()->get(['id', 'name', 'color']);

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }
}
