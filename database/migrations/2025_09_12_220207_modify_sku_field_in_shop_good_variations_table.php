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
        Schema::table('shop_good_variations', function (Blueprint $table) {
            // Убираем уникальный индекс с поля sku
            $table->dropUnique(['sku']);
            
            // Делаем поле sku nullable
            $table->string('sku')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            // Возвращаем поле sku как NOT NULL
            $table->string('sku')->nullable(false)->change();
            
            // Возвращаем уникальный индекс для поля sku
            $table->unique('sku');
        });
    }
};
