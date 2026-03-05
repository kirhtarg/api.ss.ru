<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use App\Models\SiteTemplate;
use Illuminate\Http\JsonResponse;

class SiteTemplateController extends Controller
{
    /**
     * Получить активный шаблон сайта
     */
    public function getActive(): JsonResponse
    {
        try {
            $template = SiteTemplate::getActive();

            if (! $template) {
                // Если нет активного шаблона, возвращаем дефолтный
                $template = SiteTemplate::getDefault();
            }

            if (! $template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Шаблон не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $template->getTemplateData(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить активный шаблон главной страницы
     */
    public function getActiveMain(): JsonResponse
    {
        try {
            $template = SiteTemplate::getActiveMain();

            if (! $template) {
                // Если нет активного шаблона главной страницы, возвращаем дефолтный
                $template = SiteTemplate::getDefault();
            }

            if (! $template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Шаблон главной страницы не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $template->getTemplateData(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона главной страницы: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить данные меню
     */
    public function getMenu(): JsonResponse
    {
        try {
            $menuItems = SiteMenuItem::getMenuTree();

            return response()->json([
                'success' => true,
                'data' => $menuItems->map(function ($item) {
                    return $item->getMenuData();
                }),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить все доступные шаблоны (для админки)
     */
    public function getAll(): JsonResponse
    {
        try {
            $templates = SiteTemplate::with(['menu'])
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $templates->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'description' => $template->description,
                        'folder_name' => $template->folder_name,
                        'menu_name' => $template->menu?->name,
                        'is_active' => $template->is_active,
                        'sort_order' => $template->sort_order,
                        'created_at' => $template->created_at,
                        'updated_at' => $template->updated_at,
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов: '.$e->getMessage(),
            ], 500);
        }
    }
}
