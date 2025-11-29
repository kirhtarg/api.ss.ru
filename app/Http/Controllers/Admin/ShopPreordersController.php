<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopPreorder;
use App\Models\ShopOrderLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopPreordersController extends Controller
{
    /**
     * Получить список предзаказов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopPreorder::with(['good.categories', 'good.brands', 'good.tags', 'variation', 'user']);

        // Фильтр по статусу
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Фильтр по категориям
        if ($request->has('categories') && is_array($request->categories) && count($request->categories) > 0) {
            $categoryIds = $request->categories;
            $query->whereHas('good.categories', function ($q) use ($categoryIds) {
                $q->whereIn('shop_categories.id', $categoryIds);
            });
        }

        // Фильтр по брендам
        if ($request->has('brands') && is_array($request->brands) && count($request->brands) > 0) {
            $brandIds = $request->brands;
            $query->whereHas('good.brands', function ($q) use ($brandIds) {
                $q->whereIn('shop_brands.id', $brandIds);
            });
        }

        // Фильтр по тегам
        if ($request->has('tags') && is_array($request->tags) && count($request->tags) > 0) {
            $tagIds = $request->tags;
            $query->whereHas('good.tags', function ($q) use ($tagIds) {
                $q->whereIn('shop_tags.id', $tagIds);
            });
        }

        // Сортировка
        $query->orderBy('created_at', 'desc');

        $preorders = $query->paginate(20);

        // Получаем ID всех предзаказов для подсчета логов
        $preorderIds = $preorders->pluck('id')->toArray();
        
        // Подсчитываем количество логов для каждого предзаказа
        $logsCount = ShopOrderLog::where('section', ShopOrderLog::SECTION_PREORDERS)
            ->whereIn('entity_id', $preorderIds)
            ->selectRaw('entity_id, count(*) as count')
            ->groupBy('entity_id')
            ->pluck('count', 'entity_id')
            ->toArray();

        // Добавляем информацию об остатках и дополнительных данных для каждого предзаказа
        $preorders->getCollection()->transform(function ($preorder) use ($logsCount) {
            $stockQuantity = 0;
            $remoteStockQuantity = null;

            // Если есть вариация, берем остатки из вариации
            if ($preorder->variation_id && $preorder->variation) {
                $stockQuantity = $preorder->variation->stock_quantity ?? 0;
                $remoteStockQuantity = $preorder->variation->remote_stock_quantity ?? null;
            } 
            // Иначе берем остатки из основного товара
            elseif ($preorder->good) {
                $stockQuantity = $preorder->good->stock_quantity ?? 0;
                $remoteStockQuantity = $preorder->good->remote_stock_quantity ?? null;
            }

            $preorder->stock_quantity = $stockQuantity;
            $preorder->remote_stock_quantity = $remoteStockQuantity;
            
            // Добавляем количество логов
            $preorder->logs_count = $logsCount[$preorder->id] ?? 0;

            // Добавляем информацию о категориях, брендах и тегах
            if ($preorder->good) {
                $preorder->categories = $preorder->good->categories->map(function ($cat) {
                    return ['id' => $cat->id, 'name' => $cat->name];
                });
                $preorder->brands = $preorder->good->brands->map(function ($brand) {
                    return ['id' => $brand->id, 'name' => $brand->name];
                });
                $preorder->tags = $preorder->good->tags->map(function ($tag) {
                    return ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color ?? '#3B82F6'];
                });
            } else {
                $preorder->categories = [];
                $preorder->brands = [];
                $preorder->tags = [];
            }

            return $preorder;
        });

        return response()->json([
            'success' => true,
            'data' => $preorders
        ]);
    }

    /**
     * Получить конкретный предзаказ
     */
    public function show(int $id): JsonResponse
    {
        $preorder = ShopPreorder::with(['good', 'variation', 'user'])->find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $preorder
        ]);
    }

    /**
     * Обновить предзаказ
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $preorder = ShopPreorder::find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'status' => 'sometimes|in:pending,confirmed,cancelled,fulfilled',
            'notes' => 'nullable|string|max:1000',
            'customer_name' => 'sometimes|string|max:255',
            'customer_email' => 'sometimes|email|max:255',
            'customer_phone' => 'sometimes|string|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $preorder->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Предзаказ обновлен',
            'data' => $preorder
        ]);
    }

    /**
     * Удалить предзаказ
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $preorder = ShopPreorder::with('good')->find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        // Логгируем удаление перед удалением записи
        $user = $request->user();
        $userName = $user ? $user->name : 'Администратор';
        $goodName = $preorder->good ? $preorder->good->name : $preorder->good_name;
        
        ShopOrderLog::logPreorderDeleted(
            $preorder->id,
            $user ? $user->id : null,
            $userName,
            $goodName
        );

        $preorder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Предзаказ удален'
        ]);
    }

    /**
     * Изменить статус предзаказа
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $preorder = ShopPreorder::with('good')->find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,cancelled,fulfilled',
            'comment' => 'nullable|string|max:1000',
            'action_icon_id' => 'nullable|integer|exists:shop_order_log_icons,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $oldStatus = $preorder->status;
        $newStatus = $request->status;

        $preorder->update([
            'status' => $newStatus
        ]);

        // Логируем изменение статуса
        $user = $request->user();
        $userName = $user ? $user->name : 'Администратор';
        $goodName = $preorder->good ? $preorder->good->name : $preorder->good_name;
        
        ShopOrderLog::logPreorderStatusChange(
            $preorder->id,
            $oldStatus,
            $newStatus,
            $user ? $user->id : null,
            $userName,
            $request->get('comment'),
            $request->get('action_icon_id'),
            $goodName
        );

        return response()->json([
            'success' => true,
            'message' => 'Статус предзаказа обновлен',
            'data' => $preorder
        ]);
    }

    /**
     * Получить журнал действий предзаказа
     */
    public function logs(int $id): JsonResponse
    {
        $preorder = ShopPreorder::find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $logs = ShopOrderLog::with('actionIcon')
            ->where('entity_id', $id)
            ->where('section', ShopOrderLog::SECTION_PREORDERS)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'action_color' => $log->action_color,
                    'action_bg_color' => $log->action_bg_color,
                    'action_icon' => $log->actionIcon ? [
                        'icon' => $log->actionIcon->icon,
                        'color' => $log->actionIcon->color
                    ] : null,
                    'comment' => $log->comment,
                    'user_name' => $log->user_name,
                    'created_at' => $log->created_at
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs
            ]
        ]);
    }

    /**
     * Добавить комментарий в журнал
     */
    public function addLog(Request $request, int $id): JsonResponse
    {
        $preorder = ShopPreorder::with('good')->find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'comment' => 'required|string|max:1000',
            'action_icon_id' => 'nullable|integer|exists:shop_order_log_icons,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $userName = $user ? $user->name : 'Администратор';
        $goodName = $preorder->good ? $preorder->good->name : $preorder->good_name;

        $log = ShopOrderLog::logPreorderAction($preorder->id, 'Комментарий', [
            'comment' => $request->get('comment'),
            'action_icon_id' => $request->get('action_icon_id'),
            'user_id' => $user ? $user->id : null,
            'user_name' => $userName,
            'info' => "Предзаказ: {$goodName}"
        ]);

        // Загружаем иконку действия
        $log->load('actionIcon');

        return response()->json([
            'success' => true,
            'message' => 'Комментарий добавлен',
            'data' => [
                'id' => $log->id,
                'action' => $log->action,
                'action_color' => $log->action_color,
                'action_bg_color' => $log->action_bg_color,
                'action_icon' => $log->actionIcon ? [
                    'icon' => $log->actionIcon->icon,
                    'color' => $log->actionIcon->color
                ] : null,
                'comment' => $log->comment,
                'user_name' => $log->user_name,
                'created_at' => $log->created_at
            ]
        ]);
    }

    /**
     * Логгировать добавление в корзину
     */
    public function logAddToCart(Request $request, int $id): JsonResponse
    {
        $preorder = ShopPreorder::with('good')->find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $userName = $user ? $user->name : 'Администратор';
        $goodName = $preorder->good ? $preorder->good->name : $preorder->good_name;

        $log = ShopOrderLog::logPreorderAddedToCart(
            $preorder->id,
            $request->get('quantity'),
            $user ? $user->id : null,
            $userName,
            $goodName
        );

        return response()->json([
            'success' => true,
            'message' => 'Добавление в корзину залоггировано',
            'data' => [
                'id' => $log->id,
                'action' => $log->action,
                'action_color' => $log->action_color,
                'user_name' => $log->user_name,
                'created_at' => $log->created_at
            ]
        ]);
    }

    /**
     * Получить статистику
     */
    public function stats(): JsonResponse
    {
        // Получаем все предзаказы со статусом pending с загруженными товарами и вариациями
        $pendingPreorders = ShopPreorder::with(['good', 'variation'])
            ->where('status', 'pending')
            ->get();

        // Подсчитываем предзаказы, где товар есть в наличии
        $pendingWithStock = 0;
        foreach ($pendingPreorders as $preorder) {
            $stockQuantity = 0;
            $remoteStockQuantity = null;

            // Если есть вариация, берем остатки из вариации
            if ($preorder->variation_id && $preorder->variation) {
                $stockQuantity = $preorder->variation->stock_quantity ?? 0;
                $remoteStockQuantity = $preorder->variation->remote_stock_quantity ?? null;
            } 
            // Иначе берем остатки из основного товара
            elseif ($preorder->good) {
                $stockQuantity = $preorder->good->stock_quantity ?? 0;
                $remoteStockQuantity = $preorder->good->remote_stock_quantity ?? null;
            }

            // Проверяем, есть ли товар в наличии
            $hasStock = $stockQuantity > 0;
            $hasRemoteStock = $remoteStockQuantity !== null && 
                             $remoteStockQuantity !== '' && 
                             $remoteStockQuantity !== '0';

            if ($hasStock || $hasRemoteStock) {
                $pendingWithStock++;
            }
        }

        $stats = [
            'total' => ShopPreorder::count(),
            'pending' => ShopPreorder::where('status', 'pending')->count(),
            'pending_with_stock' => $pendingWithStock,
            'confirmed' => ShopPreorder::where('status', 'confirmed')->count(),
            'cancelled' => ShopPreorder::where('status', 'cancelled')->count(),
            'fulfilled' => ShopPreorder::where('status', 'fulfilled')->count(),
            'today' => ShopPreorder::whereDate('created_at', today())->count(),
            'this_week' => ShopPreorder::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => ShopPreorder::whereMonth('created_at', now()->month)->count()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}

