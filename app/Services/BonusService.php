<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use App\Models\ShopOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BonusService
{
    /**
     * Получить баланс бонусов пользователя
     */
    public function getBonusBalance(User $user): float
    {
        
        try {
            $userBonus = UserBonus::firstOrCreate(['user_id' => $user->id]);
            return $userBonus->points;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Получить историю транзакций бонусов пользователя
     */
    public function getBonusTransactions(User $user)
    {
        return UserBonusTransaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Начислить бонусы за заказ
     */
    public function awardOrderBonuses(ShopOrder $order): void
    {
        if (!$order->user_id) {
            return; // Только для зарегистрированных пользователей
        }

        $userBonus = UserBonus::getOrCreateForUser($order->user_id);
        $settings = \App\Models\ShopBonusSettings::getActiveSettings();

        if (!$settings) {
            $settings = \App\Models\ShopBonusSettings::getDefaultSettings();
        }

        // Определяем, является ли цена акционной (упрощенная логика)
        $isSalePrice = $order->metadata['is_sale_price'] ?? false;
        
        $bonusPoints = $settings->calculateOrderBonus($order->total_amount, $isSalePrice);
        
        if ($bonusPoints > 0) {
            $expiresAt = Carbon::now()->addDays($settings->bonus_expiry_days);
            
            $userBonus->addPoints(
                $bonusPoints,
                "Начисление бонусов за заказ #{$order->order_number}",
                $order->id,
                $expiresAt,
                [
                    'order_amount' => $order->total_amount,
                    'bonus_rate' => $isSalePrice ? $settings->sale_price_percentage : $settings->regular_price_percentage,
                    'calculated_points' => $bonusPoints,
                    'is_sale_price' => $isSalePrice
                ]
            );
        }
    }

    /**
     * Начислить бонусы за регистрацию
     */
    public function awardRegistrationBonuses(int $userId): void
    {
        $userBonus = UserBonus::getOrCreateForUser($userId);
        
        $bonusPoints = 100; // 100 баллов за регистрацию
        $expiresAt = Carbon::now()->addYear();
        
        $userBonus->addPoints(
            $bonusPoints,
            'Бонусы за регистрацию',
            null,
            $expiresAt,
            ['registration_bonus' => true]
        );
    }

    /**
     * Начислить бонусы за отзыв
     */
    public function awardReviewBonuses(int $userId, int $orderId = null): void
    {
        $userBonus = UserBonus::getOrCreateForUser($userId);
        
        $bonusPoints = 50; // 50 баллов за отзыв
        $expiresAt = Carbon::now()->addYear();
        
        $userBonus->addPoints(
            $bonusPoints,
            'Бонусы за отзыв о товаре',
            $orderId,
            $expiresAt,
            ['review_bonus' => true]
        );
    }

    /**
     * Потратить бонусы на заказ
     */
    public function spendBonusesOnOrder(int $userId, int $pointsToSpend, ShopOrder $order): void
    {
        $userBonus = UserBonus::getOrCreateForUser($userId);
        
        if ($userBonus->getAvailablePoints() < $pointsToSpend) {
            throw new \Exception('Недостаточно бонусных баллов');
        }

        $userBonus->spendPoints(
            $pointsToSpend,
            "Использование бонусов для заказа #{$order->order_number}",
            $order->id,
            [
                'order_amount' => $order->total_amount,
                'bonus_discount' => $pointsToSpend
            ]
        );
    }

    /**
     * Вернуть бонусы при отмене заказа
     */
    public function refundOrderBonuses(ShopOrder $order): void
    {
        if (!$order->user_id) {
            return;
        }

        $userBonus = UserBonus::getOrCreateForUser($order->user_id);
        
        // Находим транзакции по этому заказу
        $spentTransactions = $userBonus->transactions()
            ->where('order_id', $order->id)
            ->where('type', 'spend')
            ->get();

        foreach ($spentTransactions as $transaction) {
            $refundPoints = abs($transaction->points);
            
            $userBonus->addPoints(
                $refundPoints,
                "Возврат бонусов за отмененный заказ #{$order->order_number}",
                $order->id,
                null,
                ['refund' => true, 'original_transaction_id' => $transaction->id]
            );
        }
    }

    /**
     * Получить доступные бонусы пользователя
     */
    public function getUserAvailableBonuses(int $userId): int
    {
        $userBonus = UserBonus::getOrCreateForUser($userId);
        $userBonus->cleanExpiredPoints(); // Очищаем истекшие бонусы
        
        return $userBonus->getAvailablePoints();
    }

    /**
     * Получить историю бонусов пользователя
     */
    public function getUserBonusHistory(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return UserBonusTransaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Получить статистику бонусов пользователя
     */
    public function getUserBonusStats(int $userId): array
    {
        $userBonus = UserBonus::getOrCreateForUser($userId);
        
        return [
            'current_points' => $userBonus->points,
            'available_points' => $userBonus->getAvailablePoints(),
            'total_earned' => $userBonus->total_earned,
            'total_spent' => $userBonus->total_spent,
            'expired_points' => $userBonus->total_earned - $userBonus->total_spent - $userBonus->points
        ];
    }

    /**
     * Очистить истекшие бонусы для всех пользователей
     */
    public function cleanAllExpiredBonuses(): int
    {
        $totalCleaned = 0;
        
        $userBonuses = UserBonus::where('points', '>', 0)->get();
        
        foreach ($userBonuses as $userBonus) {
            $cleaned = $userBonus->cleanExpiredPoints();
            $totalCleaned += $cleaned;
        }
        
        return $totalCleaned;
    }

    /**
     * Рассчитать максимальное количество бонусов для использования
     */
    public function calculateMaxUsableBonuses(int $userId, float $orderAmount): int
    {
        $availablePoints = $this->getUserAvailableBonuses($userId);
        
        // Можно использовать максимум 50% от суммы заказа бонусами
        $maxFromOrder = (int) round($orderAmount * 0.5);
        
        return min($availablePoints, $maxFromOrder);
    }

    /**
     * Проверить, можно ли использовать бонусы для заказа
     */
    public function canUseBonusesForOrder(int $userId, float $orderAmount): bool
    {
        $availablePoints = $this->getUserAvailableBonuses($userId);
        $minOrderAmount = 1000; // Минимальная сумма заказа для использования бонусов
        
        return $availablePoints > 0 && $orderAmount >= $minOrderAmount;
    }

    /**
     * Списывает бонусы с баланса пользователя
     */
    public function deductBonuses(int $userId, float $points): array
    {
        try {
            
            $userBonus = UserBonus::where('user_id', $userId)->first();
            
            if (!$userBonus) {
                return [
                    'success' => false,
                    'message' => 'У пользователя нет бонусного счета'
                ];
            }

            if ($userBonus->points < $points) {
                return [
                    'success' => false,
                    'message' => 'Недостаточно бонусов на счету'
                ];
            }

            // Списываем бонусы
            $userBonus->decrement('points', $points);
            
            // Создаем запись о транзакции
            UserBonusTransaction::create([
                'user_id' => $userId,
                'type' => 'deduction',
                'points' => -$points,
                'description' => 'Списание бонусов за заказ',
                'order_id' => null
            ]);


            return [
                'success' => true,
                'remaining_points' => $userBonus->fresh()->points
            ];

        } catch (\Exception $e) {
            
            return [
                'success' => false,
                'message' => 'Ошибка при списании бонусов: ' . $e->getMessage()
            ];
        }
    }
}
