<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShopBrandsController extends Controller
{
    /**
     * Получить список брендов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopBrand::query();

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

        $brands = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $brands->items(),
            'pagination' => [
                'current_page' => $brands->currentPage(),
                'last_page' => $brands->lastPage(),
                'per_page' => $brands->perPage(),
                'total' => $brands->total()
            ]
        ]);
    }

    /**
     * Получить бренд по ID
     */
    public function show($id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $brand
        ]);
    }

    /**
     * Создать новый бренд
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_brands,slug',
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

        $brand = ShopBrand::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно создан',
            'data' => $brand
        ], 201);
    }

    /**
     * Обновить бренд
     */
    public function update(Request $request, $id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_brands', 'slug')->ignore($id)],
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

        $brand->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно обновлен',
            'data' => $brand
        ]);
    }

    /**
     * Удалить бренд
     */
    public function destroy($id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);
        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно удален'
        ]);
    }

    /**
     * Получить все активные бренды для селекта
     */
    public function active(): JsonResponse
    {
        $brands = ShopBrand::active()->ordered()->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $brands
        ]);
    }
}
