<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_good_images', function (Blueprint $table) {
            $table->char('source_content_hash', 64)->nullable()->after('file_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shop_good_images', function (Blueprint $table) {
            $table->dropIndex(['source_content_hash']);
            $table->dropColumn('source_content_hash');
        });
    }
};
