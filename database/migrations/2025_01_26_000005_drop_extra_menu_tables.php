<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Отключаем проверку внешних ключей
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Удаляем таблицы в правильном порядке (сначала зависимые)
        Schema::dropIfExists('shop_category_extra_menu_section_items');
        Schema::dropIfExists('shop_category_extra_menu_sections');
        Schema::dropIfExists('shop_category_extra_menu_filters');
        Schema::dropIfExists('shop_category_extra_menus');

        // Включаем проверку внешних ключей обратно
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // При откате ничего не делаем, так как это миграция для очистки
    }
};
