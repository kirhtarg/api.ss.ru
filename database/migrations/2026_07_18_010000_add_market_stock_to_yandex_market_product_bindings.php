<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_yandex_market_product_bindings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_yandex_market_product_bindings', 'market_stock')) {
                $table->integer('market_stock')->nullable()->after('content_rating');
            }
            if (! Schema::hasColumn('shop_yandex_market_product_bindings', 'market_stock_details')) {
                $table->json('market_stock_details')->nullable()->after('market_stock');
            }
            if (! Schema::hasColumn('shop_yandex_market_product_bindings', 'market_stock_updated_at')) {
                $table->timestamp('market_stock_updated_at')->nullable()->after('market_stock_details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_yandex_market_product_bindings', function (Blueprint $table) {
            $columns = [];
            foreach (['market_stock_updated_at', 'market_stock_details', 'market_stock'] as $column) {
                if (Schema::hasColumn('shop_yandex_market_product_bindings', $column)) $columns[] = $column;
            }
            if ($columns) $table->dropColumn($columns);
        });
    }
};
