<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'id' => 1,
                'name' => 'Default Shop Template',
                'description' => 'Дефолтный шаблон магазина с базовыми компонентами GoodCard и GoodPage',
                'folder_name' => 'default',
                'is_active' => 0,
                'is_active_card' => 0,
                'is_active_page' => 0,
                'settings' => '{"author": "System", "version": "1.0.0", "features": ["responsive", "modern_design"]}',
                'sort_order' => 0,
                'created_at' => '2025-09-12 23:02:51',
                'updated_at' => '2025-09-12 23:02:51',
            ],
            [
                'id' => 2,
                'name' => 'Skate&Snow',
                'description' => 'Шаблон магазина Skate&Snow',
                'folder_name' => 'ss',
                'is_active' => 1,
                'is_active_card' => 1,
                'is_active_page' => 1,
                'settings' => '{"author": "System", "version": "1.0.0", "features": ["responsive", "modern_design"], "card_image_width": "70%", "card_border_color": "#cccccc", "card_border_style": "solid", "card_border_width": "1px", "card_border_radius": "16px", "card_load_animation": "fade-in-scale", "card_background_image": "bg-card-8.jpg", "card_image_responsive": true, "card_slideshow_enabled": true, "card_image_aspect_ratio": "square", "card_slideshow_interval": 1500, "card_image_border_radius": "6px", "card_slideshow_transition": "fade", "card_hover_effects_enabled": true}',
                'sort_order' => 1,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        foreach ($templates as $template) {
            DB::table('shop_templates')->updateOrInsert(
                ['id' => $template['id']],
                $template
            );
        }
    }
}







