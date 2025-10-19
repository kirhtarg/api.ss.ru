<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_good_properties')) {
            Schema::table('shop_good_properties', function (Blueprint $table) {
                if (Schema::hasColumn('shop_good_properties', 'variation_id')) {
                    // Сначала удаляем внешний ключ, если он существует
                    try {
                        $table->dropForeign(['variation_id']);
                    } catch (\Exception $e) {
                        // Игнорируем ошибку, если внешний ключ не существует
                    }
                    // Затем удаляем колонку
                    $table->dropColumn('variation_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shop_good_properties') && Schema::hasTable('shop_good_variations')) {
            Schema::table('shop_good_properties', function (Blueprint $table) {
                if (!Schema::hasColumn('shop_good_properties', 'variation_id')) {
                    $table->unsignedBigInteger('variation_id')->nullable()->index();
                    // Восстанавливаем внешний ключ только если таблица shop_good_variations существует
                    try {
                        $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');
                    } catch (\Exception $e) {
                        // Игнорируем ошибку, если не удается создать внешний ключ
                    }
                }
            });
        }
    }
};


