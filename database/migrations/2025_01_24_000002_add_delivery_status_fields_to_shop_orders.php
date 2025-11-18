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
            if (!Schema::hasColumn('shop_orders', 'cdek_order_uuid')) {
                $table->string('cdek_order_uuid', 255)->nullable()->after('shipping_address')->comment('UUID заказа в СДЭК');
            }
            if (!Schema::hasColumn('shop_orders', 'delivery_status')) {
                $table->text('delivery_status')->nullable()->after('cdek_order_uuid')->comment('Статус доставки (JSON или текст)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'delivery_status')) {
                $table->dropColumn('delivery_status');
            }
            if (Schema::hasColumn('shop_orders', 'cdek_order_uuid')) {
                $table->dropColumn('cdek_order_uuid');
            }
        });
    }
};

