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
        Schema::dropIfExists('site_auth_blocks');
    }
};
