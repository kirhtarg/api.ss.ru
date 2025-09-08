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
        Schema::table('shop_stocks', function (Blueprint $table) {
            // Удаляем поле warehouse_id при откате
            if (Schema::hasColumn('shop_stocks', 'warehouse_id')) {
                $table->dropForeign(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            }
        });
    }
};
