<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\ExportFilesController;
use App\Models\ExportFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProtectedAvitoExportTest extends TestCase
{
    #[DataProvider('files')]
    public function test_permanent_avito_export_detection(array $attributes, bool $expected): void
    {
        $method = new ReflectionMethod(ExportFilesController::class, 'isProtectedAvitoFeed');
        $method->setAccessible(true);

        $actual = $method->invoke(new ExportFilesController(), new ExportFile($attributes));

        $this->assertSame($expected, $actual);
    }

    public static function files(): array
    {
        return [
            'configuration flag' => [['export_config' => ['is_avito_permanent' => true]], true],
            'permanent filename' => [['filename' => 'avito.xml'], true],
            'permanent storage path' => [['file_path' => 'exports/avito.xml'], true],
            'archive avito export' => [['filename' => 'export_123.xml', 'file_path' => 'exports/export_123.xml'], false],
            'excel export' => [['filename' => 'export_123.xlsx'], false],
        ];
    }
}
