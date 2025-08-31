<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'site_name',
                'name' => 'Название сайта',
                'value' => 'Skate and Snow',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Название сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin_name',
                'name' => 'Название админ-панели',
                'value' => 'Ваше значение',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Название админ-панели',
                'image_width' => null,
                'image_height' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'site_description',
                'name' => 'Описание сайта',
                'value' => 'Магазин и аренда оборудования для сноуборда и скейтборда',
                'type' => 'text',
                'group' => 'general',
                'description' => 'Описание сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin_email',
                'name' => 'Email администратора',
                'value' => 'admin@skateandsnow.ru',
                'type' => 'email',
                'group' => 'admin',
                'description' => 'Email администратора',
                'image_width' => null,
                'image_height' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'quick_login',
                'name' => 'Быстрый вход',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'admin',
                'description' => 'Показывать кнопку быстрого входа для администратора',
                'image_width' => null,
                'image_height' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_avatar_width',
                'name' => 'Ширина аватара по умолчанию',
                'value' => '200',
                'type' => 'integer',
                'group' => 'images',
                'description' => 'Ширина аватара по умолчанию',
                'image_width' => 200,
                'image_height' => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_avatar_height',
                'name' => 'Высота аватара по умолчанию',
                'value' => '200',
                'type' => 'integer',
                'group' => 'images',
                'description' => 'Высота аватара по умолчанию',
                'image_width' => 200,
                'image_height' => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->insert($setting);
        }
    }
}
