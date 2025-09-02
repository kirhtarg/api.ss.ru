<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'warehouse_id',
        'quantity',
        'reserved_quantity',
        'min_quantity'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'min_quantity' => 'integer'
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
     * Склад
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(ShopWarehouse::class, 'warehouse_id');
    }

    /**
     * Получить доступное количество (общее - зарезервированное)
     */
    public function getAvailableQuantityAttribute()
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    /**
     * Проверить, есть ли товар в наличии
     */
    public function getInStockAttribute()
    {
        return $this->available_quantity > 0;
    }

    /**
     * Проверить, низкий ли остаток
     */
    public function getLowStockAttribute()
    {
        return $this->quantity <= $this->min_quantity;
    }

    /**
     * Scope для товаров в наличии
     */
    public function scopeInStock($query)
    {
        return $query->whereRaw('quantity > reserved_quantity');
    }

    /**
     * Scope для товаров с низким остатком
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('quantity <= min_quantity');
    }
}
