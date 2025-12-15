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
        Schema::create('textblocks', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название блока
            $table->text('text')->nullable(); // Текст блока
            $table->string('background_color', 7)->default('#ffffff'); // Цвет фона в формате HEX
            $table->string('text_color', 7)->default('#000000'); // Цвет текста в формате HEX
            $table->string('link')->nullable(); // Ссылка в тексте
            $table->enum('link_type', ['internal', 'external'])->default('internal'); // Тип ссылки
            $table->boolean('is_active')->default(true); // Активен ли блок
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('textblocks');
    }
};
