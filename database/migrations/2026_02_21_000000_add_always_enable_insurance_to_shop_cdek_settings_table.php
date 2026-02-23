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
            $table->boolean('always_enable_insurance')->default(false)->after('disable_order_creation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->dropColumn('always_enable_insurance');
        });
    }
};

