<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopStockReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'warehouse_id',
        'quantity',
        'reserved_until',
        'reservation_type',
        'reference_id',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_until' => 'datetime',
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
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope для активных резервирований
     */
    public function scopeActive($query)
    {
        return $query->where('reserved_until', '>', now());
    }

    /**
     * Scope для истекших резервирований
     */
    public function scopeExpired($query)
    {
        return $query->where('reserved_until', '<=', now());
    }

    /**
     * Scope для резервирований по типу
     */
    public function scopeByType($query, $type)
    {
        return $query->where('reservation_type', $type);
    }
}
