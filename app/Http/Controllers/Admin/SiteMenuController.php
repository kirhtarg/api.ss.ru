<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteMenuController extends Controller
{
    /**
     * Получить все меню
     */
    public function index(): JsonResponse
    {
        try {
            $menus = SiteMenu::orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $menus,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить меню по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $menu = SiteMenu::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $menu,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новое меню
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:site_menus',
                'description' => 'nullable|string',
                'template_name' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $menu = SiteMenu::create($validated);

            return response()->json([
                'success' => true,
                'data' => $menu,
                'message' => 'Меню успешно создано',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить меню
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $menu = SiteMenu::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:site_menus,name,'.$id,
                'description' => 'nullable|string',
                'template_name' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $menu->update($validated);

            return response()->json([
                'success' => true,
                'data' => $menu,
                'message' => 'Меню успешно обновлено',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить меню
     */
    public function destroy($id): JsonResponse
    {
        try {
            $menu = SiteMenu::findOrFail($id);

            // Проверяем, используется ли меню в активных шаблонах сайта
            if ($menu->siteTemplates()->where('is_active', true)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить меню, которое используется в активном шаблоне сайта',
                ], 400);
            }

            $menu->delete();

            return response()->json([
                'success' => true,
                'message' => 'Меню успешно удалено',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить статус меню
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $menu = SiteMenu::findOrFail($id);

            $validated = $request->validate([
                'is_active' => 'required|boolean',
            ]);

            $menu->update($validated);

            return response()->json([
                'success' => true,
                'data' => $menu,
                'message' => 'Статус меню обновлен',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить статистику меню
     */
    public function statistics(): JsonResponse
    {
        try {
            $totalMenus = SiteMenu::count();
            $activeMenus = SiteMenu::where('is_active', true)->count();
            $systemMenus = SiteMenu::whereIn('name', ['main', 'footer', 'header'])->count();
            $menusWithTemplates = SiteMenu::whereHas('siteTemplates')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_menus' => $totalMenus,
                    'active_menus' => $activeMenus,
                    'system_menus' => $systemMenus,
                    'menus_with_templates' => $menusWithTemplates,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики: '.$e->getMessage(),
            ], 500);
        }
    }
}
