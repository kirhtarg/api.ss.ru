<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOzonCategoryMapping extends Model
{
    protected $fillable = [
        'account_id',
        'category_id',
        'category_ids',
        'description_category_id',
        'type_id',
        'ozon_category_name',
        'variation_mode',
        'group_attribute_id',
        'attribute_mappings',
        'variation_attribute_mappings',
        'dimension_settings',
        'price_adjustment',
        'is_active',
    ];
    protected $casts = [
        'category_ids' => 'array',
        'attribute_mappings' => 'array',
        'variation_attribute_mappings' => 'array',
        'dimension_settings' => 'array',
        'price_adjustment' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo { return $this->belongsTo(ShopOzonAccount::class, 'account_id'); }
    public function category(): BelongsTo { return $this->belongsTo(ShopCategory::class, 'category_id'); }

    public function categoryIds(): array
    {
        return collect($this->category_ids ?: [$this->category_id])
            ->push($this->category_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
