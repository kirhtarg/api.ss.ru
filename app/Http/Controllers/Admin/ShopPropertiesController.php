<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopProperty;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShopPropertiesController extends Controller
{
    /**
     * Получить список свойств
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopProperty::query();

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

        $properties = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $properties->items(),
            'pagination' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total()
            ]
        ]);
    }

    /**
     * Получить свойство по ID
     */
    public function show($id): JsonResponse
    {
        $property = ShopProperty::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $property
        ]);
    }

    /**
     * Создать новое свойство
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_properties,slug',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $property = ShopProperty::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Свойство успешно создано',
            'data' => $property
        ], 201);
    }

    /**
     * Обновить свойство
     */
    public function update(Request $request, $id): JsonResponse
    {
        $property = ShopProperty::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_properties', 'slug')->ignore($id)],
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $property->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Свойство успешно обновлено',
            'data' => $property
        ]);
    }

    /**
     * Удалить свойство
     */
    public function destroy($id): JsonResponse
    {
        $property = ShopProperty::findOrFail($id);
        $property->delete();

        return response()->json([
            'success' => true,
            'message' => 'Свойство успешно удалено'
        ]);
    }

    /**
     * Получить все активные свойства для селекта
     */
    public function active(): JsonResponse
    {
        $properties = ShopProperty::active()->ordered()->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $properties
        ]);
    }
}
