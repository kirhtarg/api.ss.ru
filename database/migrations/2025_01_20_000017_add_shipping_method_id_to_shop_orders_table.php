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
        Schema::table('shop_orders', function (Blueprint $table) {
            // Добавляем поле shipping_method_id после shipping_method
            if (! Schema::hasColumn('shop_orders', 'shipping_method_id')) {
                $table->unsignedBigInteger('shipping_method_id')->nullable()->after('shipping_method');
                $table->foreign('shipping_method_id')->references('id')->on('shop_delivery_methods')->onDelete('set null');
                $table->index('shipping_method_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'shipping_method_id')) {
                $table->dropForeign(['shipping_method_id']);
                $table->dropIndex(['shipping_method_id']);
                $table->dropColumn('shipping_method_id');
            }
        });
    }
};
