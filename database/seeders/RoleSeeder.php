<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'id' => 1,
                'name' => 'admin',
                'display_name' => 'Администратор',
                'description' => 'Полный доступ ко всем разделам админки',
                'permissions' => null,
                'is_active' => 1,
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 2,
                'name' => 'user',
                'display_name' => 'Пользователь',
                'description' => 'Зарегистрированый пользователь',
                'permissions' => null,
                'is_active' => 1,
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 3,
                'name' => 'manager',
                'display_name' => 'Менеджер',
                'description' => 'Доступ к администрированию магазина',
                'permissions' => null,
                'is_active' => 1,
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 4,
                'name' => 'site',
                'display_name' => 'Сайт',
                'description' => 'Редактирование параметров и страниц сайта',
                'permissions' => '[]',
                'is_active' => 1,
                'created_at' => '2025-09-15 03:10:49',
                'updated_at' => '2025-09-15 03:10:49',
            ],
            [
                'id' => 5,
                'name' => 'editor',
                'display_name' => 'Редактор',
                'description' => 'Администрирование блога',
                'permissions' => '[]',
                'is_active' => 1,
                'created_at' => '2025-09-15 03:11:48',
                'updated_at' => '2025-09-15 03:11:48',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['id' => $role['id']],
                $role
            );
        }
    }
}




