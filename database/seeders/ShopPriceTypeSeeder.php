<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopPriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $priceTypes = [
            [
                'id' => 1,
                'name' => 'Розничная цена',
                'description' => 'Стандартная розничная цена',
                'multiplier' => 1.0000,
                'is_active' => 1,
                'is_default' => 1,
                'sort_order' => 1,
                'created_at' => '2025-09-02 10:30:56',
                'updated_at' => '2025-09-02 10:30:56',
            ],
            [
                'id' => 2,
                'name' => 'Оптовая цена',
                'description' => 'Цена для оптовых покупателей',
                'multiplier' => 0.8000,
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 2,
                'created_at' => '2025-09-02 10:30:56',
                'updated_at' => '2025-09-02 10:30:56',
            ],
            [
                'id' => 3,
                'name' => 'VIP цена',
                'description' => 'Специальная цена для VIP клиентов',
                'multiplier' => 0.9000,
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 3,
                'created_at' => '2025-09-02 10:30:56',
                'updated_at' => '2025-09-02 10:30:56',
            ],
        ];

        foreach ($priceTypes as $priceType) {
            DB::table('shop_price_types')->updateOrInsert(
                ['id' => $priceType['id']],
                $priceType
            );
        }
    }
}
