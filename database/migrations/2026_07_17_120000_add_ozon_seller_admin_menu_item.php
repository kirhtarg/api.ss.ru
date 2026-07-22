<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $shopPageId = DB::table('admin_pages')->where('slug', 'shop')->value('id');

        if (! $shopPageId) {
            return;
        }

        DB::table('admin_menu_items')->updateOrInsert(
            ['href' => 'ozon_seller', 'page_id' => $shopPageId],
            [
                'label' => 'Ozon Seller',
                'icon' => 'mdi:shopping-outline',
                'description' => 'Карточки, категории, цены и остатки Ozon Seller',
                'order' => 101,
                'is_active' => 1,
                'in_menu' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('admin_menu_items')->where('href', 'ozon_seller')->delete();
    }
};
