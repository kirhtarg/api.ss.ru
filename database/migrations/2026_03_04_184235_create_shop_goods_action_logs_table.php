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
        Schema::create('shop_goods_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->nullable()->constrained('shop_goods')->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->nullOnDelete();
            $table->string('action')->comment('created, deleted');
            $table->string('action_type')->nullable()->comment('Ман. удаление, Масс. удаление, Ред. товара и т.д.');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_goods_action_logs');
    }
};
