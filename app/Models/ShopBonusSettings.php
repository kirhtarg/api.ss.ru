<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopBonusSettings extends Model
{
    use HasFactory;

    protected $table = 'shop_bonus_settings';

    protected $fillable = [
        'name',
        'regular_price_percentage',
        'sale_price_percentage',
        'max_usage_percentage',
        'is_active',
        'min_order_amount',
        'min_purchase_amount',
        'min_bonus_amount',
        'max_bonus_amount',
        'bonus_expiry_days',
        'metadata'
    ];

    protected $casts = [
        'regular_price_percentage' => 'decimal:2',
        'sale_price_percentage' => 'decimal:2',
        'max_usage_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'min_order_amount' => 'integer',
        'min_purchase_amount' => 'decimal:2',
        'min_bonus_amount' => 'integer',
        'max_bonus_amount' => 'integer',
        'bonus_expiry_days' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Получить активные настройки бонусов
     */
    public static function getActiveSettings()
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Получить настройки по умолчанию
     */
    public static function getDefaultSettings()
    {
        return static::firstOrCreate(
            ['name' => 'Основная бонусная система'],
            [
                'regular_price_percentage' => 5.00,
                'sale_price_percentage' => 2.50,
                'max_usage_percentage' => 50.00,
                'is_active' => true,
                'min_order_amount' => 0,
                'min_bonus_amount' => 1,
                'max_bonus_amount' => null,
                'bonus_expiry_days' => 365,
                'metadata' => []
            ]
        );
    }

    /**
     * Рассчитать бонусы для заказа
     */
    public function calculateOrderBonus($orderAmount, $isSalePrice = false)
    {
        if ($orderAmount < $this->min_order_amount) {
            return 0;
        }

        $percentage = $isSalePrice ? $this->sale_price_percentage : $this->regular_price_percentage;
        return (int) round($orderAmount * ($percentage / 100));
    }

    /**
     * Рассчитать максимальную сумму списания бонусов
     */
    public function calculateMaxBonusUsage($orderAmount)
    {
        $maxAmount = (int) round($orderAmount * ($this->max_usage_percentage / 100));
        
        if ($this->max_bonus_amount && $maxAmount > $this->max_bonus_amount) {
            return $this->max_bonus_amount;
        }
        
        return max($this->min_bonus_amount, $maxAmount);
    }

    /**
     * Проверить, можно ли использовать бонусы для заказа
     */
    public function canUseBonuses($orderAmount, $bonusAmount)
    {
        if ($orderAmount < $this->min_order_amount) {
            return false;
        }

        if ($bonusAmount < $this->min_bonus_amount) {
            return false;
        }

        $maxUsage = $this->calculateMaxBonusUsage($orderAmount);
        return $bonusAmount <= $maxUsage;
    }
}
