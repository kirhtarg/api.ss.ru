<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('site_menus')->insert([
            [
                'id' => 1,
                'name' => 'Основное меню',
                'description' => 'Главное меню сайта',
                'is_active' => 1,
                'template_name' => null,
                'settings' => null,
                'sort_order' => 0,
                'created_at' => '2025-09-03 13:40:15',
                'updated_at' => '2025-09-03 15:28:29',
            ],
        ]);
    }
}
