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
        // Добавляем поля с значениями по умолчанию
        if (!Schema::hasColumn('shop_orders', 'payment_status_id')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_status_id')->default(1)->after('status_id');
            });
        }
        
        if (!Schema::hasColumn('shop_orders', 'delivery_status_id')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_status_id')->default(1)->after('payment_status_id');
            });
        }
        
        // Обновляем существующие записи значениями по умолчанию
        DB::table('shop_orders')->whereNull('payment_status_id')->update(['payment_status_id' => 1]);
        DB::table('shop_orders')->whereNull('delivery_status_id')->update(['delivery_status_id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status_id', 'delivery_status_id']);
        });
    }
};
