<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promocode;
use App\Models\Category;
use App\Models\Good;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PromocodeController extends Controller
{
    /**
     * Получить список промокодов
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('PromocodeController@index called');
        \Log::info('Request data: ' . json_encode($request->all()));
        
        try {
            $query = Promocode::query();

            // Поиск
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Фильтр по типу
            if ($request->has('type') && $request->type !== '' && $request->type !== null) {
                $query->where('type', $request->type);
            }

            // Фильтр по статусу
            if ($request->has('is_active') && $request->is_active !== '' && $request->is_active !== null) {
                $query->where('is_active', $request->is_active);
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('per_page', 15);
            
            // Отладка: проверим, сколько промокодов в базе
            $totalCount = Promocode::count();
            \Log::info('Total promocodes in database: ' . $totalCount);
            
            $promocodes = $query->paginate($perPage);
            
            \Log::info('Query result count: ' . $promocodes->count());
            \Log::info('Query SQL: ' . $query->toSql());
            \Log::info('Query bindings: ' . json_encode($query->getBindings()));

            return response()->json([
                'success' => true,
                'data' => $promocodes
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in PromocodeController@index: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки промокодов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить промокод по ID
     */
    public function show(Promocode $promocode): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $promocode
        ]);
    }

    /**
     * Создать новый промокод
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:promocodes,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(['percentage', 'fixed_amount', 'free_delivery'])],
            'value' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'applicable_categories' => 'nullable|array',
            'applicable_categories.*' => 'integer|exists:categories,id',
            'applicable_goods' => 'nullable|array',
            'applicable_goods.*' => 'integer|exists:goods,id',
            'applicable_variations' => 'nullable|array',
            'applicable_variations.*' => 'integer|exists:good_variations,id',
        ]);

        // Валидация value для определенных типов
        if (in_array($validated['type'], ['percentage', 'fixed_amount']) && !$validated['value']) {
            return response()->json([
                'success' => false,
                'message' => 'Поле "Значение" обязательно для выбранного типа промокода'
            ], 422);
        }

        $promocode = Promocode::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно создан',
            'data' => $promocode
        ], 201);
    }

    /**
     * Обновить промокод
     */
    public function update(Request $request, Promocode $promocode): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('promocodes', 'code')->ignore($promocode->id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(['percentage', 'fixed_amount', 'free_delivery'])],
            'value' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'applicable_categories' => 'nullable|array',
            'applicable_categories.*' => 'integer|exists:categories,id',
            'applicable_goods' => 'nullable|array',
            'applicable_goods.*' => 'integer|exists:goods,id',
            'applicable_variations' => 'nullable|array',
            'applicable_variations.*' => 'integer|exists:good_variations,id',
        ]);

        // Валидация value для определенных типов
        if (in_array($validated['type'], ['percentage', 'fixed_amount']) && !$validated['value']) {
            return response()->json([
                'success' => false,
                'message' => 'Поле "Значение" обязательно для выбранного типа промокода'
            ], 422);
        }

        $promocode->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно обновлен',
            'data' => $promocode
        ]);
    }

    /**
     * Удалить промокод
     */
    public function destroy(Promocode $promocode): JsonResponse
    {
        // Проверяем, есть ли использования промокода
        if ($promocode->usages()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить промокод, который уже использовался'
            ], 422);
        }

        $promocode->delete();

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно удален'
        ]);
    }

    /**
     * Получить статистику использования промокода
     */
    public function stats(Promocode $promocode): JsonResponse
    {
        try {
            // Упрощенная статистика без сложных запросов
            $stats = [
                'total_usage' => $promocode->used_count,
                'recent_usage' => 0, // Пока оставляем 0
                'usage_by_period' => [], // Пока пустой массив
            ];

            // Пытаемся получить статистику по использованию, если связь работает
            try {
                $recentUsage = $promocode->usages()
                    ->where('used_at', '>=', Carbon::now()->subDays(30))
                    ->count();
                $stats['recent_usage'] = $recentUsage;
            } catch (\Exception $e) {
                \Log::warning('Could not load recent usage stats: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in PromocodeController@stats: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статистики: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить данные для селектов (категории, товары)
     */
    public function getSelectData(): JsonResponse
    {
        $categories = Category::select('id', 'name')->get();
        $goods = Good::select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'goods' => $goods
            ]
        ]);
    }
}
