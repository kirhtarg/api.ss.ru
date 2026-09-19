<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_good_slug_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamps();
            $table->index('good_id');
        });

        // Repair the legacy URL explicitly reported in Yandex diagnostics.
        // Future slug changes are captured by ShopGoodSlugAliasObserver.
        $good = DB::table('shop_goods')->where('sku', '8040083')->first(['id']);
        if ($good) {
            DB::table('shop_good_slug_aliases')->insertOrIgnore([
                'good_id' => $good->id,
                'slug' => 'altalist-kaku-sp2-white-frameblue-viv0-mirror-photochromic-lens-ocki-solncezasht',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_good_slug_aliases');
    }
};
