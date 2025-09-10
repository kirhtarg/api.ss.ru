<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopProperty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopPropertiesController extends Controller
{
    /**
     * Получить список всех свойств для импорта
     */
    public function list()
    {
        try {
            $properties = ShopProperty::ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $properties->map(function($property) {
                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                        'slug' => $property->slug,
                        'sort_order' => $property->sort_order
                    ];
                })
            ]);
        } catch (\Exception $e) {
            \Log::error('Ошибка получения списка свойств: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка свойств',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}