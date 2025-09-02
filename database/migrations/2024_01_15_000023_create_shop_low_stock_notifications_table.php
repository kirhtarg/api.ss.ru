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
        Schema::create('shop_low_stock_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('shop_warehouses')->onDelete('cascade');
            $table->integer('current_quantity');
            $table->integer('min_quantity');
            $table->boolean('is_sent')->default(false);
            $table->datetime('sent_at')->nullable();
            $table->timestamps();
            
            // Индексы для производительности
            $table->index(['good_id', 'warehouse_id']);
            $table->index(['variation_id', 'warehouse_id']);
            $table->index('is_sent');
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_low_stock_notifications');
    }
};
