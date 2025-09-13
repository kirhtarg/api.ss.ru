<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopTemplate;
use Illuminate\Http\JsonResponse;

class ShopTemplateController extends Controller
{
    /**
     * Получить активный шаблон магазина
     */
    public function getActive(): JsonResponse
    {
        try {
            $template = ShopTemplate::getActive();
            
            if (!$template) {
                // Если нет активного шаблона, возвращаем дефолтный
                $template = ShopTemplate::getDefault();
            }
            
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Шаблон магазина не найден'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $template->getTemplateData()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона магазина: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить все доступные шаблоны магазина (для админки)
     */
    public function getAll(): JsonResponse
    {
        try {
            $templates = ShopTemplate::ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $templates->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'description' => $template->description,
                        'folder_name' => $template->folder_name,
                        'is_active' => $template->is_active,
                        'sort_order' => $template->sort_order,
                        'created_at' => $template->created_at,
                        'updated_at' => $template->updated_at,
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов магазина: ' . $e->getMessage()
            ], 500);
        }
    }
}
