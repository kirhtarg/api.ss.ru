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
        // Добавляем внешние ключи только если таблица существует и не имеет внешних ключей
        if (Schema::hasTable('admin_page_role')) {
            Schema::table('admin_page_role', function (Blueprint $table) {
                // Проверяем, что внешние ключи еще не существуют
                if (!Schema::hasColumn('admin_page_role', 'admin_page_id')) {
                    $table->unsignedBigInteger('admin_page_id')->change();
                }
                if (!Schema::hasColumn('admin_page_role', 'role_id')) {
                    $table->unsignedBigInteger('role_id')->change();
                }
                
                // Добавляем внешние ключи
                $table->foreign('admin_page_id')->references('id')->on('admin_pages')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('admin_page_role')) {
            Schema::table('admin_page_role', function (Blueprint $table) {
                $table->dropForeign(['admin_page_id']);
                $table->dropForeign(['role_id']);
            });
        }
    }
};
