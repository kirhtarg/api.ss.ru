<?php

namespace App\Services;

use App\Models\ShopGood;

class ProductNameParser
{
    public function value(string $name, string $brand, string $field): string
    {
        if ($name === '' || $brand === '') return '';

        $brandIndex = stripos($name, $brand);
        if ($brandIndex === false) return '';

        if ($field === 'type') {
            return trim(substr($name, 0, $brandIndex));
        }

        if (! in_array($field, ['model', 'year'], true)) return '';

        $afterBrand = trim(substr($name, $brandIndex + strlen($brand)));
        if ($afterBrand === '') return '';

        $year = $this->year($afterBrand);
        if ($field === 'year') return $year;

        return $year !== '' ? trim(str_replace($year, '', $afterBrand)) : $afterBrand;
    }

    public function modelForGood(ShopGood $good): string
    {
        $brand = $good->brands->pluck('name')->filter()->join(' ');
        return $this->value((string) $good->name, $brand, 'model');
    }

    public function typeForGood(ShopGood $good): string
    {
        $brand = $good->brands->pluck('name')->filter()->join(' ');
        return $this->value((string) $good->name, $brand, 'type');
    }

    public function year(string $text): string
    {
        preg_match_all('/\b(20\d{2}|19\d{2})\b/', $text, $matches);
        return $matches[0][0] ?? '';
    }
}
