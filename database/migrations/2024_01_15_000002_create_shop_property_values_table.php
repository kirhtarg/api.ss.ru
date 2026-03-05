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
        Schema::create('shop_property_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')
                ->constrained('shop_properties')
                ->onDelete('cascade')
                ->comment('ID свойства');
            $table->string('value')->comment('Значение для выбора');
            $table->string('color', 7)->nullable()->comment('HEX код цвета (для цветовых свойств)');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            $table->boolean('is_active')->default(true)->comment('Активно ли значение');
            $table->timestamps();

            $table->index(['property_id', 'sort_order']);
            $table->index(['property_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_property_values');
    }
};
