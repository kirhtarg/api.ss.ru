<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserBonus extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'points',
        'total_earned',
        'total_spent',
    ];

    protected $casts = [
        'points' => 'integer',
        'total_earned' => 'integer',
        'total_spent' => 'integer',
    ];

    /**
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Транзакции бонусов
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(UserBonusTransaction::class, 'user_id', 'user_id');
    }

    /**
     * Добавить бонусы
     */
    public function addPoints(int $points, string $description, ?int $orderId = null, ?\DateTime $expiresAt = null, array $metadata = []): UserBonusTransaction
    {
        $this->points += $points;
        $this->total_earned += $points;
        $this->save();

        return $this->transactions()->create([
            'type' => 'earn',
            'points' => $points,
            'description' => $description,
            'order_id' => $orderId,
            'expires_at' => $expiresAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Потратить бонусы
     */
    public function spendPoints(int $points, string $description, ?int $orderId = null, array $metadata = []): UserBonusTransaction
    {
        if ($this->points < $points) {
            throw new \Exception('Недостаточно бонусных баллов');
        }

        $this->points -= $points;
        $this->total_spent += $points;
        $this->save();

        return $this->transactions()->create([
            'type' => 'spend',
            'points' => -$points,
            'description' => $description,
            'order_id' => $orderId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Получить или создать бонусы для пользователя
     */
    public static function getOrCreateForUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'points' => 0,
                'total_earned' => 0,
                'total_spent' => 0,
            ]
        );
    }

    /**
     * Получить доступные бонусы (исключая истекшие)
     */
    public function getAvailablePoints(): int
    {
        $expiredPoints = $this->transactions()
            ->where('type', 'earn')
            ->where('expires_at', '<', now())
            ->sum('points');

        return max(0, $this->points - $expiredPoints);
    }

    /**
     * Очистить истекшие бонусы
     */
    public function cleanExpiredPoints(): int
    {
        $expiredTransactions = $this->transactions()
            ->where('type', 'earn')
            ->where('expires_at', '<', now())
            ->where('points', '>', 0)
            ->get();

        $expiredPoints = 0;
        foreach ($expiredTransactions as $transaction) {
            $expiredPoints += $transaction->points;
            $this->points -= $transaction->points;
        }

        if ($expiredPoints > 0) {
            $this->save();

            // Создаем транзакцию истечения
            $this->transactions()->create([
                'type' => 'expire',
                'points' => -$expiredPoints,
                'description' => 'Истечение бонусных баллов',
                'metadata' => ['expired_count' => $expiredTransactions->count()],
            ]);
        }

        return $expiredPoints;
    }
}
