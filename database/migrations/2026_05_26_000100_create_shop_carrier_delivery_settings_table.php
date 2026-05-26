<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_carrier_delivery_settings')) {
            return;
        }

        Schema::create('shop_carrier_delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->string('carrier', 50)->unique();
            $table->string('api_url')->nullable();
            $table->text('api_token')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('warehouse_id')->nullable();
            $table->string('sender_city')->nullable();
            $table->string('sender_street')->nullable();
            $table->string('sender_house')->nullable();
            $table->string('sender_postal_code')->nullable();
            $table->decimal('default_weight', 8, 2)->default(0.5);
            $table->decimal('default_length', 8, 2)->default(10);
            $table->decimal('default_width', 8, 2)->default(10);
            $table->decimal('default_height', 8, 2)->default(10);
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_carrier_delivery_settings');
    }
};
