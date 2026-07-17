<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ShopYandexMarketCategoryMapping extends Model
{
    protected $fillable = [
        'account_id', 'category_ids', 'market_category_id', 'market_category_name',
        'attribute_mappings', 'dimension_settings', 'price_adjustment', 'is_active',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'attribute_mappings' => 'array',
        'dimension_settings' => 'array',
        'price_adjustment' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ShopYandexMarketAccount::class, 'account_id');
    }

    public function categoryIds(): Collection
    {
        return collect($this->category_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
    }
}
