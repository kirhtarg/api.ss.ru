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
            // Проверяем, существует ли колонка, и добавляем только если её нет
            if (!Schema::hasColumn('sliders', 'transition_duration')) {
                $table->integer('transition_duration')->default(1000)->after('auto_interval');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            // Проверяем, существует ли колонка, и удаляем только если она есть
            if (Schema::hasColumn('sliders', 'transition_duration')) {
                $table->dropColumn('transition_duration');
            }
        });
    }
};
