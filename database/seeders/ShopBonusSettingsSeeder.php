<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopBonusSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'id' => 1,
                'name' => 'Основная бонусная система',
                'regular_price_percentage' => 5.00,
                'sale_price_percentage' => 2.50,
                'max_usage_percentage' => 50.00,
                'is_active' => true,
                'min_order_amount' => 0,
                'min_bonus_amount' => 1,
                'max_bonus_amount' => null,
                'bonus_expiry_days' => 365,
                'metadata' => json_encode([
                    'description' => 'Стандартная система начисления и списания бонусов',
                    'created_by' => 'system'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Премиум бонусная система',
                'regular_price_percentage' => 7.50,
                'sale_price_percentage' => 4.00,
                'max_usage_percentage' => 70.00,
                'is_active' => false,
                'min_order_amount' => 1000,
                'min_bonus_amount' => 10,
                'max_bonus_amount' => 5000,
                'bonus_expiry_days' => 730,
                'metadata' => json_encode([
                    'description' => 'Премиум система с увеличенными процентами',
                    'created_by' => 'system'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($settings as $setting) {
            DB::table('shop_bonus_settings')->updateOrInsert(
                ['id' => $setting['id']],
                $setting
            );
        }
    }
}
