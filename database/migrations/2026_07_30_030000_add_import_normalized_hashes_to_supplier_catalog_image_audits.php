<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_image_audits', function (Blueprint $table) {
            $table->char('normalized_crop_hash', 16)->nullable()->after('perceptual_hash')->index();
            $table->char('normalized_white_hash', 16)->nullable()->after('normalized_crop_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_image_audits', function (Blueprint $table) {
            $table->dropIndex(['normalized_crop_hash']);
            $table->dropIndex(['normalized_white_hash']);
            $table->dropColumn(['normalized_crop_hash', 'normalized_white_hash']);
        });
    }
};
