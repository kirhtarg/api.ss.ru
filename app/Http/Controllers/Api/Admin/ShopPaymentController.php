<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ShopPaymentController extends Controller
{
    /**
     * Получить список способов оплаты
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopPaymentMethod::query();

            // Фильтрация по активности
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Фильтрация по типу
            if ($request->has('type')) {
                $query->where('type', $request->get('type'));
            }

            // Поиск
            if ($request->has('search') && $request->get('search')) {
                $search = $request->get('search');
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'sort_order');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $paymentMethods = $query->paginate($perPage);

            // Безопасная сериализация данных с image_url
            $items = [];
            foreach ($paymentMethods->items() as $method) {
                try {
                    $item = [
                        'id' => $method->id,
                        'name' => $method->name,
                        'type' => $method->type,
                        'is_active' => $method->is_active,
                        'description' => $method->description,
                        'settings' => $method->settings,
                        'sort_order' => $method->sort_order,
                        'is_default' => $method->is_default,
                        'can_disable_default' => $method->can_disable_default,
                        'created_at' => $method->created_at,
                        'updated_at' => $method->updated_at,
                    ];
                    
                    // Безопасно получаем image_url
                    try {
                        $item['image_url'] = $method->image_url;
                    } catch (\Exception $e) {
                        \Log::warning('Error getting image_url for payment method ' . $method->id . ': ' . $e->getMessage());
                        $item['image_url'] = null;
                    }
                    
                    $items[] = $item;
                } catch (\Exception $e) {
                    \Log::error('Error serializing payment method item: ' . $e->getMessage());
                    continue;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $paymentMethods->currentPage(),
                    'last_page' => $paymentMethods->lastPage(),
                    'per_page' => $paymentMethods->perPage(),
                    'total' => $paymentMethods->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить способ оплаты по ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            $data = [
                'id' => $paymentMethod->id,
                'name' => $paymentMethod->name,
                'type' => $paymentMethod->type,
                'is_active' => $paymentMethod->is_active,
                'description' => $paymentMethod->description,
                'settings' => $paymentMethod->settings,
                'sort_order' => $paymentMethod->sort_order,
                'is_default' => $paymentMethod->is_default,
                'can_disable_default' => $paymentMethod->can_disable_default,
                'created_at' => $paymentMethod->created_at,
                'updated_at' => $paymentMethod->updated_at,
            ];
            
            // Безопасно получаем image_url
            try {
                $data['image_url'] = $paymentMethod->image_url;
            } catch (\Exception $e) {
                \Log::warning('Error getting image_url for payment method ' . $id . ': ' . $e->getMessage());
                $data['image_url'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Способ оплаты не найден',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Создать новый способ оплаты
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:50',
                'is_active' => 'boolean',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
                'can_disable_default' => 'boolean'
            ]);

            // Дополнительная валидация для Яндекс Пэй
            if ($request->get('type') === 'yandex_pay' || $request->get('type') === 'yandex_split') {
                $yandexValidator = Validator::make($request->get('settings', []), [
                    'merchant_id' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'mode' => 'required|string|in:test,live',
                    'currency' => 'required|string|in:RUB,USD,EUR',
                    'return_url' => 'nullable|url',
                    'webhook_url' => 'nullable|url',
                    'additional_settings' => 'nullable|string',
                    'split_min_amount' => 'nullable|numeric|min:0',
                    'split_max_amount' => 'nullable|numeric|min:0|gte:split_min_amount',
                    'split_settings' => 'nullable|string'
                ]);

                if ($yandexValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации настроек Яндекс Пэй',
                        'errors' => $yandexValidator->errors()
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default')) {
                ShopPaymentMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $paymentMethod = ShopPaymentMethod::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты создан успешно',
                'data' => $paymentMethod
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания способа оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить способ оплаты
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'type' => 'sometimes|required|string|max:50',
                'is_active' => 'boolean',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
                'can_disable_default' => 'boolean'
            ]);

            // Дополнительная валидация для Яндекс Пэй
            if ($request->get('type') === 'yandex_pay' || $request->get('type') === 'yandex_split') {
                $yandexValidator = Validator::make($request->get('settings', []), [
                    'merchant_id' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'mode' => 'required|string|in:test,live',
                    'currency' => 'required|string|in:RUB,USD,EUR',
                    'return_url' => 'nullable|url',
                    'webhook_url' => 'nullable|url',
                    'additional_settings' => 'nullable|string',
                    'split_min_amount' => 'nullable|numeric|min:0',
                    'split_max_amount' => 'nullable|numeric|min:0|gte:split_min_amount',
                    'split_settings' => 'nullable|string'
                ]);

                if ($yandexValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации настроек Яндекс Пэй',
                        'errors' => $yandexValidator->errors()
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default') && !$paymentMethod->is_default) {
                ShopPaymentMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $paymentMethod->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты обновлен успешно',
                'data' => $paymentMethod
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления способа оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить способ оплаты
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            // Нельзя удалить способ по умолчанию, если он не может быть отключен
            if ($paymentMethod->is_default && !$paymentMethod->can_disable_default) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить этот способ оплаты по умолчанию'
                ], 400);
            }

            // Нельзя удалить способ по умолчанию, если нет других активных
            if ($paymentMethod->is_default) {
                $hasOtherActive = ShopPaymentMethod::where('id', '!=', $id)
                    ->where('is_active', true)
                    ->exists();

                if (!$hasOtherActive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Нельзя удалить способ оплаты по умолчанию, если нет других активных способов'
                    ], 400);
                }
            }

            $paymentMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты удален успешно'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления способа оплаты',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить порядок сортировки
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'items' => 'required|array',
                'items.*.id' => 'required|integer|exists:shop_payment_methods,id',
                'items.*.sort_order' => 'required|integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            foreach ($request->get('items') as $item) {
                ShopPaymentMethod::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Порядок сортировки обновлен успешно'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка сортировки',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
