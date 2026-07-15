<?php

namespace Tests\Unit;

use App\Jobs\ProcessExportJob;
use App\Models\ShopGood;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ProcessExportClonedFieldsTest extends TestCase
{
    public function test_cloned_fields_keep_their_individual_template_labels(): void
    {
        $job = (new ReflectionClass(ProcessExportJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProcessExportJob::class, 'createExportRow');
        $method->setAccessible(true);
        $good = new ShopGood(['name' => 'Тестовый товар']);

        $row = $method->invoke($job, $good, [
            'fields' => ['name', 'name__clone_123'],
            'field_labels' => ['name' => 'Название', 'name__clone_123' => 'Название товара Ozon'],
        ], 1);

        $this->assertSame('Тестовый товар', $row['Название']);
        $this->assertSame('Тестовый товар', $row['Название товара Ozon']);
    }
}
