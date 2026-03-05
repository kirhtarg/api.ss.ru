<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopDeliveryMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShopDeliveryController extends Controller
{
    /**
     * Получить список способов доставки
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopDeliveryMethod::query();

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
                $query->where(function ($q) use ($search) {
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
            $deliveryMethods = $query->paginate($perPage);

            // Безопасная сериализация данных
            $items = [];
            foreach ($deliveryMethods->items() as $item) {
                try {
                    $items[] = [
                        'id' => $item->id,
                        'name' => $item->name,
                        'type' => $item->type,
                        'is_active' => $item->is_active,
                        'cost' => $item->cost,
                        'free_from' => $item->free_from,
                        'description' => $item->description,
                        'settings' => $item->settings,
                        'sort_order' => $item->sort_order,
                        'is_default' => $item->is_default,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ];
                } catch (\Exception $e) {
                    \Log::error('Error serializing delivery method item: '.$e->getMessage());

                    continue;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $deliveryMethods->currentPage(),
                    'last_page' => $deliveryMethods->lastPage(),
                    'per_page' => $deliveryMethods->perPage(),
                    'total' => $deliveryMethods->total(),
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('ShopDeliveryController::index error: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов доставки',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Получить способ доставки по ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $deliveryMethod = ShopDeliveryMethod::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $deliveryMethod,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Способ доставки не найден',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Создать новый способ доставки
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:50',
                'is_active' => 'boolean',
                'cost' => 'required|numeric|min:0',
                'free_from' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default')) {
                ShopDeliveryMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $deliveryMethod = ShopDeliveryMethod::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ доставки создан успешно',
                'data' => $deliveryMethod,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания способа доставки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить способ доставки
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $deliveryMethod = ShopDeliveryMethod::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'type' => 'sometimes|required|string|max:50',
                'is_active' => 'boolean',
                'cost' => 'sometimes|required|numeric|min:0',
                'free_from' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default') && ! $deliveryMethod->is_default) {
                ShopDeliveryMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $deliveryMethod->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ доставки обновлен успешно',
                'data' => $deliveryMethod,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления способа доставки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить способ доставки
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $deliveryMethod = ShopDeliveryMethod::findOrFail($id);

            // Нельзя удалить способ по умолчанию, если есть другие активные
            if ($deliveryMethod->is_default) {
                $hasOtherActive = ShopDeliveryMethod::where('id', '!=', $id)
                    ->where('is_active', true)
                    ->exists();

                if ($hasOtherActive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Нельзя удалить способ доставки по умолчанию, если есть другие активные способы',
                    ], 400);
                }
            }

            $deliveryMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Способ доставки удален успешно',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления способа доставки',
                'error' => $e->getMessage(),
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
                'items.*.id' => 'required|integer|exists:shop_delivery_methods,id',
                'items.*.sort_order' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            foreach ($request->get('items') as $item) {
                ShopDeliveryMethod::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Порядок сортировки обновлен успешно',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка сортировки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
