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
        Schema::table('shop_good_properties', function (Blueprint $table) {
            $table->unsignedBigInteger('variation_id')->nullable()->after('good_id');
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');
            $table->index(['variation_id', 'property_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_properties', function (Blueprint $table) {
            $table->dropForeign(['variation_id']);
            $table->dropIndex(['variation_id', 'property_id']);
            $table->dropColumn('variation_id');
        });
    }
};
