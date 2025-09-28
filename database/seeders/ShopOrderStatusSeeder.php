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
            'name' => 'Обрабатывается',
            'color' => 'yellow',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        ShopOrderStatus::create([
            'name' => 'Подтвержден',
            'color' => 'blue',
            'is_active' => true,
            'sort_order' => 20,
        ]);

        ShopOrderStatus::create([
            'name' => 'Отправлен',
            'color' => 'purple',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        ShopOrderStatus::create([
            'name' => 'Доставлен',
            'color' => 'green',
            'is_active' => true,
            'sort_order' => 40,
        ]);

        ShopOrderStatus::create([
            'name' => 'Отменен',
            'color' => 'red',
            'is_active' => true,
            'sort_order' => 50,
        ]);
    }
}