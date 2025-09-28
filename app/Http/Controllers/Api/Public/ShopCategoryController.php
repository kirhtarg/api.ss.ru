<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use Illuminate\Http\Request;

class ShopCategoryController extends Controller
{
    /**
     * Получить главные категории с подкатегориями
     */
    public function getMainCategories()
    {
        try {
            $mainCategories = ShopCategory::active()
                ->main()
                ->ordered()
                ->get();

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
