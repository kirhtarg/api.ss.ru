<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopNotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'email',
        'telegram_chat_id',
        'telegram_bot_token',
        'telegram_bot_username',
        'is_active',
        'settings',
        'description'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array'
    ];

    /**
     * События оповещений для этого канала
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShopNotificationEvent::class, 'channel_id');
    }

    /**
     * Проверить, включено ли событие для этого канала
     */
    public function isEventEnabled(string $eventType): bool
    {
        return $this->events()
            ->where('event_type', $eventType)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Scope для активных каналов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для каналов по типу
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Получить каналы для конкретного события
     */
    public static function getChannelsForEvent(string $eventType): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
            ->whereHas('events', function ($query) use ($eventType) {
                $query->where('event_type', $eventType)
                    ->where('is_enabled', true);
            })
            ->get();
    }
}

