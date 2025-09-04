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
        // Сначала удаляем внешние ключи из site_menu_items
        if (Schema::hasTable('site_menu_items')) {
            Schema::table('site_menu_items', function (Blueprint $table) {
                // Проверяем существование внешних ключей перед удалением
                $foreignKeys = \DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_NAME = 'site_menu_items' 
                    AND CONSTRAINT_NAME LIKE '%_foreign'
                ");
                
                $existingForeignKeys = collect($foreignKeys)->pluck('CONSTRAINT_NAME')->toArray();
                
                if (in_array('site_menu_items_site_menu_id_foreign', $existingForeignKeys)) {
                    $table->dropForeign(['site_menu_id']);
                }
                if (in_array('site_menu_items_parent_id_foreign', $existingForeignKeys)) {
                    $table->dropForeign(['parent_id']);
                }
            });
        }
        
        // Проверяем существование внешних ключей перед удалением
        if (Schema::hasTable('site_templates')) {
            Schema::table('site_templates', function (Blueprint $table) {
                // Проверяем существование колонок перед удалением внешних ключей
                if (Schema::hasColumn('site_templates', 'menu_template_id')) {
                    $table->dropForeign(['menu_template_id']);
                }
                if (Schema::hasColumn('site_templates', 'auth_template_id')) {
                    $table->dropForeign(['auth_template_id']);
                }
            });
        }
        
        // Затем удаляем таблицы site_menus и site_auth_blocks
        Schema::dropIfExists('site_menus');
        Schema::dropIfExists('site_auth_blocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем таблицу site_menus
        Schema::create('site_menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Восстанавливаем таблицу site_auth_blocks
        Schema::create('site_auth_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Восстанавливаем внешние ключи
        Schema::table('site_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_template_id')->nullable();
            $table->unsignedBigInteger('auth_template_id')->nullable();
            $table->foreign('menu_template_id')->references('id')->on('site_menus')->onDelete('set null');
            $table->foreign('auth_template_id')->references('id')->on('site_auth_blocks')->onDelete('set null');
        });
    }
};
