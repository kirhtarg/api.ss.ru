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
        Schema::create('constructor_block_settings', function (Blueprint $table) {
            $table->id();
            $table->string('block_type')->unique();
            $table->string('block_name');
            $table->string('category');
            $table->string('icon')->nullable();
            $table->json('default_settings');
            $table->json('available_settings');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('constructor_block_settings');
    }
};