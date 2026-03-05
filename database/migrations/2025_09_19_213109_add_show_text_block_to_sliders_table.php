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
        // Проверяем, существует ли таблица sliders
        if (Schema::hasTable('sliders')) {
            Schema::table('sliders', function (Blueprint $table) {
                // Проверяем, существует ли колонка show_text_block
                if (! Schema::hasColumn('sliders', 'show_text_block')) {
                    $table->boolean('show_text_block')->default(true)->after('text_position');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Проверяем, существует ли таблица sliders
        if (Schema::hasTable('sliders')) {
            Schema::table('sliders', function (Blueprint $table) {
                // Проверяем, существует ли колонка show_text_block
                if (Schema::hasColumn('sliders', 'show_text_block')) {
                    $table->dropColumn('show_text_block');
                }
            });
        }
    }
};
