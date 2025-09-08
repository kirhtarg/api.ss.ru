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
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название шаблона
            $table->text('description')->nullable(); // Описание шаблона
            $table->json('settings'); // JSON с настройками импорта
            $table->boolean('is_default')->default(false); // Шаблон по умолчанию
            $table->timestamps();
            
            // Индексы
            $table->index('name');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};
