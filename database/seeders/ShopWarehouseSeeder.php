<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopWarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouses = [
            [
                'id' => 1,
                'name' => 'Основной склад',
                'description' => 'Главный склад компании',
                'address' => 'г. Москва, ул. Складская, д. 1',
                'phone' => null,
                'email' => null,
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:29:26',
                'updated_at' => '2025-09-02 10:29:26',
            ],
            [
                'id' => 2,
                'name' => 'Склад в СПб',
                'description' => 'Региональный склад в Санкт-Петербурге',
                'address' => 'г. Санкт-Петербург, ул. Складская, д. 2',
                'phone' => null,
                'email' => null,
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:29:26',
                'updated_at' => '2025-09-02 10:29:26',
            ],
            [
                'id' => 3,
                'name' => 'Склад в Екатеринбурге',
                'description' => 'Региональный склад в Екатеринбурге',
                'address' => 'г. Екатеринбург, ул. Складская, д. 3',
                'phone' => null,
                'email' => null,
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:29:26',
                'updated_at' => '2025-09-02 10:29:26',
            ],
        ];

        foreach ($warehouses as $warehouse) {
            DB::table('shop_warehouses')->updateOrInsert(
                ['id' => $warehouse['id']],
                $warehouse
            );
        }
    }
}
