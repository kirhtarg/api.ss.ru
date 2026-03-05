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
            // Добавляем stock_quantity, если её нет
            if (! Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                $table->integer('stock_quantity')->default(0)->after('sale_price')
                    ->comment('Количество на складе');
            }

            // Добавляем description, если её нет
            if (! Schema::hasColumn('shop_good_variations', 'description')) {
                $table->text('description')->nullable()->after('name')
                    ->comment('Описание вариации');
            }

            // Добавляем short_description, если её нет
            if (! Schema::hasColumn('shop_good_variations', 'short_description')) {
                $table->text('short_description')->nullable()->after('description')
                    ->comment('Краткое описание вариации');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            // Удаляем добавленные колонки
            if (Schema::hasColumn('shop_good_variations', 'short_description')) {
                $table->dropColumn('short_description');
            }
            if (Schema::hasColumn('shop_good_variations', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                $table->dropColumn('stock_quantity');
            }
        });
    }
};
