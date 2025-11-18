<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promocode;
use App\Models\ShopCategory;
use App\Models\ShopGood;
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
            
            $promocodes = $query->with(['categories', 'goods', 'users'])->paginate($perPage);
            
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
    public function show($id): JsonResponse
    {
        $promocode = Promocode::find($id);
        
        if (!$promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден'
            ], 404);
        }
        
        $promocode->load(['categories', 'goods', 'users']);
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
            'applicable_categories.*' => 'integer|exists:shop_categories,id',
            'applicable_goods' => 'nullable|array',
            'applicable_goods.*' => 'integer|exists:shop_goods,id',
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

        // Извлекаем связи для обработки отдельно
        $categoryIds = $request->input('category_ids', []);
        $goodIds = $request->input('good_ids', []);
        $userIds = $request->input('user_ids', []);

        // Удаляем эти поля из validated, так как они не в fillable
        unset($validated['applicable_categories'], $validated['applicable_goods']);

        $promocode = Promocode::create($validated);

        // Синхронизируем связи many-to-many
        if (!empty($categoryIds)) {
            $promocode->categories()->attach($categoryIds);
        }
        if (!empty($goodIds)) {
            $promocode->goods()->attach($goodIds);
        }
        if (!empty($userIds)) {
            $promocode->users()->attach($userIds);
        }

        // Загружаем связи для ответа
        $promocode->load(['categories', 'goods', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно создан',
            'data' => $promocode
        ], 201);
    }

    /**
     * Обновить промокод
     */
    public function update(Request $request, $id): JsonResponse
    {
        $promocode = Promocode::find($id);
        
        if (!$promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден'
            ], 404);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('promocodes', 'code')->ignore($promocode->id, 'id')],
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
            'applicable_categories.*' => 'integer|exists:shop_categories,id',
            'applicable_goods' => 'nullable|array',
            'applicable_goods.*' => 'integer|exists:shop_goods,id',
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

        // Извлекаем связи для обработки отдельно
        $categoryIds = $request->input('category_ids', []);
        $goodIds = $request->input('good_ids', []);
        $userIds = $request->input('user_ids', []);

        // Удаляем эти поля из validated, так как они не в fillable
        unset($validated['applicable_categories'], $validated['applicable_goods']);

        $promocode->update($validated);

        // Синхронизируем связи many-to-many
        if ($request->has('category_ids')) {
            $promocode->categories()->sync($categoryIds);
        }
        if ($request->has('good_ids')) {
            $promocode->goods()->sync($goodIds);
        }
        if ($request->has('user_ids')) {
            $promocode->users()->sync($userIds);
        }

        // Загружаем связи для ответа
        $promocode->load(['categories', 'goods', 'users']);

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
            // Получаем статистику из таблицы promocode_usage
            $totalUsage = $promocode->usages()->count();
            $recentUsage = $promocode->usages()
                ->where('used_at', '>=', Carbon::now()->subDays(30))
                ->count();
            
            // Статистика по пользователям
            $uniqueUsers = $promocode->usages()
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');
            
            // Общая сумма скидок
            $totalDiscountAmount = $promocode->usages()
                ->sum('discount_amount');
            
            // Статистика по периодам (последние 7 дней, 30 дней, все время)
            $usageByPeriod = [
                'last_7_days' => $promocode->usages()
                    ->where('used_at', '>=', Carbon::now()->subDays(7))
                    ->count(),
                'last_30_days' => $recentUsage,
                'all_time' => $totalUsage
            ];

            $stats = [
                'total_usage' => $totalUsage,
                'recent_usage' => $recentUsage,
                'unique_users' => $uniqueUsers,
                'total_discount_amount' => round($totalDiscountAmount, 2),
                'usage_by_period' => $usageByPeriod,
                'usage_limit' => $promocode->usage_limit,
                'usage_limit_per_user' => $promocode->usage_limit_per_user,
            ];

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
     * Получить данные для селектов (категории, товары, пользователи)
     */
    public function getSelectData(): JsonResponse
    {
        $categories = ShopCategory::select('id', 'name')->orderBy('name')->get();
        $goods = ShopGood::select('id', 'name')->orderBy('name')->get();
        $users = \App\Models\User::select('id', 'name', 'email')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'goods' => $goods,
                'users' => $users
            ]
        ]);
    }

    /**
     * Поиск категорий, товаров или пользователей
     */
    public function searchItems(Request $request): JsonResponse
    {
        $type = $request->input('type'); // 'categories', 'goods', 'users'
        $search = $request->input('search', '');

        $results = [];

        switch ($type) {
            case 'categories':
                $query = ShopCategory::select('id', 'name');
                if ($search) {
                    $query->where('name', 'like', "%{$search}%");
                }
                $results = $query->orderBy('name')->limit(20)->get();
                break;

            case 'goods':
                $query = ShopGood::select('id', 'name');
                if ($search) {
                    $query->where('name', 'like', "%{$search}%");
                }
                $results = $query->orderBy('name')->limit(20)->get();
                break;

            case 'users':
                $query = \App\Models\User::select('id', 'name', 'email');
                if ($search) {
                    $query->where(function($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
                    });
                }
                $results = $query->orderBy('name')->limit(20)->get();
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный тип поиска'
                ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }
}
