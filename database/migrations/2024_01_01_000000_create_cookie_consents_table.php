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
        Schema::create('cookie_consents', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique(); // Уникальный идентификатор сессии
            $table->string('ip_address', 45)->nullable(); // IP адрес пользователя
            $table->string('user_agent')->nullable(); // User Agent браузера
            $table->boolean('necessary_cookies')->default(true); // Необходимые куки (всегда true)
            $table->boolean('analytics_cookies')->default(false); // Аналитические куки
            $table->boolean('marketing_cookies')->default(false); // Маркетинговые куки
            $table->boolean('preferences_cookies')->default(false); // Куки предпочтений
            $table->timestamp('consent_given_at'); // Время согласия
            $table->timestamp('expires_at'); // Время истечения согласия (например, через 1 год)
            $table->timestamps();
            
            // Индексы для быстрого поиска
            $table->index(['session_id', 'expires_at']);
            $table->index('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cookie_consents');
    }
};
