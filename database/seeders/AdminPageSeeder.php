<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminPageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pages = [
            ['name' => 'Dashboard', 'slug' => 'dashboard', 'title' => 'Панель управления', 'description' => 'Главная страница администратора', 'icon' => 'fas fa-tachometer-alt', 'component' => 'Dashboard', 'order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),],
            ['name' => 'Users', 'slug' => 'users', 'title' => 'Пользователи', 'description' => 'Управление пользователями системы', 'icon' => 'fas fa-users', 'component' => 'Users', 'order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),],
            ['name' => 'Settings', 'slug' => 'settings', 'title' => 'Настройки', 'description' => 'Настройки системы', 'icon' => 'fas fa-cog', 'component' => 'Settings', 'order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),],
            ['name' => 'Shop', 'slug' => 'shop', 'title' => 'Магазин', 'description' => 'Управление товарами и категориями', 'icon' => 'fas fa-shopping-cart', 'component' => 'Shop', 'order' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),],
        ];
        foreach ($pages as $page) { DB::table('admin_pages')->insert($page); }
    }
}
