<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopNotificationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'event_type',
        'is_enabled',
        'template',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'template' => 'array',
    ];

    /**
     * Канал оповещений
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ShopNotificationChannel::class, 'channel_id');
    }

    /**
     * Scope для включенных событий
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope для конкретного типа события
     */
    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Получить название события
     */
    public function getEventNameAttribute(): string
    {
        return match ($this->event_type) {
            'order_created' => 'Заказ создан',
            'cancellation_request' => 'Заявка на отмену оплаченного заказа',
            'order_cancelled' => 'Заказ отменен пользователем',
            'preorder_created' => 'Предзаказ товара',
            'site_message' => 'Сообщение на сайте',
            default => $this->event_type
        };
    }
}
