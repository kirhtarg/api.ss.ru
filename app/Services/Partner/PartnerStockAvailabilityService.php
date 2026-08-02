<?php

namespace App\Services\Partner;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopStock;
use Illuminate\Database\Eloquent\Builder;

final class PartnerStockAvailabilityService
{
    public function quantity(ShopGood $good, ?ShopGoodVariation $variation = null): int
    {
        if (! $this->productIsActiveForCatalog($good) || ($variation && ! $variation->is_active)) {
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
        return $this->productState($good)['is_available'];
    }

    /** @return array{available_quantity: int, is_available: bool} */
    public function productState(ShopGood $good): array
    {
        if (! $this->productIsActiveForCatalog($good)) {
            return ['available_quantity' => 0, 'is_available' => false];
        }

        $activeVariations = $good->relationLoaded('variations')
            ? $good->variations->where('is_active', true)
            : $good->variations()->where('is_active', true)->with('stock')->get();

        if ($activeVariations->isNotEmpty()) {
            $availableQuantity = (int) $activeVariations->sum(
                fn (ShopGoodVariation $variation): int => $this->quantity($good, $variation),
            );

            return [
                'available_quantity' => $availableQuantity,
                'is_available' => $availableQuantity > 0,
            ];
        }

        $availableQuantity = $this->quantity($good);

        return [
            'available_quantity' => $availableQuantity,
            'is_available' => $availableQuantity > 0,
        ];
    }

    public function productIsActiveForCatalog(ShopGood $good): bool
    {
        $attributes = $good->getAttributes();

        return (bool) $good->is_active
            && (! array_key_exists('is_show', $attributes) || (bool) $good->is_show);
    }

    public function applyCatalogEligibleQuery(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_show', true)->where(function (Builder $eligible): void {
            $eligible->where('is_preorder', true)
                ->orWhere(function (Builder $withoutVariations): void {
                    $withoutVariations->whereDoesntHave(
                        'variations',
                        fn (Builder $variations): Builder => $variations->where('is_active', true),
                    )->where(
                        fn (Builder $stock): Builder => $this->applyReservableStockQuery($stock, false),
                    );
                })
                ->orWhereHas('variations', function (Builder $variations): void {
                    $variations->where('is_active', true)->where(
                        fn (Builder $stock): Builder => $this->applyReservableStockQuery($stock, true),
                    );
                });
        });
    }

    private function applyReservableStockQuery(Builder $query, bool $variation): Builder
    {
        return $query->where(function (Builder $stock) use ($variation): void {
            $stock->whereHas('stock', function (Builder $rows) use ($variation): void {
                if (! $variation) {
                    $rows->whereNull('variation_id');
                }
                $rows->whereColumn('quantity', '>', 'reserved_quantity');
            })->orWhere(function (Builder $fallback) use ($variation): void {
                $fallback->whereDoesntHave('stock', function (Builder $rows) use ($variation): void {
                    if (! $variation) {
                        $rows->whereNull('variation_id');
                    }
                })->where('stock_quantity', '>', 0);
            });
        });
    }
}
