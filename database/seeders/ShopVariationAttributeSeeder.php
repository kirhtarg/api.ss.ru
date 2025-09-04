<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopVariationAttributeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $attributes = [
            [
                'id' => 1,
                'name' => 'Размер',
                'type' => 'select',
                'created_at' => '2025-09-02 10:32:07',
                'updated_at' => '2025-09-02 10:32:07',
            ],
            [
                'id' => 2,
                'name' => 'Цвет',
                'type' => 'select',
                'created_at' => '2025-09-02 10:32:07',
                'updated_at' => '2025-09-02 10:32:07',
            ],
            [
                'id' => 3,
                'name' => 'Размер обуви',
                'type' => 'select',
                'created_at' => '2025-09-02 10:32:07',
                'updated_at' => '2025-09-02 10:32:07',
            ],
        ];

        foreach ($attributes as $attribute) {
            DB::table('shop_variation_attributes')->updateOrInsert(
                ['id' => $attribute['id']],
                $attribute
            );
        }

        // Создаем значения атрибутов
        $attributeValues = [
            // Размеры
            ['attribute_id' => 1, 'value' => 'XS', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 1, 'value' => 'S', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 1, 'value' => 'M', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 1, 'value' => 'L', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 1, 'value' => 'XL', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 1, 'value' => 'XXL', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            
            // Цвета
            ['attribute_id' => 2, 'value' => 'Черный', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 2, 'value' => 'Белый', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 2, 'value' => 'Красный', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 2, 'value' => 'Синий', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 2, 'value' => 'Зеленый', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 2, 'value' => 'Желтый', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            
            // Размеры обуви
            ['attribute_id' => 3, 'value' => '36', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '37', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '38', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '39', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '40', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '41', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '42', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '43', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '44', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
            ['attribute_id' => 3, 'value' => '45', 'color_code' => null, 'image_path' => null, 'sort_order' => 0],
        ];

        foreach ($attributeValues as $value) {
            DB::table('shop_variation_attribute_values')->updateOrInsert(
                [
                    'attribute_id' => $value['attribute_id'],
                    'value' => $value['value']
                ],
                array_merge($value, [
                    'created_at' => '2025-09-02 10:32:07',
                    'updated_at' => '2025-09-02 10:32:07',
                ])
            );
        }
    }
}
