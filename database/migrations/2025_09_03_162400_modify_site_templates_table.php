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
            // Добавляем новое поле menu_id для связи с таблицей site_menus
            $table->unsignedBigInteger('menu_id')->nullable()->after('folder_name');
            $table->foreign('menu_id')->references('id')->on('site_menus')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_templates', function (Blueprint $table) {
            // Удаляем новое поле
            $table->dropForeign(['menu_id']);
            $table->dropColumn('menu_id');
        });
    }
};
