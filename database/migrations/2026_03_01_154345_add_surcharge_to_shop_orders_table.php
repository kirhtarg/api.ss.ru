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
            $table->boolean('surcharge_enabled')->default(false);
            $table->decimal('surcharge_value', 10, 2)->nullable();
            $table->string('surcharge_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn(['surcharge_enabled', 'surcharge_value', 'surcharge_type']);
        });
    }
};