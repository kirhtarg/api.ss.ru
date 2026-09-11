<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shop_good_images', 'is_size_chart')) {
            Schema::table('shop_good_images', function (Blueprint $table) {
                $table->boolean('is_size_chart')->default(false)->after('is_main');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shop_good_images', 'is_size_chart')) {
            Schema::table('shop_good_images', function (Blueprint $table) {
                $table->dropColumn('is_size_chart');
            });
        }
    }
};
