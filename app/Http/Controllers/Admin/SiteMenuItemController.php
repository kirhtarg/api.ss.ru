<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteMenuItemController extends Controller
{
    /**
     * Получить все пункты меню для конкретного меню
     */
    public function index($menuId): JsonResponse
    {
        try {
            $menuItems = SiteMenuItem::where('site_menu_id', $menuId)
                ->with(['children' => function ($query) {
                    $query->orderBy('sort_order', 'asc');
                }])
                ->whereNull('parent_id')
                ->orderBy('sort_order', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $menuItems,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения пунктов меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый пункт меню
     */
    public function store(Request $request, $menuId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'url' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:site_menu_items,id',
                'sort_order' => 'integer|min:0',
                'is_active' => 'boolean',
                'target' => 'string|in:_self,_blank,_parent,_top',
                'icon' => 'nullable|string|max:255',
                'attributes' => 'nullable|array',
            ]);

            // Добавляем site_menu_id из URL
            $validated['site_menu_id'] = $menuId;

            // Если sort_order не указан, устанавливаем максимальный + 1
            if (! isset($validated['sort_order'])) {
                $maxOrder = SiteMenuItem::where('site_menu_id', $menuId)->max('sort_order') ?? 0;
                $validated['sort_order'] = $maxOrder + 1;
            }

            $menuItem = SiteMenuItem::create($validated);

            return response()->json([
                'success' => true,
                'data' => $menuItem,
                'message' => 'Пункт меню успешно создан',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания пункта меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить пункт меню по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $menuItem = SiteMenuItem::with('children')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $menuItem,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения пункта меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить пункт меню
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $menuItem = SiteMenuItem::findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'url' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:site_menu_items,id',
                'sort_order' => 'integer|min:0',
                'is_active' => 'boolean',
                'target' => 'string|in:_self,_blank,_parent,_top',
                'icon' => 'nullable|string|max:255',
                'attributes' => 'nullable|array',
            ]);

            // Проверяем, что пункт не является родителем самому себе
            if ($validated['parent_id'] == $menuItem->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пункт меню не может быть родителем самому себе',
                ], 400);
            }

            $menuItem->update($validated);

            return response()->json([
                'success' => true,
                'data' => $menuItem->load('children'),
                'message' => 'Пункт меню успешно обновлен',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления пункта меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить пункт меню
     */
    public function destroy($id): JsonResponse
    {
        try {
            $menuItem = SiteMenuItem::findOrFail($id);
            $menuItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Пункт меню успешно удален',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления пункта меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить порядок пунктов меню
     */
    public function updateOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'items' => 'required|array',
                'items.*.id' => 'required|exists:site_menu_items,id',
                'items.*.sort_order' => 'required|integer|min:0',
                'items.*.parent_id' => 'nullable|exists:site_menu_items,id',
            ]);

            foreach ($validated['items'] as $item) {
                SiteMenuItem::where('id', $item['id'])->update([
                    'sort_order' => $item['sort_order'],
                    'parent_id' => $item['parent_id'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Порядок пунктов меню успешно обновлен',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка пунктов меню: '.$e->getMessage(),
            ], 500);
        }
    }
}
