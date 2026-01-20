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
        // Добавляем индексы
        Schema::table('shop_orders', function (Blueprint $table) {
            if (!Schema::hasIndex('shop_orders', 'shop_orders_payment_status_id_index')) {
                $table->index(['payment_status_id']);
            }
            if (!Schema::hasIndex('shop_orders', 'shop_orders_delivery_status_id_index')) {
                $table->index(['delivery_status_id']);
            }
        });

        // Добавляем внешние ключи
        Schema::table('shop_orders', function (Blueprint $table) {
            // Проверяем, что внешние ключи еще не существуют
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'shop_orders' 
                AND CONSTRAINT_NAME LIKE '%payment_status_id%'
            ");
            
            if (empty($foreignKeys)) {
                $table->foreign('payment_status_id')->references('id')->on('shop_payment_statuses')->onDelete('restrict');
            }
            
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'shop_orders' 
                AND CONSTRAINT_NAME LIKE '%delivery_status_id%'
            ");
            
            if (empty($foreignKeys)) {
                $table->foreign('delivery_status_id')->references('id')->on('shop_delivery_statuses')->onDelete('restrict');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropForeign(['payment_status_id']);
            $table->dropForeign(['delivery_status_id']);
            $table->dropIndex(['payment_status_id']);
            $table->dropIndex(['delivery_status_id']);
        });
    }
};
