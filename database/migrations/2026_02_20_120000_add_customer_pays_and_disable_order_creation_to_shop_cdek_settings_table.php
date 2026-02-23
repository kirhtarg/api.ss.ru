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
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->boolean('customer_pays_delivery')->default(false)->after('cash_on_delivery_enabled');
            $table->boolean('disable_order_creation')->default(false)->after('customer_pays_delivery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->dropColumn('customer_pays_delivery');
            $table->dropColumn('disable_order_creation');
        });
    }
};
