<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['shop_yandex_market_product_bindings', 'shop_yandex_market_sync_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['good_id']);
                $blueprint->unsignedBigInteger('good_id')->nullable()->change();
                $blueprint->foreign('good_id')->references('id')->on('shop_goods')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['shop_yandex_market_product_bindings', 'shop_yandex_market_sync_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['good_id']);
                $blueprint->unsignedBigInteger('good_id')->nullable(false)->change();
                $blueprint->foreign('good_id')->references('id')->on('shop_goods')->cascadeOnDelete();
            });
        }
    }
};
