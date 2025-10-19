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
        $pages = [
            [
                'id' => 1,
                'name' => 'Настройки',
                'slug' => 'settings',
                'description' => 'Редактирование значений переменных системы',
                'is_active' => true,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-15 08:33:31',
            ],
            [
                'id' => 2,
                'name' => 'Магазин',
                'slug' => 'shop',
                'description' => 'Администрирование товаров магазина',
                'is_active' => true,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-15 08:33:31',
            ],
            [
                'id' => 3,
                'name' => 'Настройки сайта',
                'slug' => 'site-settings',
                'description' => 'Настройки основного сайта',
                'is_active' => true,
                'created_at' => '2025-08-30 18:43:13',
                'updated_at' => '2025-09-15 08:33:31',
            ],
        ];

        foreach ($pages as $page) {
            DB::table('admin_pages')->updateOrInsert(
                ['id' => $page['id']],
                $page
            );
        }
    }
}
