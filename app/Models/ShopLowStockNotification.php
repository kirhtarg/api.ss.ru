<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopLowStockNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'warehouse_id',
        'current_quantity',
        'min_quantity',
        'is_sent',
        'sent_at',
    ];

    protected $casts = [
        'current_quantity' => 'integer',
        'min_quantity' => 'integer',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
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
     * Scope для отправленных уведомлений
     */
    public function scopeSent($query)
    {
        return $query->where('is_sent', true);
    }

    /**
     * Scope для неотправленных уведомлений
     */
    public function scopeNotSent($query)
    {
        return $query->where('is_sent', false);
    }
}
