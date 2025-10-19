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
        Schema::create('site_menu_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_menu_id')->nullable();
            $table->string('title')->comment('Название пункта меню');
            $table->string('url')->comment('URL пункта меню');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('ID родительского пункта меню');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            $table->boolean('is_active')->default(true)->comment('Активен ли пункт меню');
            $table->string('target')->default('_self')->comment('Цель ссылки (_self, _blank)');
            $table->string('icon')->nullable();
            $table->json('attributes')->nullable()->comment('Дополнительные атрибуты в JSON');
            $table->timestamps();
            
            $table->index(['parent_id', 'sort_order', 'is_active'], 'site_menu_items_parent_id_sort_order_is_active_index');
            $table->foreign('parent_id')->references('id')->on('site_menu_items')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_menu_items');
    }
};