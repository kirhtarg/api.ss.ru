<?php

namespace Tests\Unit;

use App\Jobs\ProcessExportJob;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ProcessExportExcelCellTypeTest extends TestCase
{
    public function test_prices_and_dimensions_are_written_as_numbers_but_sku_stays_text(): void
    {
        $job = (new ReflectionClass(ProcessExportJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProcessExportJob::class, 'setExcelCellValue');
        $method->setAccessible(true);
        $sheet = (new Spreadsheet)->getActiveSheet();

        $method->invoke($job, $sheet, 'A1', '19791.50', 'price');
        $method->invoke($job, $sheet, 'B1', '90', 'height');
        $method->invoke($job, $sheet, 'C1', '001234', 'sku');

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A1')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C1')->getDataType());
        $this->assertSame('001234', $sheet->getCell('C1')->getValue());
    }
}
