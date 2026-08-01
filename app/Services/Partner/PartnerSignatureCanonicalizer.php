<?php

namespace App\Services\Partner;

final class PartnerSignatureCanonicalizer
{
    public function payload(array $payload): string
    {
        $normalized = $this->sortRecursively($payload);

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function query(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $position => $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurlencode(urldecode($rawKey));
            $value = rawurlencode(urldecode($rawValue));
            $pairs[] = [$key, $value, $position];
        }

        usort($pairs, static fn (array $left, array $right): int =>
            [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]]
        );

        return implode('&', array_map(
            static fn (array $pair): string => $pair[0].'='.$pair[1],
            $pairs,
        ));
    }

    public function request(
        string $method,
        string $path,
        string $rawQuery,
        string $rawBody,
        string $timestamp,
        string $nonce,
    ): string {
        return implode("\n", [
            strtoupper($method),
            '/'.ltrim($path, '/'),
            $this->query($rawQuery),
            hash('sha256', $rawBody),
            $timestamp,
            $nonce,
        ]);
    }

    private function sortRecursively(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? $this->sortRecursively($item) : $item, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }

        return $value;
    }
}
