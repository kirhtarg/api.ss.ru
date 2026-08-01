<?php

namespace App\Services\Partner;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopStock;

final class PartnerStockAvailabilityService
{
    public function quantity(ShopGood $good, ?ShopGoodVariation $variation = null): int
    {
        if (! $good->is_active || ($variation && ! $variation->is_active)) {
            return 0;
        }

        $stock = $variation?->relationLoaded('stock')
            ? $variation->stock
            : ($good->relationLoaded('stock')
                ? $good->stock->where('variation_id', $variation?->id)
                : ShopStock::query()
                    ->where('good_id', $good->id)
                    ->when($variation, fn ($query) => $query->where('variation_id', $variation->id), fn ($query) => $query->whereNull('variation_id'))
                    ->get());

        if ($stock->isNotEmpty()) {
            return (int) $stock->sum(fn (ShopStock $row): int => max(0, (int) $row->quantity - (int) $row->reserved_quantity));
        }

        return max(0, (int) ($variation?->stock_quantity ?? $good->stock_quantity));
    }

    public function productIsAvailable(ShopGood $good): bool
    {
        if (! $good->is_active) {
            return false;
        }

        $activeVariations = $good->relationLoaded('variations')
            ? $good->variations->where('is_active', true)
            : $good->variations()->where('is_active', true)->get();

        if ($activeVariations->isNotEmpty()) {
            return $activeVariations->contains(fn (ShopGoodVariation $variation): bool => $this->quantity($good, $variation) > 0);
        }

        return $this->quantity($good) > 0;
    }
}
