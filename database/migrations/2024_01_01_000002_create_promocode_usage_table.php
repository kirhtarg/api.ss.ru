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
        Schema::create('promocode_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocode_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // null для неавторизованных
            $table->string('session_id')->nullable(); // Для неавторизованных пользователей
            $table->foreignId('order_id')->nullable()->constrained('shop_orders')->onDelete('set null');
            $table->decimal('discount_amount', 10, 2);
            $table->json('applied_to')->nullable(); // JSON с информацией о том, к чему применен промокод
            $table->timestamp('used_at');
            $table->timestamps();
            
            $table->index(['promocode_id', 'user_id']);
            $table->index(['promocode_id', 'session_id']);
            $table->index('used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_usage');
    }
};
