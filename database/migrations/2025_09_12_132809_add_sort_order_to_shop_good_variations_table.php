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
        if (Schema::hasTable('shop_good_variations')) {
            // Проверяем, существует ли уже поле sort_order
            if (! Schema::hasColumn('shop_good_variations', 'sort_order')) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->integer('sort_order')->default(0)->after('is_active');
                });
            }

            // Проверяем, существует ли уже индекс
            if (! Schema::hasIndex('shop_good_variations', ['good_id', 'sort_order'])) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->index(['good_id', 'sort_order']);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_good_variations')) {
            // Удаляем индекс, если он существует
            if (Schema::hasIndex('shop_good_variations', ['good_id', 'sort_order'])) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->dropIndex(['good_id', 'sort_order']);
                });
            }

            // Удаляем колонку, если она существует
            if (Schema::hasColumn('shop_good_variations', 'sort_order')) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->dropColumn('sort_order');
                });
            }
        }
    }
};
