<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->json('dimension_settings')->nullable()->after('variation_attribute_mappings');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->dropColumn('dimension_settings');
        });
    }
};
