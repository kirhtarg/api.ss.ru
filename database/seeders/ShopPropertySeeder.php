<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopPropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $properties = [
            [
                'id' => 1,
                'name' => 'Материал',
                'is_filterable' => 1,
                'sort_order' => 1,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
            [
                'id' => 2,
                'name' => 'Цвет',
                'is_filterable' => 1,
                'sort_order' => 2,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
            [
                'id' => 3,
                'name' => 'Размер',
                'is_filterable' => 1,
                'sort_order' => 3,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
            [
                'id' => 4,
                'name' => 'Вес',
                'is_filterable' => 0,
                'sort_order' => 4,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
            [
                'id' => 5,
                'name' => 'Страна производитель',
                'is_filterable' => 1,
                'sort_order' => 5,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
            [
                'id' => 6,
                'name' => 'Гарантия',
                'is_filterable' => 0,
                'sort_order' => 6,
                'created_at' => '2025-09-02 10:27:59',
                'updated_at' => '2025-09-02 10:27:59',
            ],
        ];

        foreach ($properties as $property) {
            DB::table('shop_properties')->updateOrInsert(
                ['id' => $property['id']],
                $property
            );
        }
    }
}
