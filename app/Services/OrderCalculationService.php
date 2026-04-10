<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\User;
use App\Models\UserBonus;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class OrderCalculationService
{
    /**
     * Рассчитать финальную цену единицы товара с учетом всех условий
     */
    public function calculateFinalUnitPrice(ShopGood $good, ?ShopGoodVariation $variation = null, ?User $user = null): array
    {
        $basePrice = $variation ? ($variation->price > 0 ? $variation->price : $good->price) : $good->price;
        $salePrice = $variation ? ($variation->sale_price > 0 ? $variation->sale_price : $good->sale_price) : $good->sale_price;
        
        $finalPrice = $basePrice;
        $isDemping = (bool)($good->is_demping ?? false);

        // Если есть акционная цена, она приоритетнее базовой
        if ($salePrice > 0) {
            $finalPrice = $salePrice;
        }

        // Если стоит флаг демпинга, цена может быть фиксированной (обычно sale_price используется для демпинга)
        // В этом проекте логика checkout.vue приоритезирует sale_price если он есть.

        $result = [
            'base_price' => (float)$basePrice,
            'sale_price' => (float)$salePrice,
            'final_price' => (float)$finalPrice,
            'is_demping' => $isDemping,
            'discounts' => []
        ];

        // Расчет дополнительных скидок для зарегистрированных пользователей
        if ($user && !$isDemping && $salePrice <= 0) {
            // Скидка 5% для зарегистрированных (пример из логики проекта)
            $regDiscountPercent = 5;
            $regDiscountAmount = round($finalPrice * ($regDiscountPercent / 100));
            
            $result['discounts']['registered_user'] = [
                'percent' => $regDiscountPercent,
                'amount' => $regDiscountAmount
            ];
            
            // Проверка на день рождения (скидка 10% вместо 5%)
            if ($this->isUserBirthday($user)) {
                $birthdayDiscountPercent = 10;
                $birthdayDiscountAmount = round($basePrice * ($birthdayDiscountPercent / 100));
                
                // Заменяем обычную скидку на именинную, если она больше
                $result['discounts']['birthday'] = [
                    'percent' => $birthdayDiscountPercent,
                    'amount' => $birthdayDiscountAmount
                ];
                // Убираем обычную, так как они не суммируются обычно
                unset($result['discounts']['registered_user']);
                $finalPrice -= $birthdayDiscountAmount;
            } else {
                $finalPrice -= $regDiscountAmount;
            }
        }

        $result['final_price'] = (float)$finalPrice;

        return $result;
    }

    /**
     * Рассчитать бонусы для элемента корзины
     */
    public function calculateItemBonuses(array $calculationResult, ?User $user = null): int
    {
        // Без регистрации бонусы не начисляются
        if (!$user) {
            return 0;
        }

        $settings = \App\Models\ShopBonusSettings::getSettingsForUser($user);
        
        // Если цена акционная или стоит флаг демпинга
        $isSale = ($calculationResult['sale_price'] > 0) || ($calculationResult['is_demping'] ?? false);

        return $settings->calculateOrderBonus($calculationResult['final_price'], $isSale);
    }

    /**
     * Проверка на день рождения пользователя
     */
    private function isUserBirthday(User $user): bool
    {
        if (!$user->birthday) return false;
        
        $birthday = is_string($user->birthday) ? new \DateTime($user->birthday) : $user->birthday;
        $today = new \DateTime();
        
        return $birthday->format('m-d') === $today->format('m-d');
    }

    /**
     * Рассчитать доступные к списанию бонусы для всей корзины
     */
    public function calculateMaxBonusesToUse(float $totalAmount, int $userPoints): int
    {
        $maxUsagePercentSetting = Setting::where('key', 'max_bonus_usage_percentage')->first();
        $maxPercent = $maxUsagePercentSetting ? (int)$maxUsagePercentSetting->value : 30;

        $maxAllowedByPrice = (int)floor($totalAmount * ($maxPercent / 100));
        
        return min($userPoints, $maxAllowedByPrice);
    }
}
