<?php

namespace App\Services\Partner;

use App\Models\ShopCategory;
use App\Models\ShopGood;

class PartnerCatalogService
{
    public function categories()
    {
        return ShopCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'parent_id', 'name', 'slug', 'image']);
    }

    public function products(array $filters)
    {
        return ShopGood::query()
            ->where('is_active', true)
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'categories',
                fn ($categories) => $categories->where('shop_categories.id', $id),
            ))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(
                fn ($search) => $search->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"),
            ))
            ->with([
                'images' => fn ($query) => $query->orderByDesc('is_main')->orderBy('sort_order'),
                'variations' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 24), 100));
    }

    public function product(ShopGood $good): ShopGood
    {
        abort_unless($good->is_active, 404);

        return $good->load(['images', 'variations', 'categories', 'brands', 'properties']);
    }
}
