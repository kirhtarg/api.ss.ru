<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->decimal('avito_price', 12, 2)->nullable()->after('demping_price');
        });

        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->decimal('avito_price', 12, 2)->nullable()->after('demping_price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->dropColumn('avito_price');
        });

        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropColumn('avito_price');
        });
    }
};
