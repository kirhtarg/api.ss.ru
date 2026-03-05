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
        // Проверяем, существует ли таблица shop_properties
        if (Schema::hasTable('shop_properties')) {
            // Проверяем, существует ли уже поле property_type
            if (! Schema::hasColumn('shop_properties', 'property_type')) {
                Schema::table('shop_properties', function (Blueprint $table) {
                    $table->enum('property_type', ['string', 'color', 'select'])
                        ->default('string')
                        ->after('name')
                        ->comment('Тип свойства: string - строка, color - цвет, select - выбор из списка');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_properties')) {
            if (Schema::hasColumn('shop_properties', 'property_type')) {
                Schema::table('shop_properties', function (Blueprint $table) {
                    $table->dropColumn('property_type');
                });
            }
        }
    }
};
