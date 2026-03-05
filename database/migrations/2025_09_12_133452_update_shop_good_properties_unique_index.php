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
        if (Schema::hasTable('shop_good_properties')) {
            // Проверяем и удаляем старый уникальный индекс, если он существует
            if (Schema::hasIndex('shop_good_properties', 'shop_good_props_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->dropUnique('shop_good_props_unique');
                });
            }

            // Проверяем, существует ли уже новый индекс
            if (! Schema::hasIndex('shop_good_properties', 'shop_good_properties_good_variation_property_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    // Добавляем новый уникальный индекс с учетом variation_id
                    $table->unique(['good_id', 'variation_id', 'property_id'], 'shop_good_properties_good_variation_property_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_good_properties')) {
            // Удаляем новый уникальный индекс, если он существует
            if (Schema::hasIndex('shop_good_properties', 'shop_good_properties_good_variation_property_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->dropUnique('shop_good_properties_good_variation_property_unique');
                });
            }

            // Восстанавливаем старый уникальный индекс, если его нет
            if (! Schema::hasIndex('shop_good_properties', 'shop_good_props_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->unique(['good_id', 'property_id'], 'shop_good_props_unique');
                });
            }
        }
    }
};
