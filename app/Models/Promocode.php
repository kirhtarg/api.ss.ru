<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Promocode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'min_order_amount',
        'max_discount_amount',
        'usage_limit',
        'used_count',
        'usage_limit_per_user',
        'is_active',
        'starts_at',
        'expires_at',
        'applicable_categories',
        'applicable_goods',
        'applicable_variations',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'applicable_categories' => 'array',
        'applicable_goods' => 'array',
        'applicable_variations' => 'array',
    ];

    /**
     * Связь с использованием промокодов
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromocodeUsage::class);
    }

    /**
     * Проверка, активен ли промокод
     */
    public function isActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = Carbon::now();
        
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && $now->gt($this->expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Проверка, можно ли использовать промокод
     */
    public function canBeUsed(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * Проверка, можно ли использовать промокод пользователем
     */
    public function canBeUsedByUser(?int $userId = null, ?string $sessionId = null): bool
    {
        if (!$this->canBeUsed()) {
            return false;
        }

        if (!$this->usage_limit_per_user) {
            return true;
        }

        $query = $this->usages();
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId);
        } else {
            return false;
        }

        $userUsageCount = $query->count();

        return $userUsageCount < $this->usage_limit_per_user;
    }

    /**
     * Проверка, применим ли промокод к заказу
     */
    public function isApplicableToOrder(array $cartItems, float $orderAmount): bool
    {
        // Проверка минимальной суммы заказа
        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            return false;
        }

        // Проверка категорий товаров
        if ($this->applicable_categories) {
            $hasApplicableCategory = false;
            foreach ($cartItems as $item) {
                if (isset($item['categories']) && is_array($item['categories'])) {
                    foreach ($item['categories'] as $categoryId) {
                        if (in_array($categoryId, $this->applicable_categories)) {
                            $hasApplicableCategory = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$hasApplicableCategory) {
                return false;
            }
        }

        // Проверка конкретных товаров
        if ($this->applicable_goods) {
            $hasApplicableGood = false;
            foreach ($cartItems as $item) {
                if (in_array($item['good_id'], $this->applicable_goods)) {
                    $hasApplicableGood = true;
                    break;
                }
            }
            if (!$hasApplicableGood) {
                return false;
            }
        }

        // Проверка конкретных вариаций
        if ($this->applicable_variations) {
            $hasApplicableVariation = false;
            foreach ($cartItems as $item) {
                if (isset($item['variation_id']) && in_array($item['variation_id'], $this->applicable_variations)) {
                    $hasApplicableVariation = true;
                    break;
                }
            }
            if (!$hasApplicableVariation) {
                return false;
            }
        }

        return true;
    }

    /**
     * Расчет скидки
     */
    public function calculateDiscount(float $orderAmount, array $cartItems = []): float
    {
        if (!$this->isApplicableToOrder($cartItems, $orderAmount)) {
            return 0;
        }

        $discount = 0;

        switch ($this->type) {
            case 'percentage':
                $discount = $orderAmount * ($this->value / 100);
                break;
            case 'fixed_amount':
                $discount = $this->value;
                break;
            case 'free_delivery':
                // Для бесплатной доставки возвращаем 0, логика обработки в контроллере
                $discount = 0;
                break;
        }

        // Ограничение максимальной скидки
        if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
            $discount = $this->max_discount_amount;
        }

        // Скидка не может быть больше суммы заказа
        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return round($discount, 2);
    }

    /**
     * Увеличение счетчика использований
     */
    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }
}
