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
            // Удаляем старый уникальный индекс
            $table->dropUnique(['good_id', 'property_id']);
            
            // Добавляем новый уникальный индекс с учетом variation_id
            $table->unique(['good_id', 'variation_id', 'property_id'], 'shop_good_properties_good_variation_property_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_properties', function (Blueprint $table) {
            // Удаляем новый уникальный индекс
            $table->dropUnique('shop_good_properties_good_variation_property_unique');
            
            // Восстанавливаем старый уникальный индекс
            $table->unique(['good_id', 'property_id']);
        });
    }
};
