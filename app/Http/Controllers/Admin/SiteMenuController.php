<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteMenuController extends Controller
{
    /**
     * Получить все шаблоны меню
     */
    public function index(): JsonResponse
    {
        try {
            $menus = SiteMenu::ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $menus
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов меню: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый шаблон меню
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'template_name' => 'required|string|max:255|unique:site_menus',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $menu = SiteMenu::create($validated);
            
            return response()->json([
                'success' => true,
                'data' => $menu,
                'message' => 'Шаблон меню успешно создан'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона меню: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить шаблон меню по ID
     */
    public function show(SiteMenu $siteMenu): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $siteMenu
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона меню: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить шаблон меню
     */
    public function update(Request $request, SiteMenu $siteMenu): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'template_name' => 'required|string|max:255|unique:site_menus,template_name,' . $siteMenu->id,
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $siteMenu->update($validated);
            
            return response()->json([
                'success' => true,
                'data' => $siteMenu,
                'message' => 'Шаблон меню успешно обновлен'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления шаблона меню: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить шаблон меню
     */
    public function destroy(SiteMenu $siteMenu): JsonResponse
    {
        try {
            // Проверяем, используется ли шаблон в активных шаблонах сайта
            if ($siteMenu->siteTemplates()->where('is_active', true)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить шаблон меню, который используется в активном шаблоне сайта'
                ], 400);
            }
            
            $siteMenu->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Шаблон меню успешно удален'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления шаблона меню: ' . $e->getMessage()
            ], 500);
        }
    }
}
