<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopDeliveryStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'id' => 1,
                'name' => 'created',
                'display_name' => 'Создан',
                'color' => '#6B7280',
                'is_active' => true,
                'sort_order' => 10,
                'description' => 'Заказ создан',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'transferred_to_courier',
                'display_name' => 'Передан в ТК',
                'color' => '#3B82F6',
                'is_active' => true,
                'sort_order' => 20,
                'description' => 'Заказ передан в транспортную компанию',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'cancelled',
                'display_name' => 'Отменен',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 30,
                'description' => 'Доставка отменена',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'name' => 'in_transit',
                'display_name' => 'В пути',
                'color' => '#8B5CF6',
                'is_active' => true,
                'sort_order' => 40,
                'description' => 'Заказ в пути',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'name' => 'at_pickup_point',
                'display_name' => 'В месте выдачи',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 50,
                'description' => 'Заказ прибыл в место выдачи',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'name' => 'delivered',
                'display_name' => 'Выдан',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 60,
                'description' => 'Заказ выдан получателю',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('shop_delivery_statuses')->updateOrInsert(
                ['id' => $status['id']],
                $status
            );
        }
    }
}
