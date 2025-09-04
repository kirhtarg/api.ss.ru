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
        Schema::table('site_templates', function (Blueprint $table) {
            // Удаляем старые поля
            $table->dropColumn(['menu_template_id', 'auth_template_id']);
        });
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
