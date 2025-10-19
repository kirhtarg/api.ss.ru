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
        Schema::create('shop_order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique(); // pending, processing, shipped, delivered, cancelled
            $table->string('display_name', 100); // Ожидает, В обработке, Отправлен, Доставлен, Отменен
            $table->string('color', 7)->default('#6B7280'); // Цвет для отображения в админке
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_order_statuses');
    }
};
