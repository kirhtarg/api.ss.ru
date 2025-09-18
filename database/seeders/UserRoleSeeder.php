<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userRoles = [
            [
                'id' => 2,
                'user_id' => 1,
                'role_id' => 1,
                'is_active' => 1,
                'assigned_at' => '2025-09-02 10:56:38',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 4,
                'user_id' => 3,
                'role_id' => 3,
                'is_active' => 1,
                'assigned_at' => '2025-09-02 11:55:51',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 5,
                'user_id' => 2,
                'role_id' => 3,
                'is_active' => 1,
                'assigned_at' => '2025-09-02 11:56:12',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 10,
                'user_id' => 8,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-03 18:59:05',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 11,
                'user_id' => 9,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-03 19:02:33',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 12,
                'user_id' => 10,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-05 06:52:34',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 13,
                'user_id' => 11,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-05 06:53:20',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 14,
                'user_id' => 12,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-05 06:58:57',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 15,
                'user_id' => 13,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-05 08:49:26',
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 21,
                'user_id' => 19,
                'role_id' => 2,
                'is_active' => 1,
                'assigned_at' => '2025-09-05 19:26:28',
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        foreach ($userRoles as $userRole) {
            DB::table('user_roles')->updateOrInsert(
                ['id' => $userRole['id']],
                $userRole
            );
        }
    }
}







