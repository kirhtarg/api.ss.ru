<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopSupplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ShopSuppliersController extends Controller
{
    /**
     * Получить список поставщиков
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopSupplier::query();

        // Фильтрация по активности
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Поиск по названию
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Если нужны только активные для выпадающего списка
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $suppliers = $query->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Получить поставщика по ID
     */
    public function show($id): JsonResponse
    {
        $supplier = ShopSupplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Поставщик не найден'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $supplier
        ]);
    }

    /**
     * Создать нового поставщика
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_suppliers,slug',
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
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

        $data = $validator->validated();
        
        // Генерируем slug, если не указан
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
            
            // Проверяем уникальность slug
            $counter = 1;
            $baseSlug = $data['slug'];
            while (ShopSupplier::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $baseSlug . '-' . $counter;
                $counter++;
            }
        }

        $supplier = ShopSupplier::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Поставщик успешно создан',
            'data' => $supplier
        ], 201);
    }

    /**
     * Обновить поставщика
     */
    public function update(Request $request, $id): JsonResponse
    {
        $supplier = ShopSupplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Поставщик не найден'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255|unique:shop_suppliers,slug,' . $id,
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
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

        $data = $validator->validated();
        
        // Генерируем slug, если имя изменилось и slug не указан
        if (isset($data['name']) && $data['name'] !== $supplier->name && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
            
            // Проверяем уникальность slug
            $counter = 1;
            $baseSlug = $data['slug'];
            while (ShopSupplier::where('slug', $data['slug'])->where('id', '!=', $id)->exists()) {
                $data['slug'] = $baseSlug . '-' . $counter;
                $counter++;
            }
        }

        $supplier->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Поставщик успешно обновлен',
            'data' => $supplier
        ]);
    }

    /**
     * Удалить поставщика
     */
    public function destroy($id): JsonResponse
    {
        $supplier = ShopSupplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Поставщик не найден'
            ], 404);
        }

        // Проверяем, есть ли товары с этим поставщиком
        $goodsCount = $supplier->goods()->count();
        
        if ($goodsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Невозможно удалить поставщика. С ним связано {$goodsCount} товаров."
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Поставщик успешно удален'
        ]);
    }
}

