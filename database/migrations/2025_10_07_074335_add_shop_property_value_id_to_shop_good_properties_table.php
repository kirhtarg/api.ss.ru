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
        Schema::table('shop_good_properties', function (Blueprint $table) {
            // Удаляем внешний ключ и индекс
            $table->dropForeign(['shop_property_value_id']);
            $table->dropIndex(['shop_property_value_id']);
            
            // Удаляем колонку
            $table->dropColumn('shop_property_value_id');
        });
    }
};
