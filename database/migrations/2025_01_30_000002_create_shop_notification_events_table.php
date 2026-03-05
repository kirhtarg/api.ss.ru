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
        Schema::create('shop_notification_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->enum('event_type', [
                'order_created',
                'cancellation_request',
                'order_cancelled',
                'preorder_created',
                'site_message',
            ]);
            $table->boolean('is_enabled')->default(true);
            $table->json('template')->nullable(); // Шаблон сообщения (опционально)
            $table->timestamps();

            // Индексы
            $table->index('channel_id');
            $table->index('event_type');
            $table->index('is_enabled');
            $table->index(['channel_id', 'event_type']);
            $table->unique(['channel_id', 'event_type']); // Один канал - одно событие

            // Внешние ключи
            $table->foreign('channel_id')->references('id')->on('shop_notification_channels')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_notification_events');
    }
};
