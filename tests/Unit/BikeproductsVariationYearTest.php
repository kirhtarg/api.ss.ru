<?php

namespace Tests\Unit;

use App\Services\BikeproductsCatalogService;
use PHPUnit\Framework\TestCase;

class BikeproductsVariationYearTest extends TestCase
{
    public function test_model_year_becomes_third_axis_when_supplier_provides_it(): void
    {
        $service = new BikeproductsCatalogService;
        $normalized = $this->callPrivate($service, 'normalizeSourceVariation', [[
            'NAME' => 'Шлем Example (Black, L, 2024 (SKU-1))',
            'MODELNYY_GOD' => '2025',
            'CML2_ARTICLE' => 'SKU-1',
        ], 'bikeproducts']);

        $this->assertTrue($normalized['is_variation']);
        $this->assertSame('2025', $normalized['year']);

        $axes = $this->callPrivate($service, 'namedVariationAxes', [
            $normalized['color'], $normalized['size'], $normalized['year'],
            ['Цвет' => 1, 'Размер' => 2, 'Год' => 3],
        ]);

        $this->assertSame(['Цвет', 'Размер', 'Год'], array_column($axes, 'attribute_name'));
        $this->assertSame('2025', $axes[2]['value']);
    }

    public function test_year_from_name_becomes_third_axis_when_model_year_is_absent(): void
    {
        $service = new BikeproductsCatalogService;
        $normalized = $this->callPrivate($service, 'normalizeSourceVariation', [[
            'NAME' => 'Шлем Example (Black, L, 2026 (SKU-2))',
            'CML2_ARTICLE' => 'SKU-2',
        ], 'bikeproducts']);

        $this->assertSame('2026', $normalized['year']);
        $this->assertSame('L', $normalized['size']);
        $this->assertTrue($normalized['is_variation']);
    }

    public function test_missing_or_technical_year_does_not_create_year_axis(): void
    {
        $service = new BikeproductsCatalogService;
        $normalized = $this->callPrivate($service, 'normalizeSourceVariation', [[
            'NAME' => 'Шлем Example (Black, L (SKU-3))',
            'MODELNYY_GOD' => 'GUID-123',
            'CML2_ARTICLE' => 'SKU-3',
        ], 'bikeproducts']);

        $this->assertNull($normalized['year']);
        $axes = $this->callPrivate($service, 'namedVariationAxes', [
            $normalized['color'], $normalized['size'], $normalized['year'],
            ['Цвет' => 1, 'Размер' => 2, 'Год' => 3],
        ]);

        $this->assertSame(['Цвет', 'Размер'], array_column($axes, 'attribute_name'));
    }

    private function callPrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invokeArgs($object, $arguments);
    }
}
