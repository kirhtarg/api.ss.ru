<?php

namespace App\Observers;

use App\Models\ShopGood;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopGoodSlugAliasObserver
{
    public function updating(ShopGood $good): void
    {
        if (! $good->isDirty('slug')) {
            return;
        }

        $oldSlug = trim((string) $good->getOriginal('slug'));
        if ($oldSlug === '' || ! Schema::hasTable('shop_good_slug_aliases')) {
            return;
        }

        DB::table('shop_good_slug_aliases')->insertOrIgnore([
            'good_id' => $good->id,
            'slug' => $oldSlug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
