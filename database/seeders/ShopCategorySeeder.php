<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'id' => 1,
                'name' => 'Аксессуары для телефонов',
                'description' => 'Аксессуары для телефонов',
                'image' => null,
                'icon' => 'mdi:mobile-phone-key',
                'slug' => 'aksessuary-dlya-telefonov',
                'is_active' => 1,
                'sort_order' => 1,
                'parent_id' => null,
                'created_at' => '2025-09-02 08:25:09',
                'updated_at' => '2025-09-02 12:00:01',
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'name' => 'Батуты',
                'description' => 'Батуты',
                'image' => null,
                'icon' => 'hugeicons:trampoline',
                'slug' => 'batuty',
                'is_active' => 1,
                'sort_order' => 0,
                'parent_id' => null,
                'created_at' => '2025-09-02 08:27:32',
                'updated_at' => '2025-09-02 12:00:01',
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'name' => 'Велоаксессуары',
                'description' => 'Велоаксессуары',
                'image' => null,
                'icon' => 'game-icons:velocipede',
                'slug' => 'veloaksessuary',
                'is_active' => 1,
                'sort_order' => 2,
                'parent_id' => null,
                'created_at' => '2025-09-02 08:29:00',
                'updated_at' => '2025-09-02 12:00:01',
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'name' => 'Велозапчасти',
                'description' => 'Велозапчасти',
                'image' => null,
                'icon' => 'file-icons:velocity',
                'slug' => 'velozapchasti',
                'is_active' => 1,
                'sort_order' => 4,
                'parent_id' => null,
                'created_at' => '2025-09-02 08:30:59',
                'updated_at' => '2025-09-02 12:00:01',
                'deleted_at' => null,
            ],
            [
                'id' => 5,
                'name' => 'Велосипеды',
                'description' => 'Велосипеды',
                'image' => null,
                'icon' => 'mdi:velocity',
                'slug' => 'velosipedyi',
                'is_active' => 1,
                'sort_order' => 3,
                'parent_id' => null,
                'created_at' => '2025-09-02 08:32:54',
                'updated_at' => '2025-09-02 12:00:01',
                'deleted_at' => null,
            ],
        ];

        foreach ($categories as $category) {
            DB::table('shop_categories')->updateOrInsert(
                ['id' => $category['id']],
                $category
            );
        }
    }
}
