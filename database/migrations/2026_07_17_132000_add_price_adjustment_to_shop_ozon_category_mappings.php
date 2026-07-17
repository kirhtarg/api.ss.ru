<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->json('price_adjustment')->nullable()->after('dimension_settings');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->dropColumn('price_adjustment');
        });
    }
};
