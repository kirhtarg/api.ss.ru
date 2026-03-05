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
            // Удаляем старый уникальный индекс
            if (Schema::hasIndex('shop_good_properties', 'shop_good_properties_good_variation_property_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->dropUnique('shop_good_properties_good_variation_property_unique');
                });
            }

            // Добавляем новый уникальный индекс, включающий shop_property_value_id
            // Это позволит иметь несколько значений одной характеристики у товара
            Schema::table('shop_good_properties', function (Blueprint $table) {
                $table->unique(['good_id', 'property_id', 'shop_property_value_id'], 'shop_good_properties_full_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_good_properties')) {
            // Удаляем новый уникальный индекс
            if (Schema::hasIndex('shop_good_properties', 'shop_good_properties_full_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->dropUnique('shop_good_properties_full_unique');
                });
            }

            // Восстанавливаем старый уникальный индекс
            if (! Schema::hasIndex('shop_good_properties', 'shop_good_props_unique')) {
                Schema::table('shop_good_properties', function (Blueprint $table) {
                    $table->unique(['good_id', 'property_id'], 'shop_good_props_unique');
                });
            }
        }
    }
};
