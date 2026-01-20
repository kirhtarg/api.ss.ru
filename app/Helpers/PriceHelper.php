<?php

namespace App\Helpers;

use App\Models\Setting;

class PriceHelper
{
    /**
     * Получить количество знаков после запятой для округления цен
     */
    public static function getPriceRoundDigits(): int
    {
        $setting = Setting::where('key', 'shop_price_round')->first();
        if ($setting && $setting->value !== null && $setting->value !== '') {
            return (int)$setting->value;
        }
        return 2; // По умолчанию 2 знака
    }

    /**
     * Проверить, активно ли округление до 10 рублей
     */
    public static function isRound10Enabled(): bool
    {
        $setting = Setting::where('key', 'shop_round10')->first();
        return $setting && ($setting->value == 1 || $setting->value == '1');
    }

    /**
     * Округлить цену согласно настройкам
     */
    public static function roundPrice(float $price): float
    {
        // Сначала проверяем округление до 10 рублей
        if (self::isRound10Enabled()) {
            // Округляем до ближайшего числа, кратного 10, в большую сторону
            return ceil($price / 10) * 10;
        }

        // Иначе используем обычное округление по знакам после запятой
        $digits = self::getPriceRoundDigits();
        $multiplier = pow(10, $digits);
        return round($price * $multiplier) / $multiplier;
    }

    /**
     * Форматировать цену с правильным количеством знаков после запятой
     */
    public static function formatPrice(float $price, string $separator = ',', string $thousandsSeparator = ' '): string
    {
        $digits = self::getPriceRoundDigits();
        return number_format($price, $digits, $separator, $thousandsSeparator);
    }
}

