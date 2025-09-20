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
        Schema::create('sliders', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название слайдера
            $table->enum('transition_type', ['fade', 'slide', 'zoom'])->default('fade'); // Тип смены изображения
            $table->enum('control_type', ['auto', 'manual'])->default('auto'); // Автозапуск или ручное управление
            $table->integer('auto_interval')->default(5000); // Интервал автозапуска в миллисекундах
            $table->integer('transition_duration')->default(1000); // Время перелистывания слайдов в миллисекундах
            $table->enum('title_position', ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'])->default('center'); // Расположение заголовка
            $table->enum('text_position', ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'])->default('center'); // Расположение текста
            $table->boolean('is_active')->default(true); // Активен ли слайдер
            $table->integer('sort_order')->default(0); // Порядок сортировки
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sliders');
    }
};
