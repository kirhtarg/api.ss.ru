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
                'menu_template_id' => null,
                'auth_template_id' => null,
                'is_active' => true,
                'settings' => '{"theme": "light", "footer_style": "dark", "header_sticky": true, "container_width": "max-w-7xl"}',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'SS Template',
                'description' => 'Шаблон сайта SS с современным дизайном',
                'folder_name' => 'ss',
                'menu_template_id' => null,
                'auth_template_id' => null,
                'is_active' => false,
                'settings' => '{"theme": "dark", "footer_style": "light", "header_sticky": true, "container_width": "max-w-6xl"}',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
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
