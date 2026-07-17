<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_yandex_market_category_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('selection_tag_id')->nullable()->after('account_id');
            $table->unsignedBigInteger('campaign_id')->nullable()->after('selection_tag_id');
            $table->index('selection_tag_id', 'ym_category_map_tag_idx');
            $table->index('campaign_id', 'ym_category_map_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shop_yandex_market_category_mappings', function (Blueprint $table) {
            $table->dropIndex('ym_category_map_tag_idx');
            $table->dropIndex('ym_category_map_campaign_idx');
            $table->dropColumn(['selection_tag_id', 'campaign_id']);
        });
    }
};
