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
        Schema::table('site_menus', function (Blueprint $table) {
            $table->string('template_name')->nullable()->comment('Название файла шаблона (без .vue)');
            $table->json('settings')->nullable()->comment('Настройки шаблона в JSON');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
        });
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
