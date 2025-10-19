<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeederSafe extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Очищаем таблицу settings перед заполнением
        DB::table('settings')->truncate();
        
        // Сбрасываем автоинкремент
        DB::statement('ALTER TABLE settings AUTO_INCREMENT = 1');
        
        $settings = [
            [
                'id' => 1,
                'key' => 'site_name',
                'name' => 'Название сайта',
                'value' => 'SKATE & SNOW',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Название сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-09-15 10:31:17',
            ],
            [
                'id' => 2,
                'key' => 'site_description',
                'name' => 'Описание сайта (description)',
                'value' => 'Интернет-магазин для активного спорта и отдыха!',
                'type' => 'text',
                'group' => 'general',
                'description' => 'Описание сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-09-15 10:31:15',
            ],
            // Добавьте остальные настройки из оригинального SettingSeeder
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->insert($setting);
        }
    }
}
