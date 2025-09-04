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
        // Сначала удаляем внешние ключи
        Schema::table('site_templates', function (Blueprint $table) {
            $table->dropForeign(['menu_template_id']);
            $table->dropForeign(['auth_template_id']);
        });
        
        // Затем удаляем таблицы site_menus и site_auth_blocks
        Schema::dropIfExists('site_menus');
        Schema::dropIfExists('site_auth_blocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем таблицу site_menus
        Schema::create('site_menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Восстанавливаем таблицу site_auth_blocks
        Schema::create('site_auth_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Восстанавливаем внешние ключи
        Schema::table('site_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_template_id')->nullable();
            $table->unsignedBigInteger('auth_template_id')->nullable();
            $table->foreign('menu_template_id')->references('id')->on('site_menus')->onDelete('set null');
            $table->foreign('auth_template_id')->references('id')->on('site_auth_blocks')->onDelete('set null');
        });
    }
};
