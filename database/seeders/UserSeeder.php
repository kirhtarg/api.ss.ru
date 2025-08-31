<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем пользователя Admin
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'email' => 'admin@skateandsnow.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаем роль admin
        $adminRoleId = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'display_name' => 'Администратор',
            'description' => 'Полный доступ к системе',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаем роль manager
        $managerRoleId = DB::table('roles')->insertGetId([
            'name' => 'manager',
            'display_name' => 'Менеджер',
            'description' => 'Ограниченный доступ к системе',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаем роль user
        $userRoleId = DB::table('roles')->insertGetId([
            'name' => 'user',
            'display_name' => 'Пользователь',
            'description' => 'Обычный пользователь',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Привязываем роль admin к пользователю Admin
        DB::table('user_roles')->insert([
            'user_id' => $adminId,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаем тестового пользователя с ролью user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Тестовый пользователь',
            'email' => 'user@skateandsnow.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Привязываем роль user к тестовому пользователю
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $userRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
