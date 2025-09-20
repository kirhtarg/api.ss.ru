<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopOrderStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'name' => 'pending',
                'display_name' => 'Ожидает обработки',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Заказ создан и ожидает обработки',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'processing',
                'display_name' => 'В обработке',
                'color' => '#3B82F6',
                'is_active' => true,
                'sort_order' => 2,
                'description' => 'Заказ обрабатывается менеджером',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'shipped',
                'display_name' => 'Отправлен',
                'color' => '#8B5CF6',
                'is_active' => true,
                'sort_order' => 3,
                'description' => 'Заказ отправлен клиенту',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'delivered',
                'display_name' => 'Доставлен',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 4,
                'description' => 'Заказ успешно доставлен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'cancelled',
                'display_name' => 'Отменен',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 5,
                'description' => 'Заказ отменен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('shop_order_statuses')->insert($statuses);
    }
}
