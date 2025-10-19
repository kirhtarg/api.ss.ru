<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_templates', function (Blueprint $table) {
            // Добавляем новое поле menu_id для связи с таблицей site_menus
            $table->unsignedBigInteger('menu_id')->nullable()->after('folder_name');
            
            // Создаем внешний ключ только если таблица site_menus существует
            if (Schema::hasTable('site_menus')) {
                $table->foreign('menu_id')->references('id')->on('site_menus')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('site_templates') && Schema::hasColumn('site_templates', 'menu_id')) {
            // Сначала удаляем внешний ключ с правильным именем
            try {
                DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_templates_menu_id_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_template_menu_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
            
            // Затем удаляем колонку
            Schema::table('site_templates', function (Blueprint $table) {
                $table->dropColumn('menu_id');
            });
        }
    }
};
