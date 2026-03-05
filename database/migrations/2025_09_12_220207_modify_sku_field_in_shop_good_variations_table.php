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
            // Проверяем, существует ли уникальный индекс на поле sku
            if (Schema::hasIndex('shop_good_variations', 'shop_good_variations_sku_unique')) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->dropUnique('shop_good_variations_sku_unique');
                });
            }

            // Делаем поле sku nullable
            Schema::table('shop_good_variations', function (Blueprint $table) {
                $table->string('sku')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_good_variations')) {
            Schema::table('shop_good_variations', function (Blueprint $table) {
                // Возвращаем поле sku как NOT NULL
                $table->string('sku')->nullable(false)->change();
            });

            // Возвращаем уникальный индекс для поля sku, если его нет
            if (! Schema::hasIndex('shop_good_variations', 'shop_good_variations_sku_unique')) {
                Schema::table('shop_good_variations', function (Blueprint $table) {
                    $table->unique('sku', 'shop_good_variations_sku_unique');
                });
            }
        }
    }
};
