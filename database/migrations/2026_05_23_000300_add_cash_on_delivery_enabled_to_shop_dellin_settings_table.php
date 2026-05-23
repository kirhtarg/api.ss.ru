<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_dellin_settings', 'cash_on_delivery_enabled')) {
                $table->boolean('cash_on_delivery_enabled')->default(false)->after('default_height');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            if (Schema::hasColumn('shop_dellin_settings', 'cash_on_delivery_enabled')) {
                $table->dropColumn('cash_on_delivery_enabled');
            }
        });
    }
};
