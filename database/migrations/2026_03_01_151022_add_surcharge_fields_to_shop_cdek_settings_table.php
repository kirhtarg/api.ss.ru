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
            $table->boolean('surcharge_enabled')->default(false)->after('cash_on_delivery_enabled');
            $table->decimal('surcharge_value', 8, 2)->nullable()->after('surcharge_enabled');
            $table->string('surcharge_type')->nullable()->after('surcharge_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->dropColumn(['surcharge_enabled', 'surcharge_value', 'surcharge_type']);
        });
    }
};
