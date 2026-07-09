<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->string('stock_source')->nullable()->after('fast_remote_stock_quantity')->index();
            $table->string('last_stock_import_run_id')->nullable()->after('stock_source')->index();
            $table->timestamp('last_stock_import_at')->nullable()->after('last_stock_import_run_id');
        });

        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->string('stock_source')->nullable()->after('fast_remote_stock_quantity')->index();
            $table->string('last_stock_import_run_id')->nullable()->after('stock_source')->index();
            $table->timestamp('last_stock_import_at')->nullable()->after('last_stock_import_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->dropIndex(['stock_source']);
            $table->dropIndex(['last_stock_import_run_id']);
            $table->dropColumn(['stock_source', 'last_stock_import_run_id', 'last_stock_import_at']);
        });

        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropIndex(['stock_source']);
            $table->dropIndex(['last_stock_import_run_id']);
            $table->dropColumn(['stock_source', 'last_stock_import_run_id', 'last_stock_import_at']);
        });
    }
};
