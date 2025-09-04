<?php

namespace Database\Seeders;

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
        $users = [
            [
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@skateandsnow.ru',
                'google_id' => null,
                'vk_id' => null,
                'yandex_id' => null,
                'avatar' => 'images/users/avatar_1_1756810643.png',
                'avatar_url' => null,
                'email_verified_at' => '2025-08-30 18:43:13',
                'password' => '$2y$12$x6IWINE9FAyoXGp/sKUeBeA18fRXNCQWKeteWu1pGYCyA9opc6WdG',
                'is_active' => 1,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => null,
                'updated_at' => '2025-09-02 07:57:23',
            ],
            [
                'id' => 2,
                'name' => 'Менеджер',
                'email' => 'manager@kirhtarg.ru',
                'google_id' => null,
                'vk_id' => null,
                'yandex_id' => null,
                'avatar' => null,
                'avatar_url' => null,
                'email_verified_at' => null,
                'password' => '$2y$12$GMPE5os5rPrPsj1tuyJNQODSnxIJgNxVVX8F3HPMx5VhxzIFIBC7.',
                'is_active' => 1,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => '2025-09-02 08:50:17',
                'updated_at' => '2025-09-02 08:50:17',
            ],
            [
                'id' => 3,
                'name' => 'Test',
                'email' => 'test@test.ru',
                'google_id' => null,
                'vk_id' => null,
                'yandex_id' => null,
                'avatar' => null,
                'avatar_url' => null,
                'email_verified_at' => null,
                'password' => '$2y$12$vY5TtxU8YP20vOfIyt0i6O2WEIU493ZvsQ2dx9piarNNQUTSlXbjy',
                'is_active' => 1,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => '2025-09-02 08:55:51',
                'updated_at' => '2025-09-02 08:55:51',
            ],
            [
                'id' => 8,
                'name' => 'Hoy',
                'email' => 'durshlag@mail.ru',
                'google_id' => null,
                'vk_id' => null,
                'yandex_id' => null,
                'avatar' => null,
                'avatar_url' => null,
                'email_verified_at' => null,
                'password' => '$2y$12$DrkersPtffsbv6Vzec8e8OvzQE.ySEab3AF2KWAUqmkAlleEg1uH.',
                'is_active' => 1,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => '2025-09-03 18:59:05',
                'updated_at' => '2025-09-03 18:59:05',
            ],
            [
                'id' => 9,
                'name' => 'ппп',
                'email' => 'durshlag2@mail.ru',
                'google_id' => null,
                'vk_id' => null,
                'yandex_id' => null,
                'avatar' => null,
                'avatar_url' => '2025-09-03 19:03:22',
                'email_verified_at' => null,
                'password' => '$2y$12$TyexwNUVBeXISmn7cu8EteRvB9qlTV7TpvlcY83bb4swzVSsPKsUy',
                'is_active' => 1,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => '2025-09-03 19:02:33',
                'updated_at' => '2025-09-03 19:03:22',
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['id' => $user['id']],
                $user
            );
        }

        // Создаем связи пользователей с ролями
        $userRoles = [
            ['user_id' => 1, 'role_id' => 1, 'is_active' => 1, 'assigned_at' => '2025-09-02 10:56:38'],
            ['user_id' => 3, 'role_id' => 3, 'is_active' => 1, 'assigned_at' => '2025-09-02 11:55:51'],
            ['user_id' => 2, 'role_id' => 3, 'is_active' => 1, 'assigned_at' => '2025-09-02 11:56:12'],
            ['user_id' => 8, 'role_id' => 2, 'is_active' => 1, 'assigned_at' => '2025-09-03 18:59:05'],
            ['user_id' => 9, 'role_id' => 2, 'is_active' => 1, 'assigned_at' => '2025-09-03 19:02:33'],
        ];

        foreach ($userRoles as $userRole) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $userRole['user_id'], 'role_id' => $userRole['role_id']],
                $userRole
            );
        }
    }
}
