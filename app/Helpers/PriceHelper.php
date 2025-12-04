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
     * Округлить цену согласно настройке shop_price_round
     */
    public static function roundPrice(float $price): float
    {
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

