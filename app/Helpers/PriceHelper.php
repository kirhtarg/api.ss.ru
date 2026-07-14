<?php

namespace App\Helpers;

use App\Models\Setting;

class PriceHelper
{
    private static ?int $priceRoundDigits = null;

    private static ?bool $round10Enabled = null;

    /**
     * Получить количество знаков после запятой для округления цен
     */
    public static function getPriceRoundDigits(): int
    {
        if (self::$priceRoundDigits !== null) {
            return self::$priceRoundDigits;
        }

        $setting = Setting::where('key', 'shop_price_round')->first();
        if ($setting && $setting->value !== null && $setting->value !== '') {
            return self::$priceRoundDigits = (int) $setting->value;
        }

        return self::$priceRoundDigits = 2; // По умолчанию 2 знака
    }

    /**
     * Проверить, активно ли округление до 10 рублей
     */
    public static function isRound10Enabled(): bool
    {
        if (self::$round10Enabled !== null) {
            return self::$round10Enabled;
        }

        $setting = Setting::where('key', 'shop_round10')->first();

        return self::$round10Enabled = (bool) ($setting && ($setting->value == 1 || $setting->value == '1'));
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
     * Округлить скидку/бонус согласно настройкам.
     *
     * При округлении цен до 10 рублей скидки округляются вниз, чтобы скидка
     * не становилась больше расчетной.
     */
    public static function roundDiscount(float $discount): float
    {
        if ($discount <= 0) {
            return 0;
        }

        if (self::isRound10Enabled()) {
            return floor($discount / 10) * 10;
        }

        return self::roundPrice($discount);
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
