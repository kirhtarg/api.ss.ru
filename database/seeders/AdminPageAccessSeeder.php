<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminPageAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получаем ID ролей
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $managerRoleId = DB::table('roles')->where('name', 'manager')->value('id');
        $siteRoleId = DB::table('roles')->where('name', 'site')->value('id');

        // Получаем ID страниц
        $shopPageId = DB::table('admin_pages')->where('slug', 'shop')->value('id');
        $sitePageId = DB::table('admin_pages')->where('slug', 'site')->value('id');

        // Администратор имеет доступ ко всем страницам
        if ($adminRoleId) {
            $allPages = DB::table('admin_pages')->pluck('id');
            foreach ($allPages as $pageId) {
                DB::table('admin_page_role')->updateOrInsert(
                    [
                        'admin_page_id' => $pageId,
                        'role_id' => $adminRoleId,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // Менеджер имеет доступ к разделу shop
        if ($managerRoleId && $shopPageId) {
            DB::table('admin_page_role')->updateOrInsert(
                [
                    'admin_page_id' => $shopPageId,
                    'role_id' => $managerRoleId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Роль site имеет доступ к разделу site
        if ($siteRoleId && $sitePageId) {
            DB::table('admin_page_role')->updateOrInsert(
                [
                    'admin_page_id' => $sitePageId,
                    'role_id' => $siteRoleId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('Доступ к страницам админки настроен успешно!');
    }
}
