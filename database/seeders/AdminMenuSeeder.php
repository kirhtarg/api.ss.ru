<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $menuItems = [
            [
                'id' => 1,
                'name' => 'Главная',
                'slug' => 'dashboard',
                'icon' => 'home',
                'url' => '/admin',
                'parent_id' => null,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Настройки',
                'slug' => 'settings',
                'icon' => 'settings',
                'url' => '/admin/settings',
                'parent_id' => null,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'Магазин',
                'slug' => 'shop',
                'icon' => 'shopping-cart',
                'url' => '/admin/shop',
                'parent_id' => null,
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'name' => 'Товары',
                'slug' => 'products',
                'icon' => 'package',
                'url' => '/admin/shop/products',
                'parent_id' => 3,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'name' => 'Заказы',
                'slug' => 'orders',
                'icon' => 'shopping-bag',
                'url' => '/admin/shop/orders',
                'parent_id' => 3,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'name' => 'Пользователи',
                'slug' => 'users',
                'icon' => 'users',
                'url' => '/admin/users',
                'parent_id' => null,
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($menuItems as $item) {
            DB::table('admin_menus')->updateOrInsert(
                ['id' => $item['id']],
                $item
            );
        }
    }
}
