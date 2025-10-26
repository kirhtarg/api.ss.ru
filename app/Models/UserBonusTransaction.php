<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBonusTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'points',
        'description',
        'order_id',
        'expires_at',
        'metadata'
    ];

    protected $casts = [
        'points' => 'integer',
        'expires_at' => 'date',
        'metadata' => 'array'
    ];

    /**
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Заказ
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Scope для начислений
     */
    public function scopeEarned($query)
    {
        return $query->where('type', 'earn');
    }

    /**
     * Scope для трат
     */
    public function scopeSpent($query)
    {
        return $query->where('type', 'spend');
    }

    /**
     * Scope для истечения
     */
    public function scopeExpired($query)
    {
        return $query->where('type', 'expire');
    }

    /**
     * Scope для возвратов
     */
    public function scopeRefunded($query)
    {
        return $query->where('type', 'refund');
    }

    /**
     * Scope для активных бонусов
     */
    public function scopeActive($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope для истекших бонусов по дате
     */
    public function scopeExpiredByDate($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Получить знак для отображения
     */
    public function getSignAttribute(): string
    {
        return $this->points > 0 ? '+' : '';
    }

    /**
     * Получить цвет для отображения
     */
    public function getColorAttribute(): string
    {
        return match($this->type) {
            'earn' => 'green',
            'spend' => 'red',
            'expire' => 'gray',
            'refund' => 'blue',
            default => 'gray'
        };
    }

    /**
     * Получить иконку для отображения
     */
    public function getIconAttribute(): string
    {
        return match($this->type) {
            'earn' => 'mdi:plus-circle',
            'spend' => 'mdi:minus-circle',
            'expire' => 'mdi:clock-outline',
            'refund' => 'mdi:refresh',
            default => 'mdi:circle'
        };
    }
}
