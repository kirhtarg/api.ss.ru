<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Связь с категориями товаров
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ShopCategory::class, 'promocode_categories', 'promocode_id', 'category_id');
    }

    /**
     * Связь с товарами
     */
    public function goods(): BelongsToMany
    {
        return $this->belongsToMany(ShopGood::class, 'promocode_goods', 'promocode_id', 'good_id');
    }

    /**
     * Связь с пользователями (персональные промокоды)
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'promocode_users', 'promocode_id', 'user_id');
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
     * Проверяет общий лимит использований по таблице promocode_usage
     */
    public function canBeUsed(): array
    {
        $errors = [];

        if (!$this->isActive()) {
            if (!$this->is_active) {
                $errors[] = "Промокод неактивен";
            } else {
                $now = Carbon::now();
                if ($this->starts_at && $now->lt($this->starts_at)) {
                    $errors[] = "Промокод еще не начал действовать";
                }
                if ($this->expires_at && $now->gt($this->expires_at)) {
                    $errors[] = "Срок действия промокода истек";
                }
            }
            return [
                'can_use' => false,
                'errors' => $errors
            ];
        }

        // Проверяем общий лимит использований по таблице promocode_usage
        if ($this->usage_limit) {
            $totalUsageCount = $this->usages()->count();
            if ($totalUsageCount >= $this->usage_limit) {
                $errors[] = "Лимит использований промокода исчерпан ({$this->usage_limit} из {$this->usage_limit})";
                return [
                    'can_use' => false,
                    'errors' => $errors
                ];
            }
        }

        return [
            'can_use' => true,
            'errors' => []
        ];
    }

    /**
     * Проверка, можно ли использовать промокод пользователем
     * Проверяет лимит использований на пользователя по таблице promocode_usage
     */
    public function canBeUsedByUser(?int $userId = null, ?string $sessionId = null): array
    {
        $generalCheck = $this->canBeUsed();
        if (!$generalCheck['can_use']) {
            return $generalCheck;
        }

        $errors = [];

        if (!$this->usage_limit_per_user) {
            return [
                'can_use' => true,
                'errors' => []
            ];
        }

        if (!$userId && !$sessionId) {
            $errors[] = "Необходима авторизация для использования этого промокода";
            return [
                'can_use' => false,
                'errors' => $errors
            ];
        }

        $query = $this->usages();
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId);
        }

        $userUsageCount = $query->count();

        if ($userUsageCount >= $this->usage_limit_per_user) {
            $errors[] = "Вы уже использовали этот промокод максимальное количество раз ({$this->usage_limit_per_user})";
            return [
                'can_use' => false,
                'errors' => $errors
            ];
        }

        return [
            'can_use' => true,
            'errors' => []
        ];
    }

    /**
     * Проверка, применим ли промокод к заказу
     */
    public function isApplicableToOrder(array $cartItems, float $orderAmount, ?int $userId = null): array
    {
        $errors = [];

        // Проверка минимальной суммы заказа
        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            $errors[] = "Минимальная сумма заказа для этого промокода: " . number_format($this->min_order_amount, 2, '.', ' ') . " ₽";
        }

        // Проверка персональных промокодов
        $allowedUsersCount = $this->users()->count();
        if ($allowedUsersCount > 0) {
            if (!$userId) {
                \Illuminate\Support\Facades\Log::warning('Promocode user check failed: no userId', [
                    'promocode_id' => $this->id,
                ]);
                $errors[] = "Этот промокод доступен только для определенных пользователей";
            } else {
                // Проверяем, есть ли пользователь в списке разрешенных
                // Используем более простой способ - загружаем коллекцию и проверяем через contains
                $allowedUsers = $this->users()->get();
                $allowedUserIds = $allowedUsers->pluck('id')->map(function($id) {
                    return (int)$id;
                })->toArray();
                
                // Приводим userId к int для корректного сравнения
                $userIdInt = (int)$userId;
                
                // Логирование для отладки
                \Illuminate\Support\Facades\Log::info('Promocode user check', [
                    'promocode_id' => $this->id,
                    'user_id' => $userId,
                    'user_id_type' => gettype($userId),
                    'user_id_int' => $userIdInt,
                    'allowed_users_count' => $allowedUsers->count(),
                    'allowed_user_ids' => $allowedUserIds,
                    'allowed_user_ids_types' => array_map('gettype', $allowedUserIds),
                    'user_in_array' => in_array($userIdInt, $allowedUserIds, true),
                    'user_in_collection' => $allowedUsers->contains('id', $userIdInt),
                ]);
                
                // Проверяем через contains для более надежной работы
                $userExists = $allowedUsers->contains('id', $userIdInt);
                if (!$userExists) {
                    \Illuminate\Support\Facades\Log::warning('Promocode user check failed: user not in allowed list', [
                        'promocode_id' => $this->id,
                        'user_id' => $userIdInt,
                        'allowed_user_ids' => $allowedUserIds,
                    ]);
                    $errors[] = "Этот промокод доступен только для определенных пользователей";
                }
            }
        }

        // Проверка категорий товаров (используем отношения many-to-many)
        $categoryIds = $this->categories()->pluck('category_id')->toArray();
        if (count($categoryIds) > 0) {
            $hasApplicableCategory = false;
            foreach ($cartItems as $item) {
                if (isset($item['categories']) && is_array($item['categories'])) {
                    foreach ($item['categories'] as $categoryId) {
                        if (in_array($categoryId, $categoryIds)) {
                            $hasApplicableCategory = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$hasApplicableCategory) {
                $errors[] = "Промокод применим только к товарам из определенных категорий";
            }
        }

        // Проверка конкретных товаров (используем отношения many-to-many)
        $goodIds = $this->goods()->pluck('good_id')->toArray();
        if (count($goodIds) > 0) {
            $hasApplicableGood = false;
            foreach ($cartItems as $item) {
                if (in_array($item['good_id'], $goodIds)) {
                    $hasApplicableGood = true;
                    break;
                }
            }
            if (!$hasApplicableGood) {
                $errors[] = "Промокод применим только к определенным товарам";
            }
        }

        // Проверка конкретных вариаций (оставляем JSON поле для обратной совместимости)
        if ($this->applicable_variations) {
            $hasApplicableVariation = false;
            foreach ($cartItems as $item) {
                if (isset($item['variation_id']) && in_array($item['variation_id'], $this->applicable_variations)) {
                    $hasApplicableVariation = true;
                    break;
                }
            }
            if (!$hasApplicableVariation) {
                $errors[] = "Промокод применим только к определенным вариациям товаров";
            }
        }

        return [
            'is_applicable' => count($errors) === 0,
            'errors' => $errors
        ];
    }

    /**
     * Расчет скидки
     */
    public function calculateDiscount(float $orderAmount, array $cartItems = [], ?int $userId = null): array
    {
        $applicability = $this->isApplicableToOrder($cartItems, $orderAmount, $userId);
        if (!$applicability['is_applicable']) {
            return [
                'discount' => 0,
                'errors' => $applicability['errors']
            ];
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
                // max_discount_amount будет использоваться для ограничения стоимости доставки
                $discount = 0;
                break;
        }

        // Ограничение максимальной скидки (для percentage и fixed_amount)
        if ($this->max_discount_amount && $this->type !== 'free_delivery' && $discount > $this->max_discount_amount) {
            $discount = $this->max_discount_amount;
        }

        // Скидка не может быть больше суммы заказа
        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return [
            'discount' => round($discount, 2),
            'errors' => []
        ];
    }

    /**
     * Создание записи об использовании промокода
     * Вместо простого инкремента создаем запись в таблице promocode_usage
     */
    public function recordUsage(?int $userId = null, ?string $sessionId = null, ?int $orderId = null, float $discountAmount = 0, ?array $appliedTo = null): PromocodeUsage
    {
        // Обновляем счетчик для обратной совместимости
        $this->increment('used_count');

        // Создаем запись в таблице использования
        return PromocodeUsage::create([
            'promocode_id' => $this->id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
            'applied_to' => $appliedTo,
            'used_at' => Carbon::now(),
        ]);
    }

    /**
     * Получить количество использований из таблицы promocode_usage
     */
    public function getUsageCount(): int
    {
        return $this->usages()->count();
    }

    /**
     * Получить количество использований пользователем
     */
    public function getUserUsageCount(?int $userId = null, ?string $sessionId = null): int
    {
        $query = $this->usages();
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId);
        } else {
            return 0;
        }

        return $query->count();
    }

    /**
     * Аксессор для получения актуального количества использований из таблицы promocode_usage
     * Используется для отображения в админке
     */
    public function getActualUsedCountAttribute(): int
    {
        return $this->usages()->count();
    }
}
