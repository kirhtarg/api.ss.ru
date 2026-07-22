<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_product_bindings', function (Blueprint $table) {
            $table->json('synced_attributes')->nullable()->after('payload_hash');
            $table->json('synced_variation_attributes')->nullable()->after('synced_attributes');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_product_bindings', function (Blueprint $table) {
            $table->dropColumn(['synced_attributes', 'synced_variation_attributes']);
        });
    }
};
