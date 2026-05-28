<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_russian_post_settings') || Schema::hasColumn('shop_russian_post_settings', 'cash_on_delivery_enabled')) {
            return;
        }

        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->boolean('cash_on_delivery_enabled')
                ->default(false)
                ->after('default_height')
                ->comment('Разрешена ли оплата при получении для Почты России');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shop_russian_post_settings') || ! Schema::hasColumn('shop_russian_post_settings', 'cash_on_delivery_enabled')) {
            return;
        }

        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->dropColumn('cash_on_delivery_enabled');
        });
    }
};
