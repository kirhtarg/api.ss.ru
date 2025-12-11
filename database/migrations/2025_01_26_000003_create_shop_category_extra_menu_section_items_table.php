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
        Schema::create('shop_category_extra_menu_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('shop_category_extra_menu_sections')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            // Индексы
            $table->index('section_id');
            $table->index('category_id');
            $table->index('sort_order');
            
            // Уникальность - подкатегория не может быть в одном подразделе дважды
            $table->unique(['section_id', 'category_id'], 'extra_menu_section_category_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_category_extra_menu_section_items');
    }
};


