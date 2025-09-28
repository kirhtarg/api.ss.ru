<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopDeliveryMethod;

class ShopDeliveryMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deliveryMethods = [
            [
                'name' => 'Самовывоз',
                'type' => 'pickup',
                'is_active' => true,
                'cost' => 0,
                'free_from' => null,
                'description' => 'Самовывоз из нашего магазина',
                'settings' => [
                    'address' => 'Адрес магазина',
                    'working_hours' => 'Пн-Пт: 10:00-20:00, Сб-Вс: 10:00-18:00',
                    'phone' => '+7 (999) 123-45-67'
                ],
                'sort_order' => 1,
                'is_default' => true
            ],
            [
                'name' => 'Курьерская доставка',
                'type' => 'courier',
                'is_active' => true,
                'cost' => 300,
                'free_from' => 3000,
                'description' => 'Доставка курьером по городу',
                'settings' => [
                    'delivery_time' => '1-2 дня',
                    'working_hours' => 'Пн-Пт: 9:00-18:00'
                ],
                'sort_order' => 2,
                'is_default' => false
            ],
            [
                'name' => 'СДЭК',
                'type' => 'cdek',
                'is_active' => true,
                'cost' => 250,
                'free_from' => 2500,
                'description' => 'Доставка через службу СДЭК',
                'settings' => [
                    'api_key' => '',
                    'sender_city_id' => '',
                    'delivery_time' => '2-5 дней'
                ],
                'sort_order' => 3,
                'is_default' => false
            ],
            [
                'name' => 'Почта России',
                'type' => 'post',
                'is_active' => true,
                'cost' => 200,
                'free_from' => 2000,
                'description' => 'Доставка через Почту России',
                'settings' => [
                    'delivery_time' => '5-14 дней',
                    'tracking' => true
                ],
                'sort_order' => 4,
                'is_default' => false
            ]
        ];

        foreach ($deliveryMethods as $method) {
            ShopDeliveryMethod::create($method);
        }
    }
}
