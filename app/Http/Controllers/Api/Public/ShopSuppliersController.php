<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSuppliersController extends Controller
{
    /**
     * Получить список активных поставщиков
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopSupplier::query();

        // Только активные поставщики
        $query->where('is_active', true);

        // Если нужны только активные для выпадающего списка
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $suppliers = $query->select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers,
        ]);
    }
}
