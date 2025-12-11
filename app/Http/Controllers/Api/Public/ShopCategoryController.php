<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use App\Models\ShopCategoryExtraMenu;
use Illuminate\Http\Request;

class ShopCategoryController extends Controller
{
    /**
     * Получить главные категории с подкатегориями и экстра-меню
     */
    public function getMainCategories()
    {
        try {
            $mainCategories = ShopCategory::active()
                ->main()
                ->ordered()
                ->get();

            // Получаем ID всех главных категорий для загрузки экстра-меню одним запросом
            $categoryIds = $mainCategories->pluck('id')->toArray();
            
            // Загружаем все экстра-меню одним запросом
            $extraMenus = [];
            if (!empty($categoryIds) && \Schema::hasTable('shop_category_extra_menus')) {
                $extraMenusData = ShopCategoryExtraMenu::with([
                    'filters' => function($query) {
                        $query->where('is_active', true)->orderBy('sort_order');
                    },
                    'sections' => function($query) {
                        $query->orderBy('sort_order');
                    },
                    'sections.items' => function($query) {
                        $query->orderBy('sort_order');
                    },
                    'sections.items.category' => function($query) {
                        $query->where('is_active', true);
                    }
                ])
                ->whereIn('category_id', $categoryIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('category_id');
                
                // Преобразуем в массив для быстрого доступа
                foreach ($extraMenusData as $extraMenu) {
                    $extraMenus[$extraMenu->category_id] = $extraMenu;
                }
            }

            // Загружаем подкатегории для каждой главной категории через SQL
            foreach($mainCategories as $category) {
                $childrenData = \DB::select('SELECT id, name, slug, icon, is_active, sort_order FROM shop_categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC', [$category->id]);
                
                // Преобразуем в коллекцию Eloquent моделей
                $children = collect($childrenData)->map(function($item) {
                    $child = new ShopCategory();
                    $child->id = $item->id;
                    $child->name = $item->name;
                    $child->slug = $item->slug;
                    $child->icon = $item->icon;
                    $child->is_active = $item->is_active;
                    $child->sort_order = $item->sort_order;
                    return $child;
                });
                
                $category->children = $children;
                
                // Добавляем экстра-меню к категории, если оно есть
                if (isset($extraMenus[$category->id])) {
                    $category->extra_menu = $extraMenus[$category->id];
                } else {
                    $category->extra_menu = null;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $mainCategories
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getMainCategories: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки категорий: ' . $e->getMessage()
            ], 500);
        }
    }
}
