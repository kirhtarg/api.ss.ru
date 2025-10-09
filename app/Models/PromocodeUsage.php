<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromocodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promocode_id',
        'user_id',
        'session_id',
        'order_id',
        'discount_amount',
        'applied_to',
        'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'applied_to' => 'array',
        'used_at' => 'datetime',
    ];

    /**
     * Связь с промокодом
     */
    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связь с заказом
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ShopOrder::class, 'order_id');
    }
}
