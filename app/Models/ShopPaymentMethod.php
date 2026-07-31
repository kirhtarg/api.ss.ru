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
        'can_disable_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'is_default' => 'boolean',
        'can_disable_default' => 'boolean',
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
        $settings = $this->settings ?? [];
        \Log::debug('ShopPaymentMethod API settings requested', [
            'payment_method_id' => $this->id,
            'payment_method_type' => $this->type,
            'settings_count' => count($settings),
        ]);

        return $settings;
    }

    /**
     * Проверить, можно ли отключить способ по умолчанию
     */
    public function canBeDisabled()
    {
        if (! $this->is_default) {
            return true;
        }

        if (! $this->can_disable_default) {
            return false;
        }

        // Проверяем, есть ли другие активные способы оплаты
        return static::where('id', '!=', $this->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get the image URL for this payment method
     */
    public function getImageUrlAttribute()
    {
        try {
            if (! $this->id) {
                return null;
            }

            $imagePath = '/images/payment/payment_'.$this->id.'.jpg';

            // Проверяем наличие файла в public фронтенда (из FRONTEND_PATH в .env)
            $fullPath = frontend_public_path(ltrim($imagePath, '/'));

            if ($fullPath && file_exists($fullPath)) {
                return $imagePath;
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting payment method image URL: '.$e->getMessage());

            return null;
        }
    }
}
