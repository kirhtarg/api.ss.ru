<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['shop_goods', 'shop_good_variations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'ozon_price')) {
                    $table->decimal('ozon_price', 12, 2)->nullable()->after('avito_price');
                    $table->index('ozon_price', $tableName.'_ozon_price_idx');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['shop_goods', 'shop_good_variations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'ozon_price')) {
                    $table->dropIndex($tableName.'_ozon_price_idx');
                    $table->dropColumn('ozon_price');
                }
            });
        }
    }
};
