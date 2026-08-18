<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_feed_price_stock_runs', function (Blueprint $table) {
            $table->unsignedInteger('offers_without_sku')->default(0)->after('offers_total');
        });
        Schema::table('supplier_feed_price_stock_changes', function (Blueprint $table) {
            $table->string('status', 24)->default('different')->after('field');
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_feed_price_stock_changes', function (Blueprint $table) {
            $table->dropIndex(['run_id', 'status']);
            $table->dropColumn('status');
        });
        Schema::table('supplier_feed_price_stock_runs', function (Blueprint $table) {
            $table->dropColumn('offers_without_sku');
        });
    }
};
