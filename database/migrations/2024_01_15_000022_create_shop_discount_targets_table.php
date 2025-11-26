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
        Schema::create('shop_discount_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained('shop_discounts')->onDelete('cascade');
            $table->string('target_type'); // good, category, brand, tag
            $table->unsignedBigInteger('target_id'); // ID целевого объекта
            $table->timestamps();
            
            // Индексы для производительности
            $table->index(['discount_id', 'target_type']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_discount_targets');
    }
};
