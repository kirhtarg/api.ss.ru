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
            $table->boolean('cash_on_delivery_enabled')->default(false)->after('tariffs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->dropColumn('cash_on_delivery_enabled');
        });
    }
};
