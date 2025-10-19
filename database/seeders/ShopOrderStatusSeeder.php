<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopOrderStatus;

class ShopOrderStatusSeeder extends Seeder
{
    public function run(): void
    {
        // Удаляем существующие записи, чтобы избежать дублирования при повторном запуске
        ShopOrderStatus::truncate();

        ShopOrderStatus::create([
            'name' => 'pending',
            'display_name' => 'Обрабатывается',
            'color' => '#F59E0B',
            'is_active' => true,
            'sort_order' => 10,
            'description' => 'Заказ создан и ожидает обработки'
        ]);

        ShopOrderStatus::create([
            'name' => 'confirmed',
            'display_name' => 'Подтвержден',
            'color' => '#3B82F6',
            'is_active' => true,
            'sort_order' => 20,
            'description' => 'Заказ подтвержден и готов к отправке'
        ]);

        ShopOrderStatus::create([
            'name' => 'shipped',
            'display_name' => 'Отправлен',
            'color' => '#8B5CF6',
            'is_active' => true,
            'sort_order' => 30,
            'description' => 'Заказ отправлен покупателю'
        ]);

        ShopOrderStatus::create([
            'name' => 'delivered',
            'display_name' => 'Доставлен',
            'color' => '#10B981',
            'is_active' => true,
            'sort_order' => 40,
            'description' => 'Заказ успешно доставлен'
        ]);

        ShopOrderStatus::create([
            'name' => 'cancelled',
            'display_name' => 'Отменен',
            'color' => '#EF4444',
            'is_active' => true,
            'sort_order' => 50,
            'description' => 'Заказ отменен'
        ]);
    }
}