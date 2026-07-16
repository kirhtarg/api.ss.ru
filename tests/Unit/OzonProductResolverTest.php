<?php

namespace Tests\Unit;

use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOzonCategoryMapping;
use App\Models\ShopVariationAttributeValue;
use App\Services\Ozon\OzonProductResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class OzonProductResolverTest extends TestCase
{
    public function test_mapping_matches_any_category_from_profile(): void
    {
        $good = new ShopGood(['name' => 'Товар']);
        $category = new ShopCategory;
        $category->id = 20;
        $good->setRelation('categories', new Collection([$category]));

        $mapping = new ShopOzonCategoryMapping(['category_id' => 10, 'category_ids' => [10, 20, 30]]);

        $this->assertSame($mapping, (new OzonProductResolver)->mappingFor($good, new Collection([$mapping])));
    }

    public function test_single_mode_creates_only_parent_row(): void
    {
        $good = $this->goodWithVariations(['M', 'L']);
        $mapping = new ShopOzonCategoryMapping(['variation_mode' => 'single']);

        $rows = (new OzonProductResolver)->rowsForGood($good, $mapping);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['variation']);
    }

    public function test_grouped_mode_rejects_duplicate_variation_combinations(): void
    {
        $good = $this->goodWithVariations(['M', 'M']);
        $mapping = new ShopOzonCategoryMapping([
            'variation_mode' => 'grouped',
            'group_attribute_id' => 9048,
            'variation_attribute_mappings' => [[
                'local_attribute_id' => 5,
                'local_attribute_name' => 'Размер',
                'ozon_attribute_id' => 10,
            ]],
        ]);

        $rows = (new OzonProductResolver)->rowsForGood($good, $mapping);

        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['row_errors']);
        $this->assertNotEmpty($rows[1]['row_errors']);
    }

    private function goodWithVariations(array $values): ShopGood
    {
        $good = new ShopGood(['name' => 'Куртка']);
        $variations = collect($values)->map(function (string $text, int $index) {
            $variation = new ShopGoodVariation(['name' => $text, 'is_active' => true]);
            $variation->id = $index + 1;
            $value = new ShopVariationAttributeValue(['attribute_id' => 5, 'value' => $text]);
            $variation->setRelation('attributeValues', new Collection([$value]));
            return $variation;
        });
        $good->setRelation('variations', $variations);
        return $good;
    }
}
