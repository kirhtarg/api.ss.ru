<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Администратор',
                'description' => 'Полный доступ к системе',
                'is_active' => true,
            ],
            [
                'name' => 'user',
                'display_name' => 'Пользователь',
                'description' => 'Обычный пользователь без доступа к админке',
                'is_active' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}
