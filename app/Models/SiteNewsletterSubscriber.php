<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteNewsletterSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'is_active',
        'subscribed_at',
        'unsubscribed_at',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime'
    ];

    /**
     * Scope для активных подписчиков
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для неактивных подписчиков
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
}
