<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_accounts', function (Blueprint $table) {
            $table->foreignId('selection_tag_id')->nullable()->after('warehouse_id')
                ->constrained('shop_tags')->nullOnDelete();
        });

        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->json('category_ids')->nullable()->after('category_id');
            $table->string('variation_mode', 20)->default('grouped')->after('ozon_category_name');
            $table->unsignedBigInteger('group_attribute_id')->nullable()->after('variation_mode');
            $table->json('variation_attribute_mappings')->nullable()->after('attribute_mappings');
        });

        Schema::table('shop_ozon_product_bindings', function (Blueprint $table) {
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->nullOnDelete();
            $table->boolean('is_variation')->default(false)->after('variation_id');
            $table->json('remote_payload')->nullable()->after('errors');
            $table->timestamp('remote_updated_at')->nullable()->after('last_synced_at');
        });
        \Illuminate\Support\Facades\DB::table('shop_ozon_product_bindings')->whereNotNull('variation_id')->update(['is_variation' => true]);

        Schema::table('shop_ozon_sync_items', function (Blueprint $table) {
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_sync_items', function (Blueprint $table) {
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->cascadeOnDelete();
        });

        Schema::table('shop_ozon_product_bindings', function (Blueprint $table) {
            $table->dropColumn(['is_variation', 'remote_payload', 'remote_updated_at']);
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->cascadeOnDelete();
        });

        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->dropColumn([
                'category_ids',
                'variation_mode',
                'group_attribute_id',
                'variation_attribute_mappings',
            ]);
        });

        Schema::table('shop_ozon_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selection_tag_id');
        });
    }
};
