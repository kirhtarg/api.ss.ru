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
        Schema::table('shop_properties', function (Blueprint $table) {
            // Добавляем property_type
            if (! Schema::hasColumn('shop_properties', 'property_type')) {
                $table->enum('property_type', ['string', 'color', 'select'])
                    ->default('string')
                    ->after('name')
                    ->comment('Тип свойства: string - строка, color - цвет, select - выбор из списка');
            }

            // Добавляем description
            if (! Schema::hasColumn('shop_properties', 'description')) {
                $table->text('description')->nullable()->after('property_type')
                    ->comment('Описание свойства товара');
            }

            // Добавляем is_active
            if (! Schema::hasColumn('shop_properties', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('description')
                    ->comment('Активность свойства');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_properties', function (Blueprint $table) {
            // Удаляем добавленные колонки в обратном порядке
            if (Schema::hasColumn('shop_properties', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('shop_properties', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('shop_properties', 'property_type')) {
                $table->dropColumn('property_type');
            }
        });
    }
};
