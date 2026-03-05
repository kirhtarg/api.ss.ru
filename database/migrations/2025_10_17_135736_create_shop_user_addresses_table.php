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
        Schema::create('shop_user_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name'); // Название адреса (например, "Дом", "Работа", "Дача")
            $table->string('city'); // Город
            $table->string('street'); // Улица
            $table->string('house'); // Дом
            $table->string('apartment')->nullable(); // Квартира
            $table->string('entrance')->nullable(); // Подъезд
            $table->string('intercom')->nullable(); // Код домофона
            $table->text('comment')->nullable(); // Комментарий к адресу
            $table->boolean('is_default')->default(false); // Адрес по умолчанию
            $table->timestamps();

            // Индексы
            $table->index('user_id');
            $table->index(['user_id', 'is_default']);

            // Внешний ключ
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_user_addresses');
    }
};
