<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'message',
        'type',
        'is_processed',
        'processed_at',
        'ip_address',
        'good_link',
        'good_price',
        'good_id'
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'processed_at' => 'datetime'
    ];

    /**
     * Scope для обратных звонков
     */
    public function scopeCallbacks($query)
    {
        return $query->where('type', 'callback');
    }

    /**
     * Scope для сообщений
     */
    public function scopeMessages($query)
    {
        return $query->where('type', 'message');
    }

    /**
     * Scope для "Нашел дешевле"
     */
    public function scopeFoundCheaper($query)
    {
        return $query->where('type', 'found_cheaper');
    }

    /**
     * Scope для необработанных
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope для обработанных
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Связь с товаром
     */
    public function good()
    {
        return $this->belongsTo(\App\Models\ShopGood::class, 'good_id');
    }
}
