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
        Schema::table('sliders', function (Blueprint $table) {
            // Изменяем ENUM для transition_type, добавляя новые значения
            $table->enum('transition_type', ['fade', 'slide', 'slide_left', 'slide_right', 'zoom'])
                ->default('fade')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            // Возвращаем к исходным значениям
            $table->enum('transition_type', ['fade', 'slide', 'zoom'])
                ->default('fade')
                ->change();
        });
    }
};
