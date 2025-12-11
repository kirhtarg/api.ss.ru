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
        // Если таблица уже существует, добавляем только недостающие индексы
        if (Schema::hasTable('shop_category_extra_menu_filters')) {
            Schema::table('shop_category_extra_menu_filters', function (Blueprint $table) {
                // Проверяем и добавляем индексы, если их нет
                $indexes = DB::select("SHOW INDEXES FROM `shop_category_extra_menu_filters`");
                $indexNames = array_map(function($index) {
                    return $index->Key_name;
                }, $indexes);
                
                if (!in_array('shop_category_extra_menu_filters_extra_menu_id_index', $indexNames)) {
                    $table->index('extra_menu_id');
                }
                
                if (!in_array('extra_menu_filters_menu_type_active_idx', $indexNames)) {
                    $table->index(['extra_menu_id', 'type', 'is_active'], 'extra_menu_filters_menu_type_active_idx');
                }
                
                if (!in_array('shop_category_extra_menu_filters_sort_order_index', $indexNames)) {
                    $table->index('sort_order');
                }
            });
        } else {
            Schema::create('shop_category_extra_menu_filters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('extra_menu_id')->constrained('shop_category_extra_menus')->onDelete('cascade');
                $table->string('type'); // 'price' или 'characteristic'
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                
                // Для фильтра цены
                $table->decimal('price_min', 10, 2)->nullable();
                $table->decimal('price_max', 10, 2)->nullable();
                
                // Для фильтра характеристик - название свойства из shop_properties (name)
                $table->string('characteristic_name')->nullable();
                
                $table->timestamps();
                
                // Индексы
                $table->index('extra_menu_id');
                $table->index(['extra_menu_id', 'type', 'is_active'], 'extra_menu_filters_menu_type_active_idx');
                $table->index('sort_order');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_category_extra_menu_filters');
    }
};

