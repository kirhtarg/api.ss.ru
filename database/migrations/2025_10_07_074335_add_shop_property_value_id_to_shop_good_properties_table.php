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
        Schema::table('shop_good_properties', function (Blueprint $table) {
            // Добавляем поле для связи с shop_property_values
            $table->unsignedBigInteger('shop_property_value_id')->nullable()->after('property_id');
            
            // Добавляем внешний ключ
            $table->foreign('shop_property_value_id')
                  ->references('id')
                  ->on('shop_property_values')
                  ->onDelete('set null');
            
            // Добавляем индекс для производительности
            $table->index('shop_property_value_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_good_properties') && Schema::hasColumn('shop_good_properties', 'shop_property_value_id')) {
            // Сначала удаляем внешний ключ с правильным именем
            try {
                DB::statement('ALTER TABLE shop_good_properties DROP FOREIGN KEY shop_good_properties_shop_property_value_id_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE shop_good_properties DROP FOREIGN KEY shop_good_property_shop_property_value_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
            
            // Затем удаляем колонку
            Schema::table('shop_good_properties', function (Blueprint $table) {
                $table->dropColumn('shop_property_value_id');
            });
        }
    }
};
