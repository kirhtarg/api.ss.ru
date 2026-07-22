<?php

namespace Tests\Unit;

use App\Models\ShopBrand;
use App\Models\ShopGood;
use App\Services\ProductNameParser;
use Illuminate\Support\Collection;
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

    public function test_type_for_good_uses_text_before_brand(): void
    {
        $good = new ShopGood(['name' => 'Сноуборд Arbor Cadence Camber 2025']);
        $good->setRelation('brands', new Collection([new ShopBrand(['name' => 'Arbor'])]));

        $this->assertSame('Сноуборд', (new ProductNameParser)->typeForGood($good));
    }
}
