<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pageId = DB::table('admin_pages')->where('slug', 'shop')->value('id');
        if (! $pageId) {
            return;
        }

        if (! DB::table('admin_menu_items')->where('href', 'yandex_market_seller')->exists()) {
            $maxSort = (int) DB::table('admin_menu_items')->where('page_id', $pageId)->max('order');
            DB::table('admin_menu_items')->insert([
                'page_id' => $pageId,
                'label' => 'Яндекс Маркет Seller',
                'href' => 'yandex_market_seller',
                'description' => 'Карточки, категории, цены и остатки Яндекс Маркета',
                'icon' => 'lucide:shopping-bag',
                'order' => $maxSort + 1,
                'is_active' => true,
                'in_menu' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_menu_items')->where('href', 'yandex_market_seller')->delete();
    }
};
