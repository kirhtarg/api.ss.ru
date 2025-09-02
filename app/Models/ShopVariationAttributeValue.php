<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ShopVariationAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'attribute_id',
        'value',
        'color',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Атрибут, к которому относится это значение
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ShopVariationAttribute::class, 'attribute_id');
    }

    /**
     * Вариации с этим значением атрибута
     */
    public function variations(): BelongsToMany
    {
        return $this->belongsToMany(ShopGoodVariation::class, 'shop_variation_attributes_values');
    }

    /**
     * Scope для активных значений
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('value');
    }
}
