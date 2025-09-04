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
        // Добавляем внешние ключи только если таблица существует
        if (Schema::hasTable('admin_section_role')) {
            // Проверяем, существуют ли уже внешние ключи
            $foreignKeys = \DB::select("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'admin_section_role' 
                AND CONSTRAINT_NAME LIKE '%_foreign'
            ");
            
            $existingForeignKeys = collect($foreignKeys)->pluck('CONSTRAINT_NAME')->toArray();
            
            if (!in_array('admin_section_role_admin_section_id_foreign', $existingForeignKeys) || 
                !in_array('admin_section_role_role_id_foreign', $existingForeignKeys)) {
                
                Schema::table('admin_section_role', function (Blueprint $table) {
                    // Добавляем внешние ключи только если их еще нет
                    if (!in_array('admin_section_role_admin_section_id_foreign', $existingForeignKeys)) {
                        $table->foreign('admin_section_id')->references('id')->on('admin_sections')->onDelete('cascade');
                    }
                    if (!in_array('admin_section_role_role_id_foreign', $existingForeignKeys)) {
                        $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('admin_section_role')) {
            Schema::table('admin_section_role', function (Blueprint $table) {
                $table->dropForeign(['admin_section_id']);
                $table->dropForeign(['role_id']);
            });
        }
    }
};
