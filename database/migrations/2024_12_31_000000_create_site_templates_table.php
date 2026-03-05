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
        Schema::create('site_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название шаблона сайта');
            $table->text('description')->nullable()->comment('Описание шаблона сайта');
            $table->string('folder_name')->comment('Название папки с шаблоном');
            $table->unsignedBigInteger('menu_template_id')->nullable()->comment('ID шаблона меню');
            $table->unsignedBigInteger('auth_template_id')->nullable()->comment('ID шаблона блока авторизации');
            $table->boolean('is_active')->default(false)->comment('Активен ли шаблон (только один может быть активен)');
            $table->json('settings')->nullable()->comment('Настройки шаблона в JSON');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            $table->timestamps();

            // Внешние ключи (создаем только если таблицы существуют)
            if (Schema::hasTable('site_menus')) {
                $table->foreign('menu_template_id')->references('id')->on('site_menus')->onDelete('set null');
            }
            if (Schema::hasTable('site_auth_blocks')) {
                $table->foreign('auth_template_id')->references('id')->on('site_auth_blocks')->onDelete('set null');
            }

            // Индексы
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_templates');
    }
};
