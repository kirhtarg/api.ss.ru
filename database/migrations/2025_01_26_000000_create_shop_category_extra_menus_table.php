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
        Schema::create('shop_category_extra_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
            $table->boolean('is_active')->default(false);
            $table->string('title')->nullable(); // Заголовок блока экстра-меню
            $table->timestamps();

            // Индексы
            $table->index('category_id');
            $table->index('is_active');
            $table->unique('category_id'); // Один экстра-меню на категорию
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем зависимые таблицы сначала (в обратном порядке создания)
        Schema::dropIfExists('shop_category_extra_menu_section_items');
        Schema::dropIfExists('shop_category_extra_menu_sections');
        Schema::dropIfExists('shop_category_extra_menu_filters');
        // Теперь можно удалить основную таблицу
        Schema::dropIfExists('shop_category_extra_menus');
    }
};
