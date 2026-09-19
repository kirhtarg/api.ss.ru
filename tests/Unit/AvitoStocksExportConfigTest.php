<?php

namespace Tests\Unit;

use App\Jobs\ProcessExportJob;
use PHPUnit\Framework\TestCase;

class AvitoStocksExportConfigTest extends TestCase
{
    public function test_stock_feed_config_removes_only_stock_filters_and_keeps_export_scope(): void
    {
        $job = (new \ReflectionClass(ProcessExportJob::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ProcessExportJob::class, 'avitoStocksExportConfig');
        $method->setAccessible(true);

        $config = [
            'format' => 'avito',
            'filters' => [
                'selected_ids' => [10, 20],
                'is_active' => 'true',
                'categories' => [3],
                'in_stock' => 'true',
                'stock_quantity_min' => 1,
                'remote_stock_variations_not_empty' => '1',
            ],
        ];

        $result = $method->invoke($job, $config);

        self::assertSame('avito', $result['format']);
        self::assertSame([10, 20], $result['filters']['selected_ids']);
        self::assertSame('true', $result['filters']['is_active']);
        self::assertSame([3], $result['filters']['categories']);
        self::assertArrayNotHasKey('in_stock', $result['filters']);
        self::assertArrayNotHasKey('stock_quantity_min', $result['filters']);
        self::assertArrayNotHasKey('remote_stock_variations_not_empty', $result['filters']);
    }
}
