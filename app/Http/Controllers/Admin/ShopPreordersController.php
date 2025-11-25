<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopPreorder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopPreordersController extends Controller
{
    /**
     * Получить список предзаказов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopPreorder::with(['good', 'variation', 'user']);

        // Фильтр по статусу
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Сортировка
        $query->orderBy('created_at', 'desc');

        $preorders = $query->paginate(20);

        // Добавляем информацию об остатках для каждого предзаказа
        $preorders->getCollection()->transform(function ($preorder) {
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
    public function destroy(int $id): JsonResponse
    {
        $preorder = ShopPreorder::find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

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
        $preorder = ShopPreorder::find($id);

        if (!$preorder) {
            return response()->json([
                'success' => false,
                'message' => 'Предзаказ не найден'
            ], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,cancelled,fulfilled'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $preorder->update([
            'status' => $request->status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Статус предзаказа обновлен',
            'data' => $preorder
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

