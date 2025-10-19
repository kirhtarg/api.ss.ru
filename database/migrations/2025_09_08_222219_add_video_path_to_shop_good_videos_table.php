<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_good_videos', function (Blueprint $table) {
            // Добавляем поле video_path если его нет
            if (!Schema::hasColumn('shop_good_videos', 'video_path')) {
                $table->string('video_path')->nullable()->after('variation_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_videos', function (Blueprint $table) {
            // Удаляем поле video_path при откате
            if (Schema::hasColumn('shop_good_videos', 'video_path')) {
                $table->dropColumn('video_path');
            }
        });
    }
};
