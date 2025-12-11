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
        // Добавляем поле fast_remote_stock_quantity в таблицу товаров
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->string('fast_remote_stock_quantity')->nullable()->after('remote_stock_quantity');
        });

        // Добавляем поле fast_remote_stock_quantity в таблицу вариаций товаров
        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->string('fast_remote_stock_quantity')->nullable()->after('remote_stock_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем поле fast_remote_stock_quantity из таблицы товаров
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropColumn('fast_remote_stock_quantity');
        });

        // Удаляем поле fast_remote_stock_quantity из таблицы вариаций товаров
        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->dropColumn('fast_remote_stock_quantity');
        });
    }
};
