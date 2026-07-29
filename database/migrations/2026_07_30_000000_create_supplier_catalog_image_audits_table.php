<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_image_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('supplier_catalog_snapshots')->cascadeOnDelete();
            $table->text('source_url');
            $table->char('source_url_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->char('content_hash', 64)->nullable();
            $table->char('perceptual_hash', 16)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_id', 'source_url_hash'], 'supplier_catalog_image_audit_url_unique');
            $table->index(['snapshot_id', 'status'], 'supplier_catalog_image_audit_status_index');
            $table->index('content_hash', 'supplier_catalog_image_audit_content_hash_index');
        });

        Schema::table('shop_good_images', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('file_path')->index();
            $table->char('perceptual_hash', 16)->nullable()->after('content_hash')->index();
            $table->unsignedInteger('image_width')->nullable()->after('perceptual_hash');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->timestamp('content_checked_at')->nullable()->after('image_height');
        });
    }

    public function down(): void
    {
        Schema::table('shop_good_images', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropIndex(['perceptual_hash']);
            $table->dropColumn(['content_hash', 'perceptual_hash', 'image_width', 'image_height', 'content_checked_at']);
        });

        Schema::dropIfExists('supplier_catalog_image_audits');
    }
};
