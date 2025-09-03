<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SiteMenu;
use App\Models\SiteAuthBlock;
use App\Models\SiteTemplate;
use App\Models\SiteMenuItem;

class SiteTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем дефолтный шаблон меню
        $defaultMenu = SiteMenu::firstOrCreate(
            ['template_name' => 'default'],
            [
                'name' => 'Default Menu',
                'description' => 'Дефолтный шаблон меню сайта',
                'is_active' => true,
                'settings' => [
                    'style' => 'horizontal',
                    'show_icons' => false,
                    'mobile_breakpoint' => 'md'
                ],
                'sort_order' => 0,
            ]
        );

        // Создаем дефолтный шаблон блока авторизации
        $defaultAuthBlock = SiteAuthBlock::firstOrCreate(
            ['template_name' => 'default'],
            [
                'name' => 'Default Auth Block',
                'description' => 'Дефолтный шаблон блока авторизации',
                'is_active' => true,
                'settings' => [
                    'show_notifications' => true,
                    'show_cart' => true,
                    'show_profile' => true
                ],
                'sort_order' => 0,
            ]
        );

        // Создаем дефолтный шаблон сайта
        $defaultTemplate = SiteTemplate::firstOrCreate(
            ['folder_name' => 'default'],
            [
                'name' => 'Default Template',
                'description' => 'Дефолтный шаблон сайта с базовой структурой',
                'menu_template_id' => $defaultMenu->id,
                'auth_template_id' => $defaultAuthBlock->id,
                'is_active' => true,
                'settings' => [
                    'theme' => 'light',
                    'container_width' => 'max-w-7xl',
                    'header_sticky' => true,
                    'footer_style' => 'dark'
                ],
                'sort_order' => 0,
            ]
        );

        // Создаем дефолтные пункты меню
        $menuItems = [
            [
                'title' => 'Главная',
                'url' => '/',
                'parent_id' => null,
                'sort_order' => 1,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Каталог',
                'url' => '/catalog',
                'parent_id' => null,
                'sort_order' => 2,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Скейтборды',
                'url' => '/catalog/skateboards',
                'parent_id' => null, // Будет обновлено после создания родительского пункта
                'sort_order' => 1,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Сноуборды',
                'url' => '/catalog/snowboards',
                'parent_id' => null, // Будет обновлено после создания родительского пункта
                'sort_order' => 2,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Аксессуары',
                'url' => '/catalog/accessories',
                'parent_id' => null, // Будет обновлено после создания родительского пункта
                'sort_order' => 3,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Одежда',
                'url' => '/catalog/clothing',
                'parent_id' => null, // Будет обновлено после создания родительского пункта
                'sort_order' => 4,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'О нас',
                'url' => '/about',
                'parent_id' => null,
                'sort_order' => 3,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
            [
                'title' => 'Контакты',
                'url' => '/contacts',
                'parent_id' => null,
                'sort_order' => 4,
                'is_active' => true,
                'target' => '_self',
                'attributes' => null,
            ],
        ];

        $createdItems = [];
        foreach ($menuItems as $item) {
            $createdItems[] = SiteMenuItem::firstOrCreate(
                ['title' => $item['title'], 'url' => $item['url']],
                $item
            );
        }

        // Обновляем parent_id для подпунктов каталога
        $catalogItem = $createdItems[1]; // Каталог
        $catalogSubItems = array_slice($createdItems, 2, 4); // Подпункты каталога
        
        foreach ($catalogSubItems as $subItem) {
            $subItem->update(['parent_id' => $catalogItem->id]);
        }

        $this->command->info('Дефолтные шаблоны сайта созданы успешно!');
    }
}
