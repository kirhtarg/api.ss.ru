<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_yandex_market_attribute_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_yandex_market_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('market_parameter_id');
            $table->string('market_parameter_name');
            $table->json('mapping');
            $table->timestamps();

            $table->unique(['account_id', 'market_parameter_id'], 'yandex_market_attribute_template_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_yandex_market_attribute_templates');
    }
};
