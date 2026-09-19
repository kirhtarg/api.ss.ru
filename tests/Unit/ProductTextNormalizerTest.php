<?php

namespace Tests\Unit;

use App\Support\ProductTextNormalizer;
use PHPUnit\Framework\TestCase;

class ProductTextNormalizerTest extends TestCase
{
    public function test_degree_ring_is_normalized_to_standard_degree_sign_in_nested_import_payloads(): void
    {
        $payload = ProductTextNormalizer::normalizePayload([
            'name' => 'Велошлем 9˚',
            'description' => 'Угол наклона 27.5˚',
            'variation' => ['name' => 'Размер 9˚'],
            'numeric' => 9,
        ]);

        self::assertSame('Велошлем 9°', $payload['name']);
        self::assertSame('Угол наклона 27.5°', $payload['description']);
        self::assertSame('Размер 9°', $payload['variation']['name']);
        self::assertSame(9, $payload['numeric']);
    }
}
