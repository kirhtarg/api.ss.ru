<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_yandex_market_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Основной кабинет');
            $table->text('api_key');
            $table->string('api_url')->default('https://api.partner.market.yandex.ru');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->foreignId('selection_tag_id')->nullable()->constrained('shop_tags')->nullOnDelete();
            $table->string('image_base_url')->default('https://skateandsnow.ru');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connection_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('campaigns')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_yandex_market_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_yandex_market_accounts')->cascadeOnDelete();
            $table->json('category_ids');
            $table->unsignedBigInteger('market_category_id');
            $table->string('market_category_name')->nullable();
            $table->json('attribute_mappings')->nullable();
            $table->json('dimension_settings')->nullable();
            $table->json('price_adjustment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'market_category_id'], 'yandex_market_mapping_unique');
        });

        Schema::create('shop_yandex_market_product_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('shop_yandex_market_accounts')->cascadeOnDelete();
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->nullOnDelete();
            $table->string('offer_id');
            $table->unsignedBigInteger('market_sku')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('content_rating')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('remote_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'offer_id'], 'yandex_market_offer_unique');
        });

        Schema::create('shop_yandex_market_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('shop_yandex_market_sync_runs')->cascadeOnDelete();
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->nullOnDelete();
            $table->string('offer_id');
            $table->string('status')->default('pending');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'status']);
        });

        Schema::table('shop_yandex_market_sync_runs', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')->constrained('shop_yandex_market_accounts')->nullOnDelete();
            $table->json('good_ids')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shop_yandex_market_sync_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('good_ids');
        });
        Schema::dropIfExists('shop_yandex_market_sync_items');
        Schema::dropIfExists('shop_yandex_market_product_bindings');
        Schema::dropIfExists('shop_yandex_market_category_mappings');
        Schema::dropIfExists('shop_yandex_market_accounts');
    }
};
