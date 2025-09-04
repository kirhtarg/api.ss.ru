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
        // Проверяем, что таблица site_templates существует
        if (Schema::hasTable('site_templates')) {
            Schema::table('site_templates', function (Blueprint $table) {
                // Удаляем старые поля только если они существуют
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
        });
    }
};
