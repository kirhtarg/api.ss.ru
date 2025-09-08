<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopGoodVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'name',
        'description',
        'short_description',
        'price',
        'sale_price',
        'stock_quantity',
        'width',
        'height',
        'depth',
        'weight',
        'sku',
        'is_active'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'depth' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    /**
     * Основной товар
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Значения атрибутов вариации
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ShopVariationAttributeValue::class,
            'shop_variation_attributes_values'
        );
    }

    /**
     * Изображения вариации
     */
    public function images(): HasMany
    {
        return $this->hasMany(ShopGoodImage::class, 'variation_id');
    }

    /**
     * Видео вариации
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ShopGoodVideo::class, 'variation_id');
    }

    /**
     * Остатки вариации
     */
    public function stock(): HasMany
    {
        return $this->hasMany(ShopStock::class, 'variation_id');
    }

    /**
     * Цены вариации
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ShopGoodPrice::class, 'variation_id');
    }

    /**
     * Scope для активных вариаций
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
        return $query->orderBy('name');
    }

    /**
     * Получить финальную цену (с учетом скидки)
     */
    public function getFinalPriceAttribute()
    {
        return $this->sale_price ?: $this->price;
    }

    /**
     * Получить размер скидки в процентах
     */
    public function getDiscountPercentAttribute()
    {
        if (!$this->sale_price || $this->sale_price >= $this->price) {
            return 0;
        }
        
        return round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /**
     * Получить название вариации (если не задано, то из основного товара)
     */
    public function getDisplayNameAttribute()
    {
        return $this->name ?: $this->good->name;
    }

    /**
     * Получить габариты в виде строки
     */
    public function getDimensionsAttribute()
    {
        $dimensions = [];
        if ($this->width) $dimensions[] = $this->width . '×';
        if ($this->height) $dimensions[] = $this->height . '×';
        if ($this->depth) $dimensions[] = $this->depth;
        
        return implode('', $dimensions) ?: null;
    }

    /**
     * Получить атрибуты вариации в виде строки
     */
    public function getAttributesStringAttribute()
    {
        return $this->attributeValues->map(function ($value) {
            return $value->attribute->name . ': ' . $value->value;
        })->join(', ');
    }
}
