<?php

namespace App\Support;

/**
 * Normalize product text coming from supplier and admin imports.
 * U+02DA is often used as a visual degree mark, but U+00B0 is the canonical
 * degree sign supported consistently by spreadsheet, database, and XML tools.
 */
final class ProductTextNormalizer
{
    public static function normalizeDegreeMark(string $value): string
    {
        return str_replace("\u{02DA}", "\u{00B0}", $value);
    }

    public static function normalizePayload(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::normalizeDegreeMark($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalizePayload($item);
        }

        return $value;
    }
}
