<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopGoodPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'price_type_id',
        'price',
        'sale_price',
        'valid_from',
        'valid_until'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime'
    ];

    /**
     * Товар
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Вариация товара
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ShopGoodVariation::class, 'variation_id');
    }

    /**
     * Тип цены
     */
    public function priceType(): BelongsTo
    {
        return $this->belongsTo(ShopPriceType::class, 'price_type_id');
    }

    /**
     * Scope для активных цен
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_from')
              ->orWhere('valid_from', '<=', now());
        })->where(function ($q) {
            $q->whereNull('valid_until')
              ->orWhere('valid_until', '>=', now());
        });
    }

    /**
     * Получить финальную цену (с учетом скидки)
     */
    public function getFinalPriceAttribute()
    {
        return $this->sale_price ?: $this->price;
    }
}
