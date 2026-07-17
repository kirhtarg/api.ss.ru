<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_yandex_market_product_bindings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_yandex_market_product_bindings', 'last_catalog_sync_run_id')) {
                $table->unsignedBigInteger('last_catalog_sync_run_id')->nullable()->after('remote_updated_at');
                $table->index('last_catalog_sync_run_id', 'ym_binding_catalog_run_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_yandex_market_product_bindings', function (Blueprint $table) {
            if (Schema::hasColumn('shop_yandex_market_product_bindings', 'last_catalog_sync_run_id')) {
                $table->dropIndex('ym_binding_catalog_run_idx');
                $table->dropColumn('last_catalog_sync_run_id');
            }
        });
    }
};
