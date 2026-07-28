<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->string('clean_name')->nullable()->after('name');
            $table->string('source_color')->nullable()->after('clean_name');
            $table->string('source_size')->nullable()->after('source_color');
            $table->boolean('is_source_variation')->default(false)->after('source_size');
            $table->index(['snapshot_id', 'clean_name'], 'supplier_snapshot_clean_name_index');
            $table->index(['snapshot_id', 'is_source_variation'], 'supplier_snapshot_variation_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->dropIndex('supplier_snapshot_clean_name_index');
            $table->dropIndex('supplier_snapshot_variation_index');
            $table->dropColumn(['clean_name', 'source_color', 'source_size', 'is_source_variation']);
        });
    }
};
