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
                    // Просто удаляем колонку - MySQL автоматически удалит внешний ключ
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
                    // Создаем внешний ключ только если таблица существует
                    if (Schema::hasTable('shop_good_variations')) {
                        $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');
                    }
                }
            });
        }
    }
};


