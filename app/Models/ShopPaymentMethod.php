<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'is_active',
        'description',
        'settings',
        'sort_order',
        'is_default',
        'can_disable_default'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'is_default' => 'boolean',
        'can_disable_default' => 'boolean'
    ];

    /**
     * Scope для активных способов оплаты
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Получить способ оплаты по умолчанию
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Получить настройки API
     */
    public function getApiSettings()
    {
        return $this->settings ?? [];
    }

    /**
     * Проверить, можно ли отключить способ по умолчанию
     */
    public function canBeDisabled()
    {
        if (!$this->is_default) {
            return true;
        }

        if (!$this->can_disable_default) {
            return false;
        }

        // Проверяем, есть ли другие активные способы оплаты
        return static::where('id', '!=', $this->id)
            ->where('is_active', true)
            ->exists();
    }
}
