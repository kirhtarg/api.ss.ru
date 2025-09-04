<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = [
            [
                'id' => 1,
                'name' => 'Новинка',
                'slug' => 'novinka',
                'color' => '#10B981',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 2,
                'name' => 'Хит продаж',
                'slug' => 'hit-prodazh',
                'color' => '#EF4444',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 3,
                'name' => 'Скидка',
                'slug' => 'skidka',
                'color' => '#F59E0B',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 4,
                'name' => 'Ограниченная серия',
                'slug' => 'ogranichennaya-seriya',
                'color' => '#8B5CF6',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
            [
                'id' => 5,
                'name' => 'Эксклюзив',
                'slug' => 'eksklyuziv',
                'color' => '#EC4899',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2025-09-02 10:24:25',
                'updated_at' => '2025-09-02 10:24:25',
            ],
        ];

        foreach ($tags as $tag) {
            DB::table('shop_tags')->updateOrInsert(
                ['id' => $tag['id']],
                $tag
            );
        }
    }
}
