<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopOrderStatus;

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
                'display_name' => 'Обрабатывается',
                'color' => '#F59E0B',
                'is_active' => true,
                'is_finished' => false,
                'is_cancelled' => false,
                'sort_order' => 1,
                'description' => 'Заказ принят и находится в обработке'
            ],
            [
                'name' => 'confirmed',
                'display_name' => 'Подтвержден',
                'color' => '#3B82F6',
                'is_active' => true,
                'is_finished' => false,
                'is_cancelled' => false,
                'sort_order' => 2,
                'description' => 'Заказ подтвержден менеджером'
            ],
            [
                'name' => 'paid',
                'display_name' => 'Оплачен',
                'color' => '#10B981',
                'is_active' => true,
                'is_finished' => false,
                'is_cancelled' => false,
                'sort_order' => 3,
                'description' => 'Заказ оплачен'
            ],
            [
                'name' => 'shipped',
                'display_name' => 'Отправлен',
                'color' => '#8B5CF6',
                'is_active' => true,
                'is_finished' => false,
                'is_cancelled' => false,
                'sort_order' => 4,
                'description' => 'Заказ отправлен покупателю'
            ],
            [
                'name' => 'delivered',
                'display_name' => 'Доставлен',
                'color' => '#059669',
                'is_active' => false,
                'is_finished' => true,
                'is_cancelled' => false,
                'sort_order' => 5,
                'description' => 'Заказ доставлен покупателю'
            ],
            [
                'name' => 'cancelled',
                'display_name' => 'Отменен',
                'color' => '#EF4444',
                'is_active' => false,
                'is_finished' => true,
                'is_cancelled' => true,
                'sort_order' => 6,
                'description' => 'Заказ отменен'
            ],
            [
                'name' => 'refunded',
                'display_name' => 'Возвращен',
                'color' => '#F97316',
                'is_active' => false,
                'is_finished' => true,
                'is_cancelled' => false,
                'sort_order' => 7,
                'description' => 'Средства возвращены покупателю'
            ]
        ];

        foreach ($statuses as $status) {
            ShopOrderStatus::updateOrCreate(
                ['name' => $status['name']],
                $status
            );
        }
    }
}