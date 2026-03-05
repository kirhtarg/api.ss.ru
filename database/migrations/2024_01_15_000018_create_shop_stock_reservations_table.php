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
        Schema::create('shop_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('shop_warehouses')->onDelete('cascade');
            $table->integer('quantity');
            $table->datetime('reserved_until');
            $table->string('reservation_type')->default('order'); // order, cart, manual
            $table->string('reference_id')->nullable(); // ID заказа, корзины и т.д.
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Индексы для производительности
            $table->index(['good_id', 'warehouse_id']);
            $table->index(['variation_id', 'warehouse_id']);
            $table->index('reserved_until');
            $table->index('reservation_type');
            $table->index('reference_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_stock_reservations');
    }
};
