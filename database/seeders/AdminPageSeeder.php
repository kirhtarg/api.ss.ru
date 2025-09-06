<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminPageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminPages = [
            [
                'id' => 1,
                'name' => 'Settings',
                'slug' => 'settings',
                'title' => 'Администратор',
                'description' => 'Настройки системы',
                'icon' => 'clarity:administrator-line',
                'component' => 'Dashboard',
                'order' => 0,
                'is_active' => 1,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-03 10:44:15',
            ],
            [
                'id' => 2,
                'name' => 'Shop',
                'slug' => 'shop',
                'title' => 'Магазин',
                'description' => 'Администрирование магазина',
                'icon' => 'i-typcn:shopping-cart',
                'component' => 'Shop',
                'order' => 1,
                'is_active' => 1,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-03 10:44:15',
            ],
            [
                'id' => 3,
                'name' => 'Settings2',
                'slug' => 'settings2',
                'title' => 'Администратор2',
                'description' => 'typcn:shopping-cart',
                'icon' => '',
                'component' => 'Settings',
                'order' => 2,
                'is_active' => 0,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-03 10:45:34',
            ],
        ];

        foreach ($adminPages as $page) {
            DB::table('admin_pages')->updateOrInsert(
                ['id' => $page['id']],
                $page
            );
        }

        // Создаем связи страниц с ролями
        $pageRoles = [
            ['admin_page_id' => 2, 'role_id' => 3], // manager имеет доступ к shop
        ];

        foreach ($pageRoles as $pageRole) {
            DB::table('admin_page_role')->updateOrInsert(
                ['admin_page_id' => $pageRole['admin_page_id'], 'role_id' => $pageRole['role_id']],
                $pageRole
            );
        }
    }
}
