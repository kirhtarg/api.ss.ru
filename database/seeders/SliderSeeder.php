<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SliderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем 3 тестовых слайдера
        $sliders = [
            [
                'name' => 'Главный слайдер',
                'transition_type' => 'fade',
                'control_type' => 'auto',
                'auto_interval' => 5000,
                'transition_duration' => 1000,
                'title_position' => 'center',
                'text_position' => 'center',
                'is_active' => true,
                'sort_order' => 1,
                'images' => [
                    [
                        'image_path' => 'slider1_1.jpg',
                        'title' => 'Добро пожаловать в SKATE & SNOW',
                        'text' => 'Лучшее оборудование для сноуборда и скейтборда',
                        'link' => '/catalog',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 1
                    ],
                    [
                        'image_path' => 'slider1_2.jpg',
                        'title' => 'Новые коллекции 2024',
                        'text' => 'Самые актуальные модели для активного спорта',
                        'link' => '/catalog?new=true',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 2
                    ]
                ]
            ],
            [
                'name' => 'Слайдер акций',
                'transition_type' => 'slide',
                'control_type' => 'manual',
                'auto_interval' => 7000,
                'transition_duration' => 800,
                'title_position' => 'top-left',
                'text_position' => 'bottom-right',
                'is_active' => true,
                'sort_order' => 2,
                'images' => [
                    [
                        'image_path' => 'slider2_1.jpg',
                        'title' => 'Скидки до 50%',
                        'text' => 'На все товары из прошлых коллекций',
                        'link' => '/catalog?sale=true',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 1
                    ],
                    [
                        'image_path' => 'slider2_2.jpg',
                        'title' => 'Бесплатная доставка',
                        'text' => 'При заказе от 5000 рублей',
                        'link' => '/delivery',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 2
                    ]
                ]
            ],
            [
                'name' => 'Слайдер брендов',
                'transition_type' => 'zoom',
                'control_type' => 'auto',
                'auto_interval' => 6000,
                'transition_duration' => 1200,
                'title_position' => 'bottom-center',
                'text_position' => 'top-center',
                'is_active' => true,
                'sort_order' => 3,
                'images' => [
                    [
                        'image_path' => 'slider3_1.jpg',
                        'title' => 'Burton',
                        'text' => 'Официальный дилер Burton в России',
                        'link' => '/brands/burton',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 1
                    ],
                    [
                        'image_path' => 'slider3_2.jpg',
                        'title' => 'Nike SB',
                        'text' => 'Легендарные скейтборды Nike SB',
                        'link' => '/brands/nike-sb',
                        'link_type' => 'internal',
                        'is_active' => true,
                        'sort_order' => 2
                    ]
                ]
            ]
        ];

        foreach ($sliders as $sliderData) {
            $images = $sliderData['images'];
            unset($sliderData['images']);
            
            // Добавляем ID и временные метки
            $sliderData['id'] = $sliderData['sort_order'];
            $sliderData['created_at'] = now();
            $sliderData['updated_at'] = now();
            
            // Создаем слайдер
            DB::table('sliders')->updateOrInsert(
                ['id' => $sliderData['id']],
                $sliderData
            );
            
            // Создаем изображения для слайдера
            foreach ($images as $imageData) {
                $imageData['slider_id'] = $sliderData['id'];
                $imageData['created_at'] = now();
                $imageData['updated_at'] = now();
                
                DB::table('slider_images')->updateOrInsert(
                    ['slider_id' => $imageData['slider_id'], 'sort_order' => $imageData['sort_order']],
                    $imageData
                );
            }
        }
    }
}
