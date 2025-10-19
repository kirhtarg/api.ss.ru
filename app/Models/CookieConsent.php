<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CookieConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'ip_address',
        'user_agent',
        'necessary_cookies',
        'analytics_cookies',
        'marketing_cookies',
        'preferences_cookies',
        'consent_given_at',
        'expires_at',
    ];

    protected $casts = [
        'necessary_cookies' => 'boolean',
        'analytics_cookies' => 'boolean',
        'marketing_cookies' => 'boolean',
        'preferences_cookies' => 'boolean',
        'consent_given_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Проверить, есть ли активное согласие для сессии
     */
    public static function hasActiveConsent(string $sessionId): bool
    {
        return self::where('session_id', $sessionId)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Проверить, есть ли активное согласие для IP-адреса
     */
    public static function hasActiveConsentByIp(string $ipAddress): bool
    {
        return self::where('ip_address', $ipAddress)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Получить активное согласие для сессии
     */
    public static function getActiveConsent(string $sessionId): ?self
    {
        return self::where('session_id', $sessionId)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Получить активное согласие для IP-адреса
     */
    public static function getActiveConsentByIp(string $ipAddress): ?self
    {
        return self::where('ip_address', $ipAddress)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Создать новое согласие
     */
    public static function createConsent(array $data): self
    {
        // Устанавливаем время истечения на 1 год
        $expiresAt = now()->addYear();
        
        return self::create([
            'session_id' => $data['session_id'],
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'necessary_cookies' => $data['necessary_cookies'] ?? true,
            'analytics_cookies' => $data['analytics_cookies'] ?? false,
            'marketing_cookies' => $data['marketing_cookies'] ?? false,
            'preferences_cookies' => $data['preferences_cookies'] ?? false,
            'consent_given_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Обновить существующее согласие
     */
    public function updateConsent(array $data): self
    {
        $this->update([
            'necessary_cookies' => $data['necessary_cookies'] ?? $this->necessary_cookies,
            'analytics_cookies' => $data['analytics_cookies'] ?? $this->analytics_cookies,
            'marketing_cookies' => $data['marketing_cookies'] ?? $this->marketing_cookies,
            'preferences_cookies' => $data['preferences_cookies'] ?? $this->preferences_cookies,
            'consent_given_at' => now(),
            'expires_at' => now()->addYear(), // Обновляем срок действия
        ]);

        return $this;
    }

    /**
     * Проверить, истекло ли согласие
     */
    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    /**
     * Получить все разрешенные типы куки
     */
    public function getAllowedCookieTypes(): array
    {
        $types = [];
        
        if ($this->necessary_cookies) {
            $types[] = 'necessary';
        }
        if ($this->analytics_cookies) {
            $types[] = 'analytics';
        }
        if ($this->marketing_cookies) {
            $types[] = 'marketing';
        }
        if ($this->preferences_cookies) {
            $types[] = 'preferences';
        }

        return $types;
    }

    /**
     * Проверить, разрешен ли определенный тип куки
     */
    public function isCookieTypeAllowed(string $type): bool
    {
        return match ($type) {
            'necessary' => $this->necessary_cookies,
            'analytics' => $this->analytics_cookies,
            'marketing' => $this->marketing_cookies,
            'preferences' => $this->preferences_cookies,
            default => false,
        };
    }
}
