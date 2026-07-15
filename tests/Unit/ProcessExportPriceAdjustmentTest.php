<?php

namespace Tests\Unit;

use App\Jobs\ProcessExportJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ProcessExportPriceAdjustmentTest extends TestCase
{
    #[DataProvider('adjustments')]
    public function test_price_adjustment($value, array $adjustment, $expected): void
    {
        $job = (new ReflectionClass(ProcessExportJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProcessExportJob::class, 'applyPriceAdjustment');
        $method->setAccessible(true);

        $actual = $method->invoke($job, 'price', $value, [
            'price_adjustments' => ['price' => $adjustment],
        ]);

        $this->assertSame($expected, $actual);
    }

    public static function adjustments(): array
    {
        return [
            'add percent' => [1000, ['operation' => 'add', 'mode' => 'percent', 'value' => 10], 1100.0],
            'subtract percent' => [1000, ['operation' => 'subtract', 'mode' => 'percent', 'value' => 10], 900.0],
            'add absolute' => [1000, ['operation' => 'add', 'mode' => 'absolute', 'value' => 125.5], 1125.5],
            'subtract cannot become negative' => [100, ['operation' => 'subtract', 'mode' => 'absolute', 'value' => 125], 0.0],
            'empty value remains empty' => ['', ['operation' => 'add', 'mode' => 'absolute', 'value' => 125], ''],
            'legacy config changes nothing' => [999.99, [], 999.99],
        ];
    }
}
