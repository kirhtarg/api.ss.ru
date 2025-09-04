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
        // Проверяем, что таблица site_menus существует
        if (Schema::hasTable('site_menus')) {
            Schema::table('site_menus', function (Blueprint $table) {
                // Добавляем поля только если они не существуют
                if (!Schema::hasColumn('site_menus', 'template_name')) {
                    $table->string('template_name')->nullable()->comment('Название файла шаблона (без .vue)');
                }
                if (!Schema::hasColumn('site_menus', 'settings')) {
                    $table->json('settings')->nullable()->comment('Настройки шаблона в JSON');
                }
                if (!Schema::hasColumn('site_menus', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_menus', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'settings', 'sort_order']);
        });
    }
};
