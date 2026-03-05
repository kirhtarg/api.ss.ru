<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPreorder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'good_id',
        'variation_id',
        'quantity',
        'price',
        'total',
        'good_name',
        'variation_name',
        'good_sku',
        'good_image',
        'status',
        'notes',
        'customer_name',
        'customer_email',
        'customer_phone',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /**
     * Пользователь, создавший предзаказ
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
     * Scope для предзаказов пользователя
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для предзаказов по сессии
     */
    public function scopeForSession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope для активных предзаказов
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'confirmed']);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Пересчитать общую сумму позиции
     */
    public function recalculateTotal()
    {
        $this->total = $this->price * $this->quantity;
        $this->save();
    }

    /**
     * Получить уникальный ключ для позиции
     */
    public function getUniqueKeyAttribute()
    {
        return $this->good_id.'_'.($this->variation_id ?? 'main');
    }
}
