<?php

namespace App\Models;

use App\Models\Shop\Property;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCatalogFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_code', 'source_field', 'scope', 'variation_attribute_id', 'property_id',
        'display_field', 'conditions', 'is_check_enabled', 'is_update_enabled',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_check_enabled' => 'boolean',
        'is_update_enabled' => 'boolean',
    ];

    public function variationAttribute(): BelongsTo
    {
        return $this->belongsTo(ShopVariationAttribute::class, 'variation_attribute_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
}
