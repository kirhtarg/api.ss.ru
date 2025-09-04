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
            Schema::table('admin_section_role', function (Blueprint $table) {
                // Добавляем внешние ключи
                $table->foreign('admin_section_id')->references('id')->on('admin_sections')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
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
