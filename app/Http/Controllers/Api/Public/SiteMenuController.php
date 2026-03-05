<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use App\Models\SiteTemplate;
use Illuminate\Http\JsonResponse;

class SiteMenuController extends Controller
{
    /**
     * Получить меню для активного шаблона
     */
    public function getMenu(): JsonResponse
    {
        try {
            // Получаем активный шаблон
            $template = SiteTemplate::getActive();

            if (! $template || ! $template->menu_id) {
                // Возвращаем дефолтное меню
                return response()->json([
                    'success' => true,
                    'data' => $this->getDefaultMenuItems(),
                ]);
            }

            // Получаем пункты меню для активного шаблона
            $menuItems = SiteMenuItem::getMenuTree($template->menu_id);

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
     * Получить дефолтные пункты меню
     */
    private function getDefaultMenuItems()
    {
        return [
            [
                'id' => 1,
                'title' => 'Главная',
                'url' => '/',
                'target' => '_self',
                'attributes' => [],
                'children' => [],
            ],
            [
                'id' => 2,
                'title' => 'Каталог',
                'url' => '/catalog',
                'target' => '_self',
                'attributes' => [],
                'children' => [],
            ],
            [
                'id' => 3,
                'title' => 'О нас',
                'url' => '/about',
                'target' => '_self',
                'attributes' => [],
                'children' => [],
            ],
            [
                'id' => 4,
                'title' => 'Контакты',
                'url' => '/contacts',
                'target' => '_self',
                'attributes' => [],
                'children' => [],
            ],
        ];
    }
}
