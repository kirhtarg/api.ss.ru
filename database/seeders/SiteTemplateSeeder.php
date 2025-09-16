<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'id' => 1,
                'name' => 'Default Template',
                'description' => 'Дефолтный шаблон сайта с базовой структурой',
                'folder_name' => 'default',
                'menu_id' => 1,
                'is_active' => 1,
                'is_active_main' => 1,
                'settings' => '{"theme": "light", "footer_style": "dark", "header_sticky": true, "container_width": "1920px"}',
                'sort_order' => 0,
                'created_at' => '2025-09-03 09:09:03',
                'updated_at' => '2025-09-03 09:09:03',
            ],
            [
                'id' => 2,
                'name' => 'Skate&Snow',
                'description' => 'Шаблон для сайта Skate&Snow',
                'folder_name' => 'ss',
                'menu_id' => 1,
                'is_active' => 0,
                'is_active_main' => 0,
                'settings' => '{"theme": "light", "footer_style": "dark", "header_sticky": true, "container_width": "max-w-7xl"}',
                'sort_order' => 1,
                'created_at' => '2025-09-03 13:40:15',
                'updated_at' => '2025-09-03 13:40:15',
            ],
        ];

        foreach ($templates as $template) {
            DB::table('site_templates')->updateOrInsert(
                ['id' => $template['id']],
                $template
            );
        }
    }
}


