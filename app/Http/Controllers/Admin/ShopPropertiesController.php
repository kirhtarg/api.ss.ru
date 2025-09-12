<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
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
            $properties = Property::with('values')
                ->orderBy('name')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $properties->map(function($property) {
                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                        'property_type' => $property->property_type,
                        'description' => $property->description,
                        'values' => $property->values->map(function($value) {
                            return [
                                'id' => $value->id,
                                'value' => $value->value,
                                'color' => $value->color
                            ];
                        })
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