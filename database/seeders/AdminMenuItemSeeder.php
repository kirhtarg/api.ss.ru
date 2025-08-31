<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminMenuItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $menuItems = [
            [
                'page_id' => 1, // Dashboard
                'parent_id' => null,
                'icon' => 'fas fa-tachometer-alt',
                'label' => 'Панель управления',
                'href' => '/dashboard',
                'description' => 'Главная страница администратора',
                'order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'page_id' => 2, // Users
                'parent_id' => null,
                'icon' => 'fas fa-users',
                'label' => 'Пользователи',
                'href' => '/users',
                'description' => 'Управление пользователями системы',
                'order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'page_id' => 3, // Settings
                'parent_id' => null,
                'icon' => 'fas fa-cog',
                'label' => 'Настройки',
                'href' => '/settings',
                'description' => 'Настройки системы',
                'order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($menuItems as $item) {
            DB::table('admin_menu_items')->insert($item);
        }
    }
}
