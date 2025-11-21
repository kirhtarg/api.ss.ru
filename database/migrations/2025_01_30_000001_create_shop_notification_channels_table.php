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
        Schema::create('shop_notification_channels', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['email', 'telegram'])->default('email');
            $table->string('name'); // Название канала (например, "Email администратора", "Telegram менеджера")
            $table->string('email')->nullable(); // Email адрес (для типа email)
            $table->string('telegram_chat_id')->nullable(); // Chat ID для Telegram
            $table->string('telegram_bot_token')->nullable(); // Bot Token для Telegram
            $table->string('telegram_bot_username')->nullable(); // Username бота (для отображения)
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Дополнительные настройки (SMTP для email и т.д.)
            $table->text('description')->nullable(); // Описание канала
            $table->timestamps();
            
            // Индексы
            $table->index('type');
            $table->index('is_active');
            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_notification_channels');
    }
};

