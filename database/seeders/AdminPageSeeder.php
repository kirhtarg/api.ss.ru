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
                'name' => 'Site',
                'slug' => 'site',
                'title' => 'Сайт',
                'description' => 'Администрирование основного сайта',
                'icon' => 'fluent-mdl2:website',
                'component' => 'Settings',
                'order' => 2,
                'is_active' => 1,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-15 03:08:28',
            ],
        ];

        foreach ($adminPages as $page) {
            DB::table('admin_pages')->updateOrInsert(
                ['id' => $page['id']],
                $page
            );
        }
    }
}
