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
        Schema::create('shop_order_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop_orders')->onDelete('cascade');
            $table->foreignId('action_icon_id')->nullable()->constrained('shop_order_log_icons')->onDelete('set null');
            $table->text('action'); // Действие (Заказ создан, Смена статуса, Комментарий и т.д.)
            $table->string('action_color')->nullable(); // Цвет текста действия
            $table->string('action_bg_color')->nullable(); // Цвет фона действия
            $table->text('comment')->nullable(); // Комментарий
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // ID пользователя (админа)
            $table->string('user_name')->nullable(); // Имя пользователя для отображения
            $table->timestamp('created_at')->nullable();

            // Индексы
            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_order_logs');
    }
};
