<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopOrderStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'id' => 1,
                'name' => 'pending',
                'display_name' => 'Обрабатывается',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 10,
                'description' => 'Заказ создан и ожидает обработки',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'confirmed',
                'display_name' => 'Подтвержден',
                'color' => '#3B82F6',
                'is_active' => true,
                'sort_order' => 20,
                'description' => 'Заказ подтвержден и готов к отправке',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'shipped',
                'display_name' => 'Отправлен',
                'color' => '#8B5CF6',
                'is_active' => true,
                'sort_order' => 30,
                'description' => 'Заказ отправлен покупателю',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'name' => 'delivered',
                'display_name' => 'Доставлен',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 40,
                'description' => 'Заказ успешно доставлен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'name' => 'cancelled',
                'display_name' => 'Отменен',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 60,
                'description' => 'Заказ отменен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' =>6,
                'name' => 'finished',
                'display_name' => 'Завершен',
                'color' => '#EF4444',
                'is_active' => true,
                'is_finished' => true,
                'sort_order' => 50,
                'description' => 'Заказ завершен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('shop_order_statuses')->updateOrInsert(
                ['id' => $status['id']],
                $status
            );
        }
    }
}