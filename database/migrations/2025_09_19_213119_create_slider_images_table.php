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
        Schema::create('slider_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slider_id')->constrained()->onDelete('cascade'); // Связь со слайдером
            $table->string('image_path'); // Путь к изображению в папке public/sliders
            $table->string('title')->nullable(); // Заголовок слайда
            $table->text('text')->nullable(); // Текст слайда
            $table->string('link')->nullable(); // Ссылка при клике на слайд
            $table->enum('link_type', ['internal', 'external'])->default('internal'); // Тип ссылки (внутренняя или внешняя)
            $table->boolean('is_active')->default(true); // Активен ли слайд
            $table->integer('sort_order')->default(0); // Порядок сортировки
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slider_images');
    }
};
