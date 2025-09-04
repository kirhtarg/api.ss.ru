<?php

namespace Database\Seeders;

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
                'id' => 1,
                'key' => 'site_name',
                'name' => 'Название сайта',
                'value' => 'LLC SKATEandSNOW',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Название сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-09-03 19:50:45',
            ],
            [
                'id' => 2,
                'key' => 'site_description',
                'name' => 'Описание сайта (description)',
                'value' => 'Магазин и аренда оборудования для сноуборда и скейтборда',
                'type' => 'text',
                'group' => 'general',
                'description' => 'Описание сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-09-03 19:50:43',
            ],
            [
                'id' => 3,
                'key' => 'site_logo',
                'name' => 'Логотип сайта',
                'value' => 'images\\settings\\setting_3_1756748468.png',
                'type' => 'image',
                'group' => 'general',
                'description' => 'Логотип в шапке сайта и везде, где необходимо',
                'image_width' => 400,
                'image_height' => 400,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-08-31 06:16:53',
            ],
            [
                'id' => 4,
                'key' => 'main_site',
                'name' => 'URL адрес сайта',
                'value' => 'http://localhost:3000',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Адрес основного сайта',
                'image_width' => 200,
                'image_height' => 200,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-09-03 07:50:38',
            ],
            [
                'id' => 5,
                'key' => 'quick_login',
                'name' => 'Быстрый вход',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Отключает кнопку быстрого входа под тестовым пользователем при аутентификации',
                'image_width' => 200,
                'image_height' => 200,
                'created_at' => '2025-08-30 18:43:14',
                'updated_at' => '2025-08-31 07:14:49',
            ],
            [
                'id' => 6,
                'key' => 'admin_name',
                'name' => 'Название админки',
                'value' => 'Skate&Snow CMS',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Значение переменной - в заголовках админки',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-08-30 19:41:04',
                'updated_at' => '2025-08-31 04:43:30',
            ],
            [
                'id' => 8,
                'key' => 'site_favicon',
                'name' => 'Иконка сайта',
                'value' => 'images/settings/setting_8_1756812882.png',
                'type' => 'image',
                'group' => 'general',
                'description' => 'Иконка сайта, отображаемая на сайте и в админке',
                'image_width' => 32,
                'image_height' => 32,
                'created_at' => '2025-08-30 20:00:22',
                'updated_at' => '2025-09-02 08:34:42',
            ],
            [
                'id' => 9,
                'key' => 'shop_category_img_width',
                'name' => 'Ширина изображения в каталоге',
                'value' => '350',
                'type' => 'number',
                'group' => 'shop',
                'description' => 'Задает ширину, к которой приводится изображение к каталоге товаров, px',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-09-02 08:36:15',
                'updated_at' => '2025-09-02 08:38:01',
            ],
            [
                'id' => 10,
                'key' => 'shop_category_img_height',
                'name' => 'Высота изображения в каталоге товаров',
                'value' => '350',
                'type' => 'number',
                'group' => 'shop',
                'description' => 'Задает высоту, к которой приводится изображение к каталоге товаров, px',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-09-02 08:37:31',
                'updated_at' => '2025-09-02 08:38:01',
            ],
            [
                'id' => 12,
                'key' => 'site_color1',
                'name' => 'Цвет сайта 1',
                'value' => '#000000',
                'type' => 'color',
                'group' => 'site',
                'description' => 'Стиль цвета сайта 1',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-09-03 18:38:01',
                'updated_at' => '2025-09-03 19:54:43',
            ],
            [
                'id' => 13,
                'key' => 'site_color2',
                'name' => 'Цвет сайта 2',
                'value' => '#000000',
                'type' => 'color',
                'group' => 'site',
                'description' => 'Цвет tailwind сайта 2',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-09-03 18:41:13',
                'updated_at' => '2025-09-03 20:55:34',
            ],
            [
                'id' => 14,
                'key' => 'site_preloader_color',
                'name' => 'Цвет прелоадера',
                'value' => '#000000',
                'type' => 'color',
                'group' => 'site',
                'description' => 'Цвет прелоадера главного сайта',
                'image_width' => null,
                'image_height' => null,
                'created_at' => '2025-09-03 20:12:14',
                'updated_at' => '2025-09-03 20:55:00',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['id' => $setting['id']],
                $setting
            );
        }
    }
}
