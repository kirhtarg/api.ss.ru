<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_good_variations', 'demping_price')) {
                $table->decimal('demping_price', 10, 2)->nullable()->after('sale_price');
            }
            if (!Schema::hasColumn('shop_good_variations', 'show_demping')) {
                $table->boolean('show_demping')->default(false)->after('demping_price');
            }
            
            // Индексы для производительности
            if (!Schema::hasIndex('shop_good_variations', 'shop_good_variations_demping_price_idx')) {
                $table->index('demping_price', 'shop_good_variations_demping_price_idx');
            }
            if (!Schema::hasIndex('shop_good_variations', 'shop_good_variations_show_demping_idx')) {
                $table->index('show_demping', 'shop_good_variations_show_demping_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            if (Schema::hasIndex('shop_good_variations', 'shop_good_variations_show_demping_idx')) {
                $table->dropIndex('shop_good_variations_show_demping_idx');
            }
            if (Schema::hasIndex('shop_good_variations', 'shop_good_variations_demping_price_idx')) {
                $table->dropIndex('shop_good_variations_demping_price_idx');
            }
            if (Schema::hasColumn('shop_good_variations', 'show_demping')) {
                $table->dropColumn('show_demping');
            }
            if (Schema::hasColumn('shop_good_variations', 'demping_price')) {
                $table->dropColumn('demping_price');
            }
        });
    }
};
