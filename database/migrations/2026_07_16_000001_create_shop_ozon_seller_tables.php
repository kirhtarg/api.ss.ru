<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_ozon_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Основной кабинет');
            $table->string('client_id');
            $table->text('api_key');
            $table->string('api_url')->default('https://api-seller.ozon.ru');
            $table->string('warehouse_id')->nullable();
            $table->string('image_base_url')->default('https://skateandsnow.ru');
            $table->string('vat')->default('0');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connection_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_ozon_accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('shop_categories')->cascadeOnDelete();
            $table->unsignedBigInteger('description_category_id');
            $table->unsignedBigInteger('type_id');
            $table->string('ozon_category_name')->nullable();
            $table->json('attribute_mappings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'category_id']);
        });

        Schema::create('shop_ozon_product_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_ozon_accounts')->cascadeOnDelete();
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->cascadeOnDelete();
            $table->string('offer_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('sku')->nullable();
            $table->string('status')->default('pending');
            $table->string('payload_hash', 64)->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'offer_id']);
        });

        Schema::create('shop_ozon_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_ozon_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode');
            $table->string('status')->default('pending');
            $table->json('good_ids')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_ozon_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('shop_ozon_sync_runs')->cascadeOnDelete();
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->cascadeOnDelete();
            $table->string('offer_id');
            $table->string('status')->default('pending');
            $table->string('task_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_ozon_sync_items');
        Schema::dropIfExists('shop_ozon_sync_runs');
        Schema::dropIfExists('shop_ozon_product_bindings');
        Schema::dropIfExists('shop_ozon_category_mappings');
        Schema::dropIfExists('shop_ozon_accounts');
    }
};
