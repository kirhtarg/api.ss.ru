<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slider_images', function (Blueprint $table) {
            if (! Schema::hasColumn('slider_images', 'button_text')) {
                $table->string('button_text')->nullable()->after('link_type');
            }
            if (! Schema::hasColumn('slider_images', 'mobile_target_type')) {
                $table->string('mobile_target_type')->nullable()->after('button_text');
            }
            if (! Schema::hasColumn('slider_images', 'mobile_target_id')) {
                $table->unsignedBigInteger('mobile_target_id')->nullable()->after('mobile_target_type');
            }
            if (! Schema::hasColumn('slider_images', 'mobile_link')) {
                $table->string('mobile_link')->nullable()->after('mobile_target_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('slider_images', function (Blueprint $table) {
            foreach (['mobile_link', 'mobile_target_id', 'mobile_target_type', 'button_text'] as $column) {
                if (Schema::hasColumn('slider_images', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
