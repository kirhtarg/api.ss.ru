<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteMenuItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $menuItems = [
            [
                'id' => 2,
                'site_menu_id' => 1,
                'title' => 'Велопрокат',
                'url' => '/rentbikespb',
                'parent_id' => null,
                'sort_order' => 1,
                'is_active' => 1,
                'target' => '_self',
                'icon' => 'mdi:velocity',
                'attributes' => null,
                'created_at' => '2025-09-03 09:09:03',
                'updated_at' => '2025-09-15 09:25:34',
            ],
            [
                'id' => 11,
                'site_menu_id' => 1,
                'title' => 'Прокат инвентаря',
                'url' => '/longrent',
                'parent_id' => null,
                'sort_order' => 2,
                'is_active' => 1,
                'target' => '_self',
                'icon' => 'tdesign:tools-circle',
                'attributes' => null,
                'created_at' => '2025-09-03 13:40:15',
                'updated_at' => '2025-09-15 09:27:09',
            ],
            [
                'id' => 13,
                'site_menu_id' => 1,
                'title' => 'Сервис',
                'url' => '/service',
                'parent_id' => null,
                'sort_order' => 3,
                'is_active' => 1,
                'target' => '_self',
                'icon' => 'fa7-solid:tools',
                'attributes' => null,
                'created_at' => '2025-09-15 04:09:00',
                'updated_at' => '2025-09-15 09:28:22',
            ],
            [
                'id' => 14,
                'site_menu_id' => 1,
                'title' => 'Акции',
                'url' => '/aktsii',
                'parent_id' => null,
                'sort_order' => 4,
                'is_active' => 1,
                'target' => '_self',
                'icon' => 'mdi:sale-circle',
                'attributes' => null,
                'created_at' => '2025-09-15 04:09:38',
                'updated_at' => '2025-09-15 09:32:31',
            ],
        ];

        foreach ($menuItems as $item) {
            DB::table('site_menu_items')->updateOrInsert(
                ['id' => $item['id']],
                $item
            );
        }
    }
}












