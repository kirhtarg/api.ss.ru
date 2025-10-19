<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'good_id',
        'variation_id',
        'quantity',
        'price',
        'sale_price',
        'total',
        'good_name',
        'variation_name',
        'good_sku',
        'good_image'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'total' => 'decimal:2',
        'quantity' => 'integer'
    ];

    /**
     * Пользователь, которому принадлежит элемент корзины
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
     * Scope для элементов корзины пользователя
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для элементов корзины по сессии
     */
    public function scopeForSession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope для активных элементов (не старше 30 дней)
     */
    public function scopeActive($query)
    {
        return $query->where('created_at', '>=', now()->subDays(30));
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
        // Используем акционную цену если есть, иначе обычную
        $finalPrice = ($this->sale_price && $this->sale_price > 0) ? $this->sale_price : $this->price;
        $this->total = $finalPrice * $this->quantity;
        $this->save();
    }

    /**
     * Получить уникальный ключ для позиции
     */
    public function getUniqueKeyAttribute()
    {
        return $this->good_id . '_' . ($this->variation_id ?? 'main');
    }
}
