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
        // Проверяем, что таблица site_templates существует
        if (Schema::hasTable('site_templates')) {
            Schema::table('site_templates', function (Blueprint $table) {
                // Сначала удаляем внешние ключи, если они существуют
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_NAME = 'site_templates' 
                    AND CONSTRAINT_NAME LIKE '%_foreign'
                ");
                
                $existingForeignKeys = collect($foreignKeys)->pluck('CONSTRAINT_NAME')->toArray();
                
                if (in_array('site_templates_menu_template_id_foreign', $existingForeignKeys)) {
                    $table->dropForeign(['menu_template_id']);
                }
                if (in_array('site_templates_auth_template_id_foreign', $existingForeignKeys)) {
                    $table->dropForeign(['auth_template_id']);
                }
            });
            
            // Теперь удаляем колонки
            Schema::table('site_templates', function (Blueprint $table) {
                if (Schema::hasColumn('site_templates', 'menu_template_id')) {
                    $table->dropColumn('menu_template_id');
                }
                if (Schema::hasColumn('site_templates', 'auth_template_id')) {
                    $table->dropColumn('auth_template_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_templates', function (Blueprint $table) {
            // Восстанавливаем старые поля
            $table->unsignedBigInteger('menu_template_id')->nullable();
            $table->unsignedBigInteger('auth_template_id')->nullable();
            
            // Восстанавливаем внешние ключи (если таблицы существуют)
            if (Schema::hasTable('site_menus')) {
                $table->foreign('menu_template_id')->references('id')->on('site_menus')->onDelete('set null');
            }
            if (Schema::hasTable('site_auth_blocks')) {
                $table->foreign('auth_template_id')->references('id')->on('site_auth_blocks')->onDelete('set null');
            }
        });
    }
};
