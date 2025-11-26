<?php

namespace App\Http\Controllers\Auth\Traits;

use App\Models\User;
use App\Models\UserBonus;
use Illuminate\Support\Facades\Log;

trait AwardsWelcomeBonuses
{
    /**
     * Начислить приветственные бонусы новому пользователю
     * Бонусы начисляются только если у пользователя еще нет записей о бонусах
     * 
     * @return int Сумма начисленных бонусов (0 если не начислялись)
     */
    protected function awardWelcomeBonuses(User $user): int
    {
        try {
            // Проверяем, есть ли уже у пользователя бонусы (если есть - это не первая регистрация)
            $existingBonus = UserBonus::where('user_id', $user->id)->first();
            
            if ($existingBonus) {
                Log::info('Welcome bonuses not awarded: user already has bonus record', [
                    'user_id' => $user->id
                ]);
                return 0; // У пользователя уже есть бонусы, не начисляем повторно
            }
            
            // Получаем значение bonuses_at_reg из настроек
            $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();
            
            if (!$bonusesAtReg || !$bonusesAtReg->value || $bonusesAtReg->value <= 0) {
                Log::info('Welcome bonuses not awarded: setting not found or value is 0', [
                    'user_id' => $user->id,
                    'setting_value' => $bonusesAtReg?->value ?? 'not found'
                ]);
                return 0; // Если настройка не найдена или равна 0, не начисляем бонусы
            }
            
            $bonusAmount = (int) $bonusesAtReg->value;
            
            // Используем модель UserBonus для правильного создания записи и транзакции
            $userBonus = UserBonus::getOrCreateForUser($user->id);
            
            // Добавляем бонусы через метод addPoints, который создаст транзакцию
            $userBonus->addPoints(
                $bonusAmount,
                'Приветственные бонусы за регистрацию',
                null, // order_id
                null, // expires_at (без срока действия для приветственных бонусов)
                ['registration_bonus' => true, 'bonuses_at_reg' => $bonusAmount]
            );
            
            Log::info('Welcome bonuses awarded successfully', [
                'user_id' => $user->id,
                'bonus_amount' => $bonusAmount
            ]);
            
            return $bonusAmount;
        } catch (\Exception $e) {
            Log::error('Error awarding welcome bonuses', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Не прерываем процесс регистрации, если начисление бонусов не удалось
            return 0;
        }
    }
}

