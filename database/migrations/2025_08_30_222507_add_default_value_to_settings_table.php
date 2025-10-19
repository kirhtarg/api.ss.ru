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
        // Проверяем, существует ли таблица settings
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                // Проверяем, существует ли колонка default_value
                if (!Schema::hasColumn('settings', 'default_value')) {
                    $table->text('default_value')->nullable()->after('value')->comment('Значение по умолчанию для настройки');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Проверяем, существует ли таблица settings
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                // Проверяем, существует ли колонка default_value
                if (Schema::hasColumn('settings', 'default_value')) {
                    $table->dropColumn('default_value');
                }
            });
        }
    }
};

