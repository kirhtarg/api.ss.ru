<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method_id',
        'status',
        'amount',
        'transaction_id',
        'request_data',
        'response_data',
        'error_message',
        'processed_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_data' => 'array',
        'response_data' => 'array',
        'processed_at' => 'datetime'
    ];

    /**
     * Заказ
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Способ оплаты
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(ShopPaymentMethod::class);
    }

    /**
     * Scope для успешных транзакций
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope для неудачных транзакций
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope для ожидающих транзакций
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Отметить транзакцию как успешную
     */
    public function markAsSuccessful($transactionId = null, $responseData = null)
    {
        $this->update([
            'status' => 'success',
            'transaction_id' => $transactionId,
            'response_data' => $responseData,
            'processed_at' => now()
        ]);
    }

    /**
     * Отметить транзакцию как неудачную
     */
    public function markAsFailed($errorMessage, $responseData = null)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'response_data' => $responseData,
            'processed_at' => now()
        ]);
    }
}
