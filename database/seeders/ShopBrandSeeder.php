<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopBrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [
            [
                'id' => 1,
                'name' => 'Nike',
                'slug' => 'nike',
                'description' => 'Американская компания, специализирующаяся на спортивной одежде и обуви',
                'logo' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 2,
                'name' => 'Adidas',
                'slug' => 'adidas',
                'description' => 'Немецкая компания, производитель спортивной одежды и обуви',
                'logo' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 3,
                'name' => 'Puma',
                'slug' => 'puma',
                'description' => 'Немецкая компания, производитель спортивной одежды и обуви',
                'logo' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 4,
                'name' => 'Vans',
                'slug' => 'vans',
                'description' => 'Американская компания, специализирующаяся на обуви для скейтбординга',
                'logo' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 5,
                'name' => 'Converse',
                'slug' => 'converse',
                'description' => 'Американская компания, производитель обуви',
                'logo' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
        ];

        foreach ($brands as $brand) {
            DB::table('shop_brands')->updateOrInsert(
                ['id' => $brand['id']],
                $brand
            );
        }
    }
}
