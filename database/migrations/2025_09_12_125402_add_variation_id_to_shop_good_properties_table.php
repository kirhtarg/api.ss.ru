<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверяем, существуют ли необходимые таблицы
        if (Schema::hasTable('shop_good_properties') && Schema::hasTable('shop_good_variations')) {
            Schema::table('shop_good_properties', function (Blueprint $table) {
                // Проверяем, существует ли колонка variation_id
                if (!Schema::hasColumn('shop_good_properties', 'variation_id')) {
                    $table->unsignedBigInteger('variation_id')->nullable()->after('good_id');
                    $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');
                    $table->index(['variation_id', 'property_id']);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Проверяем, существуют ли необходимые таблицы
        if (Schema::hasTable('shop_good_properties')) {
            Schema::table('shop_good_properties', function (Blueprint $table) {
                // Проверяем, существует ли колонка variation_id
                if (Schema::hasColumn('shop_good_properties', 'variation_id')) {
                    // Сначала удаляем внешний ключ
                    try {
                        DB::statement('ALTER TABLE shop_good_properties DROP FOREIGN KEY shop_good_properties_variation_id_foreign');
                    } catch (\Exception $e) {
                        // Пробуем альтернативные имена
                        try {
                            DB::statement('ALTER TABLE shop_good_properties DROP FOREIGN KEY shop_good_property_variation_id_foreign');
                        } catch (\Exception $e2) {
                            // Игнорируем ошибку, если внешний ключ не существует
                        }
                    }
                    // Затем удаляем колонку
                    $table->dropColumn('variation_id');
                }
            });
        }
    }
};
