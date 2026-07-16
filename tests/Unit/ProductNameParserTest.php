<?php

namespace Tests\Unit;

use App\Services\ProductNameParser;
use PHPUnit\Framework\TestCase;

class ProductNameParserTest extends TestCase
{
    public function test_model_matches_export_algorithm(): void
    {
        $parser = new ProductNameParser;

        $this->assertSame('Cadence Camber', $parser->value('Сноуборд Arbor Cadence Camber 2025', 'Arbor', 'model'));
        $this->assertSame('2025', $parser->value('Сноуборд Arbor Cadence Camber 2025', 'Arbor', 'year'));
        $this->assertSame('Сноуборд', $parser->value('Сноуборд Arbor Cadence Camber 2025', 'Arbor', 'type'));
    }
}
