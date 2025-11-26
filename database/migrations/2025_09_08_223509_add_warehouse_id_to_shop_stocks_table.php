<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_stocks', function (Blueprint $table) {
            // Добавляем поле warehouse_id если его нет
            if (!Schema::hasColumn('shop_stocks', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('variation_id')->constrained('shop_warehouses')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_stocks') && Schema::hasColumn('shop_stocks', 'warehouse_id')) {
            // Сначала удаляем внешний ключ с правильным именем
            try {
                DB::statement('ALTER TABLE shop_stocks DROP FOREIGN KEY shop_stock_warehouse_id_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативное имя
                try {
                    DB::statement('ALTER TABLE shop_stocks DROP FOREIGN KEY shop_stocks_warehouse_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
            
            // Затем удаляем колонку
            Schema::table('shop_stocks', function (Blueprint $table) {
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
