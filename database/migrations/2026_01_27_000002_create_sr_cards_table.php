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
        Schema::create('sr_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable()->comment('HTML описание карты');
            $table->string('image')->nullable()->comment('Путь к изображению карты');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestamps();

            // Добавляем внешний ключ только если таблица категорий существует
            if (Schema::hasTable('sr_categories')) {
                $table->foreign('category_id')->references('id')->on('sr_categories')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sr_cards');
    }
};
