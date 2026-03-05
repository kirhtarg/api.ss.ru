<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('shop_templates')) {
            // Добавляем поле is_active_card, если его нет
            if (! Schema::hasColumn('shop_templates', 'is_active_card')) {
                Schema::table('shop_templates', function (Blueprint $table) {
                    $table->boolean('is_active_card')->default(true)->after('is_active')
                        ->comment('Активность для карточек товаров');
                });
            }

            // Добавляем поле is_active_page, если его нет
            if (! Schema::hasColumn('shop_templates', 'is_active_page')) {
                Schema::table('shop_templates', function (Blueprint $table) {
                    $table->boolean('is_active_page')->default(true)->after('is_active_card')
                        ->comment('Активность для страниц товаров');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_templates')) {
            // Удаляем поле is_active_page, если оно существует
            if (Schema::hasColumn('shop_templates', 'is_active_page')) {
                Schema::table('shop_templates', function (Blueprint $table) {
                    $table->dropColumn('is_active_page');
                });
            }

            // Удаляем поле is_active_card, если оно существует
            if (Schema::hasColumn('shop_templates', 'is_active_card')) {
                Schema::table('shop_templates', function (Blueprint $table) {
                    $table->dropColumn('is_active_card');
                });
            }
        }
    }
};
