<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopCategory;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Основные категории
        $categories = [
            [
                'name' => 'Скейтборды',
                'description' => 'Профессиональные скейтборды для всех уровней катания',
                'icon' => 'mdi:skateboard',
                'slug' => 'skateboards',
                'is_active' => true,
                'sort_order' => 1
            ],
            [
                'name' => 'Сноуборды',
                'description' => 'Качественные сноуборды для зимних видов спорта',
                'icon' => 'mdi:snowboard',
                'slug' => 'snowboards',
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'name' => 'Защита',
                'description' => 'Защитное снаряжение для безопасного катания',
                'icon' => 'mdi:shield-check',
                'slug' => 'protection',
                'is_active' => true,
                'sort_order' => 3
            ],
            [
                'name' => 'Одежда',
                'description' => 'Специальная одежда для скейтбординга и сноубординга',
                'icon' => 'mdi:tshirt-crew',
                'slug' => 'clothing',
                'is_active' => true,
                'sort_order' => 4
            ],
            [
                'name' => 'Аксессуары',
                'description' => 'Дополнительные аксессуары и запчасти',
                'icon' => 'mdi:tools',
                'slug' => 'accessories',
                'is_active' => true,
                'sort_order' => 5
            ]
        ];

        // Создаем основные категории
        foreach ($categories as $categoryData) {
            ShopCategory::create($categoryData);
        }

        // Подкатегории для скейтбордов
        $skateboardCategory = ShopCategory::where('slug', 'skateboards')->first();
        if ($skateboardCategory) {
            $skateboardSubcategories = [
                [
                    'name' => 'Доски',
                    'description' => 'Скейтборд доски различных размеров и форм',
                    'icon' => 'mdi:board',
                    'slug' => 'skateboard-decks',
                    'is_active' => true,
                    'sort_order' => 1,
                    'parent_id' => $skateboardCategory->id
                ],
                [
                    'name' => 'Колеса',
                    'description' => 'Колеса для скейтбордов разных размеров и твердости',
                    'icon' => 'mdi:circle',
                    'slug' => 'skateboard-wheels',
                    'is_active' => true,
                    'sort_order' => 2,
                    'parent_id' => $skateboardCategory->id
                ],
                [
                    'name' => 'Подвески',
                    'description' => 'Подвески (траки) для скейтбордов',
                    'icon' => 'mdi:car-suspension',
                    'slug' => 'skateboard-trucks',
                    'is_active' => true,
                    'sort_order' => 3,
                    'parent_id' => $skateboardCategory->id
                ]
            ];

            foreach ($skateboardSubcategories as $subcategoryData) {
                ShopCategory::create($subcategoryData);
            }
        }

        // Подкатегории для сноубордов
        $snowboardCategory = ShopCategory::where('slug', 'snowboards')->first();
        if ($snowboardCategory) {
            $snowboardSubcategories = [
                [
                    'name' => 'Доски',
                    'description' => 'Сноуборды для различных стилей катания',
                    'icon' => 'mdi:board',
                    'slug' => 'snowboard-decks',
                    'is_active' => true,
                    'sort_order' => 1,
                    'parent_id' => $snowboardCategory->id
                ],
                [
                    'name' => 'Крепления',
                    'description' => 'Крепления для сноубордов',
                    'icon' => 'mdi:lock',
                    'slug' => 'snowboard-bindings',
                    'is_active' => true,
                    'sort_order' => 2,
                    'parent_id' => $snowboardCategory->id
                ],
                [
                    'name' => 'Ботинки',
                    'description' => 'Ботинки для сноубординга',
                    'icon' => 'mdi:shoe-print',
                    'slug' => 'snowboard-boots',
                    'is_active' => true,
                    'sort_order' => 3,
                    'parent_id' => $snowboardCategory->id
                ]
            ];

            foreach ($snowboardSubcategories as $subcategoryData) {
                ShopCategory::create($subcategoryData);
            }
        }

        // Подкатегории для защиты
        $protectionCategory = ShopCategory::where('slug', 'protection')->first();
        if ($protectionCategory) {
            $protectionSubcategories = [
                [
                    'name' => 'Шлемы',
                    'description' => 'Защитные шлемы для скейтбординга и сноубординга',
                    'icon' => 'mdi:helmet',
                    'slug' => 'helmets',
                    'is_active' => true,
                    'sort_order' => 1,
                    'parent_id' => $protectionCategory->id
                ],
                [
                    'name' => 'Наколенники',
                    'description' => 'Наколенники для защиты коленей',
                    'icon' => 'mdi:shield',
                    'slug' => 'knee-pads',
                    'is_active' => true,
                    'sort_order' => 2,
                    'parent_id' => $protectionCategory->id
                ],
                [
                    'name' => 'Налокотники',
                    'description' => 'Налокотники для защиты локтей',
                    'icon' => 'mdi:shield',
                    'slug' => 'elbow-pads',
                    'is_active' => true,
                    'sort_order' => 3,
                    'parent_id' => $protectionCategory->id
                ]
            ];

            foreach ($protectionSubcategories as $subcategoryData) {
                ShopCategory::create($subcategoryData);
            }
        }
    }
}
