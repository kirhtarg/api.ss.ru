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
        Schema::create('site_menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
        // Сначала удаляем все внешние ключи, которые ссылаются на эту таблицу
        if (Schema::hasTable('site_templates')) {
            // Удаляем внешний ключ menu_id из site_templates
            try {
                DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_templates_menu_id_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_template_menu_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
        }
        
        // Теперь безопасно удаляем таблицу
        Schema::dropIfExists('site_menus');
    }
};