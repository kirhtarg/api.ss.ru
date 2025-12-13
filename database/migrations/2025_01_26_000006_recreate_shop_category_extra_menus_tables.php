<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Отключаем проверку внешних ключей
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Создаем основную таблицу, если её нет
        if (!Schema::hasTable('shop_category_extra_menus')) {
            Schema::create('shop_category_extra_menus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
                $table->boolean('is_active')->default(false);
                $table->string('title')->nullable();
                $table->timestamps();
                
                $table->index('category_id');
                $table->index('is_active');
                $table->unique('category_id');
            });
        }
        
        // Создаем таблицу фильтров, если её нет
        if (!Schema::hasTable('shop_category_extra_menu_filters')) {
            Schema::create('shop_category_extra_menu_filters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('extra_menu_id')->constrained('shop_category_extra_menus')->onDelete('cascade');
                $table->string('type');
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->decimal('price_min', 10, 2)->nullable();
                $table->decimal('price_max', 10, 2)->nullable();
                $table->string('characteristic_name')->nullable();
                $table->timestamps();
                
                $table->index('extra_menu_id');
                $table->index(['extra_menu_id', 'type', 'is_active'], 'extra_menu_filters_menu_type_active_idx');
                $table->index('sort_order');
            });
        }
        
        // Создаем таблицу подразделов, если её нет
        if (!Schema::hasTable('shop_category_extra_menu_sections')) {
            Schema::create('shop_category_extra_menu_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('extra_menu_id')->constrained('shop_category_extra_menus')->onDelete('cascade');
                $table->string('title');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                
                $table->index('extra_menu_id');
                $table->index('sort_order');
            });
        }
        
        // Создаем таблицу элементов подразделов, если её нет
        if (!Schema::hasTable('shop_category_extra_menu_section_items')) {
            Schema::create('shop_category_extra_menu_section_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('section_id')->constrained('shop_category_extra_menu_sections')->onDelete('cascade');
                $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                
                $table->index('section_id');
                $table->index('category_id');
                $table->index('sort_order');
                $table->unique(['section_id', 'category_id'], 'extra_menu_section_category_unique');
            });
        }
        
        // Включаем проверку внешних ключей обратно
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // При откате ничего не делаем, так как это миграция для восстановления
    }
};













