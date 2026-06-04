<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_dellin_settings') && ! Schema::hasColumn('shop_dellin_settings', 'create_order_in_account')) {
            Schema::table('shop_dellin_settings', function (Blueprint $table) {
                $table->boolean('create_order_in_account')->default(false)->after('cash_on_delivery_enabled');
            });
        }

        if (Schema::hasTable('shop_russian_post_settings') && ! Schema::hasColumn('shop_russian_post_settings', 'create_order_in_account')) {
            Schema::table('shop_russian_post_settings', function (Blueprint $table) {
                $table->boolean('create_order_in_account')->default(false)->after('cash_on_delivery_enabled');
            });
        }

        if (Schema::hasTable('shop_orders')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('shop_orders', 'dellin_order_id')) {
                    $table->string('dellin_order_id')->nullable()->after('cdek_order_uuid');
                }

                if (! Schema::hasColumn('shop_orders', 'russianpost_order_id')) {
                    $table->string('russianpost_order_id')->nullable()->after('dellin_order_id');
                }

                if (! Schema::hasColumn('shop_orders', 'russianpost_barcode')) {
                    $table->string('russianpost_barcode')->nullable()->after('russianpost_order_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shop_orders')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                foreach (['russianpost_barcode', 'russianpost_order_id', 'dellin_order_id'] as $column) {
                    if (Schema::hasColumn('shop_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('shop_russian_post_settings') && Schema::hasColumn('shop_russian_post_settings', 'create_order_in_account')) {
            Schema::table('shop_russian_post_settings', function (Blueprint $table) {
                $table->dropColumn('create_order_in_account');
            });
        }

        if (Schema::hasTable('shop_dellin_settings') && Schema::hasColumn('shop_dellin_settings', 'create_order_in_account')) {
            Schema::table('shop_dellin_settings', function (Blueprint $table) {
                $table->dropColumn('create_order_in_account');
            });
        }
    }
};
