<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_auth_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название шаблона блока авторизации');
            $table->text('description')->nullable()->comment('Описание шаблона блока авторизации');
            $table->string('template_name')->comment('Название файла шаблона (без .vue)');
            $table->boolean('is_active')->default(true)->comment('Активен ли шаблон');
            $table->json('settings')->nullable()->comment('Настройки шаблона в JSON');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Сначала удаляем все внешние ключи, которые ссылаются на эту таблицу
        if (Schema::hasTable('site_templates')) {
            // Удаляем внешний ключ auth_template_id из site_templates
            try {
                DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_templates_auth_template_id_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE site_templates DROP FOREIGN KEY site_template_auth_template_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
        }

        // Теперь безопасно удаляем таблицу
        Schema::dropIfExists('site_auth_blocks');
    }
};
