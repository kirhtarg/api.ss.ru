<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promocode;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopPromocodePopupSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class PromocodeController extends Controller
{
    public function popupSettings(): JsonResponse {
        $s = ShopPromocodePopupSetting::first() ?: ShopPromocodePopupSetting::create(['delay_seconds'=>90]);
        return response()->json(['success'=>true,'data'=>$s]);
    }
    public function updatePopupSettings(Request $request): JsonResponse {
        $validator = Validator::make($request->all(), [
            'title'=>'nullable|string|max:255','text'=>'nullable|string|max:2000',
            'delay_seconds'=>'required|integer|min:60|max:120',
            'promocode_code'=>['required','string','max:32','regex:/^[A-Za-z0-9_-]+$/'],
            'discount_percent'=>'required|integer|min:1|max:90',
            'rotation_enabled'=>'required|boolean','rotation_minutes'=>'required|integer|min:1|max:1440','is_active'=>'required|boolean',
        ]);
        if ($validator->fails()) return response()->json(['success'=>false,'message'=>'Проверьте настройки всплывающего промокода','errors'=>$validator->errors()],422);
        $data = $validator->validated();
        $data['promocode_code'] = strtoupper($data['promocode_code']);
        $existingSettings = ShopPromocodePopupSetting::first();
        $codeIsOwnedElsewhere = Promocode::where('code', $data['promocode_code'])
            ->when($existingSettings?->promocode_id, fn ($query, $id) => $query->where('id', '!=', $id))
            ->exists();
        if ($codeIsOwnedElsewhere) {
            return response()->json(['success'=>false,'message'=>'Этот промокод уже используется. Укажите другой код.','errors'=>['promocode_code'=>['Промокод должен быть уникальным.']]],422);
        }
        $basePromo = Promocode::updateOrCreate(['code'=>$data['promocode_code']], [
            'name'=>'Всплывающий промокод','description'=>'Автоматически создан для всплывающего окна на странице товара',
            'type'=>'percentage','value'=>$data['discount_percent'],'is_active'=>$data['is_active'] && !$data['rotation_enabled'],
            'min_order_amount'=>null,'max_discount_amount'=>null,'usage_limit'=>null,'usage_limit_per_user'=>null,
            'starts_at'=>null,'expires_at'=>null,'applicable_categories'=>null,'applicable_goods'=>null,'applicable_variations'=>null,'user_id'=>null,
        ]);
        Promocode::where('name', 'Всплывающий промокод (ротация)')->where('is_active', true)->update(['is_active'=>false]);
        $data['promocode_id'] = $basePromo->id;
        $s = $existingSettings ?: new ShopPromocodePopupSetting(); $s->fill($data); $s->save();
        return response()->json(['success'=>true,'data'=>$s]);
    }

    public function publicPopup(): JsonResponse {
        $settings = ShopPromocodePopupSetting::where('is_active', true)->first();
        if (!$settings || !$settings->promocode_code) return response()->json(['success'=>true,'data'=>null]);
        $code = $settings->promocode_code;
        $validUntil = null;
        if ($settings->rotation_enabled) {
            $seconds = max(60, (int)$settings->rotation_minutes * 60);
            $now = Carbon::now();
            $slot = (int) floor($now->timestamp / $seconds);
            $slotStart = Carbon::createFromTimestamp($slot * $seconds);
            $validUntil = Carbon::createFromTimestamp(($slot + 1) * $seconds);
            $suffix = strtoupper(substr(hash_hmac('sha256', $settings->id.':'.$slot, (string) config('app.key')), 0, 6));
            $code = $settings->promocode_code.'-'.$suffix;
            Promocode::updateOrCreate(['code'=>$code], [
                'name'=>'Всплывающий промокод (ротация)','description'=>'Промокод из всплывающего окна, действует до '.$validUntil->toDateTimeString(),
                'type'=>'percentage','value'=>$settings->discount_percent,'is_active'=>true,'starts_at'=>$slotStart,'expires_at'=>$validUntil,
                'min_order_amount'=>null,'max_discount_amount'=>null,'usage_limit'=>null,'usage_limit_per_user'=>null,
                'applicable_categories'=>null,'applicable_goods'=>null,'applicable_variations'=>null,'user_id'=>null,
            ]);
        }
        $promocode = Promocode::where('code',$code)->first();
        return response()->json(['success'=>true,'data'=>[
            'title'=>$settings->title,'text'=>$settings->text,'delay_seconds'=>$settings->delay_seconds,
            'rotation_enabled'=>(bool)$settings->rotation_enabled,'rotation_minutes'=>(int)$settings->rotation_minutes,
            'promocode'=>$promocode ? ['code'=>$promocode->code,'discount_percent'=>$settings->discount_percent] : null,
            'valid_until'=>$validUntil?->toIso8601String(),
        ]]);
    }
    /**
     * Получить список промокодов
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('PromocodeController@index called');
        \Log::info('Request data: '.json_encode($request->all()));

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

            // Фильтр по пользователю (user_id) - персональные промокоды
            if ($request->has('user_id') && $request->user_id !== '' && $request->user_id !== null) {
                $userId = (int) $request->user_id;
                $query->where('user_id', $userId);
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('per_page', 15);

            // Отладка: проверим, сколько промокодов в базе
            $totalCount = Promocode::count();
            \Log::info('Total promocodes in database: '.$totalCount);

            $promocodes = $query->with(['categories', 'goods', 'user'])->paginate($perPage);

            \Log::info('Query result count: '.$promocodes->count());
            \Log::info('Query SQL: '.$query->toSql());
            \Log::info('Query bindings: '.json_encode($query->getBindings()));

            return response()->json([
                'success' => true,
                'data' => $promocodes,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in PromocodeController@index: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки промокодов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить промокод по ID
     */
    public function show($id): JsonResponse
    {
        $promocode = Promocode::find($id);

        if (! $promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден',
            ], 404);
        }

        $promocode->load(['categories', 'goods', 'user']);

        return response()->json([
            'success' => true,
            'data' => $promocode,
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
            'user_id' => 'nullable|integer|exists:users,id', // ID пользователя для персонального промокода
        ]);

        // Валидация value для определенных типов
        if (in_array($validated['type'], ['percentage', 'fixed_amount']) && ! $validated['value']) {
            return response()->json([
                'success' => false,
                'message' => 'Поле "Значение" обязательно для выбранного типа промокода',
            ], 422);
        }

        // Извлекаем связи для обработки отдельно
        $categoryIds = $request->input('category_ids', []);
        $goodIds = $request->input('good_ids', []);
        $userId = $request->input('user_id', null); // Для персональных промокодов

        // Удаляем эти поля из validated, так как они не в fillable
        unset($validated['applicable_categories'], $validated['applicable_goods']);

        // Если передан user_id, устанавливаем его для персонального промокода
        if ($userId) {
            $validated['user_id'] = (int) $userId;
        }

        $promocode = Promocode::create($validated);

        // Синхронизируем связи many-to-many
        if (! empty($categoryIds)) {
            $promocode->categories()->attach($categoryIds);
        }
        if (! empty($goodIds)) {
            $promocode->goods()->attach($goodIds);
        }

        // Загружаем связи для ответа
        $promocode->load(['categories', 'goods', 'user']);

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно создан',
            'data' => $promocode,
        ], 201);
    }

    /**
     * Обновить промокод
     */
    public function update(Request $request, $id): JsonResponse
    {
        $promocode = Promocode::find($id);

        if (! $promocode) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден',
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
            'user_id' => 'nullable|integer|exists:users,id', // ID пользователя для персонального промокода
        ]);

        // Валидация value для определенных типов
        if (in_array($validated['type'], ['percentage', 'fixed_amount']) && ! $validated['value']) {
            return response()->json([
                'success' => false,
                'message' => 'Поле "Значение" обязательно для выбранного типа промокода',
            ], 422);
        }

        // Извлекаем связи для обработки отдельно
        $categoryIds = $request->input('category_ids', []);
        $goodIds = $request->input('good_ids', []);
        $userId = $request->input('user_id', null); // Для персональных промокодов

        // Удаляем эти поля из validated, так как они не в fillable
        unset($validated['applicable_categories'], $validated['applicable_goods']);

        // Если передан user_id, устанавливаем его для персонального промокода
        if ($request->has('user_id')) {
            $validated['user_id'] = $userId ? (int) $userId : null;
        }

        $promocode->update($validated);

        // Синхронизируем связи many-to-many
        if ($request->has('category_ids')) {
            $promocode->categories()->sync($categoryIds);
        }
        if ($request->has('good_ids')) {
            $promocode->goods()->sync($goodIds);
        }

        // Загружаем связи для ответа
        $promocode->load(['categories', 'goods', 'user']);

        return response()->json([
            'success' => true,
            'message' => 'Промокод успешно обновлен',
            'data' => $promocode,
        ]);
    }

    /**
     * Удалить промокод
     */
    public function destroy($id): JsonResponse
    {
        try {
            $promocode = Promocode::find($id);

            if (! $promocode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Промокод не найден',
                ], 404);
            }

            // Удаляем промокод (даже если он использовался)
            // Связи many-to-many будут удалены автоматически благодаря onDelete('cascade')
            $promocode->delete();

            return response()->json([
                'success' => true,
                'message' => 'Промокод успешно удален',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении промокода: '.$e->getMessage(),
            ], 500);
        }
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
                'all_time' => $totalUsage,
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
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in PromocodeController@stats: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки статистики: '.$e->getMessage(),
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
                'users' => $users,
            ],
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
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                }
                $results = $query->orderBy('name')->limit(20)->get();
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный тип поиска',
                ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
}
