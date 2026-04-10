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
        Schema::table('shop_suppliers', function (Blueprint $table) {
            $table->text('avito_delivery_options')->nullable()->after('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_suppliers', function (Blueprint $table) {
            $table->dropColumn('avito_delivery_options');
        });
    }
};
