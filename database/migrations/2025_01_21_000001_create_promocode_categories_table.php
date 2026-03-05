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
        Schema::create('promocode_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocode_id')->constrained('promocodes')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['promocode_id', 'category_id']);
            $table->index('promocode_id');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_categories');
    }
};
