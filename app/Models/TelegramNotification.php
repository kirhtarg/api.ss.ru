<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'order_id',
        'chat_id',
        'message',
        'data',
        'status',
        'error_message',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'sent_at' => 'datetime',
    ];

    /**
     * Заказ
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Scope для ожидающих уведомлений
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope для отправленных уведомлений
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope для неудачных уведомлений
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Отметить уведомление как отправленное
     */
    public function markAsSent()
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Отметить уведомление как неудачное
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Увеличить количество попыток
     */
    public function incrementAttempts()
    {
        $this->increment('attempts');
    }
}
