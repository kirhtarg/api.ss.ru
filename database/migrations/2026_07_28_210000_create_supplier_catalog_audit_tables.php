<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 80)->index();
            $table->string('source_type', 20);
            $table->string('source_name');
            $table->string('source_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('checksum', 64)->nullable()->index();
            $table->string('status', 20)->default('processing')->index();
            $table->unsignedInteger('items_count')->default(0);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('supplier_catalog_snapshots')->cascadeOnDelete();
            $table->string('external_sku')->index();
            $table->string('external_group_key')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('brand')->nullable()->index();
            $table->string('section')->nullable();
            $table->string('sub_section')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('opt_price', 12, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('in_stock')->nullable();
            $table->json('source_axes')->nullable();
            $table->json('image_urls')->nullable();
            $table->json('raw_payload');
            $table->timestamps();

            $table->unique(['snapshot_id', 'external_sku'], 'supplier_snapshot_sku_unique');
            $table->index(['snapshot_id', 'external_group_key']);
        });

        Schema::create('supplier_catalog_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 80)->index();
            $table->string('source_field', 120);
            $table->string('scope', 20)->default('variation');
            $table->foreignId('variation_attribute_id')->nullable()->constrained('shop_variation_attributes')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('shop_properties')->nullOnDelete();
            $table->string('display_field', 120)->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_check_enabled')->default(true);
            $table->boolean('is_update_enabled')->default(false);
            $table->timestamps();

            $table->unique(['supplier_code', 'source_field', 'scope'], 'supplier_field_mapping_unique');
        });

        Schema::create('supplier_catalog_variation_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 80);
            $table->string('external_sku');
            $table->foreignId('variation_id')->constrained('shop_good_variations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['supplier_code', 'external_sku'], 'supplier_variation_binding_source_unique');
            $table->unique(['supplier_code', 'variation_id'], 'supplier_variation_binding_variation_unique');
        });

        Schema::create('supplier_catalog_group_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 80);
            $table->string('external_group_key');
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['supplier_code', 'external_group_key'], 'supplier_group_binding_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_group_bindings');
        Schema::dropIfExists('supplier_catalog_variation_bindings');
        Schema::dropIfExists('supplier_catalog_field_mappings');
        Schema::dropIfExists('supplier_catalog_items');
        Schema::dropIfExists('supplier_catalog_snapshots');
    }
};
