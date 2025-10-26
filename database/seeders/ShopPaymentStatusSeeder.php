<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopPaymentStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'id' => 1,
                'name' => 'pending',
                'display_name' => 'Ожидает оплаты',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 10,
                'description' => 'Заказ ожидает оплаты',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'paid',
                'display_name' => 'Оплачен',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 20,
                'description' => 'Заказ оплачен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('shop_payment_statuses')->updateOrInsert(
                ['id' => $status['id']],
                $status
            );
        }
    }
}
