<?php

namespace Tests\Unit;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use App\Models\ShopVariationAttributeValue;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class OzonProductPayloadBuilderTest extends TestCase
{
    public function test_variation_uses_stable_offer_id_and_group_attributes(): void
    {
        $good = new ShopGood(['name' => 'Куртка', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 7;
        $good->setRelation('images', new Collection);

        $variation = new ShopGoodVariation(['name' => 'M', 'sku' => 'SKU-CAN-CHANGE', 'price' => 9000]);
        $variation->id = 11;
        $variation->setRelation('images', new Collection);
        $variation->setRelation('attributeValues', new Collection([
            new ShopVariationAttributeValue(['attribute_id' => 5, 'value' => 'M']),
        ]));

        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'variation_mode' => 'grouped',
            'group_attribute_id' => 9048,
            'variation_attribute_mappings' => [[
                'local_attribute_id' => 5,
                'local_attribute_name' => 'Размер',
                'ozon_attribute_id' => 10,
                'dictionary_map' => ['M' => 100],
                'uses_dictionary' => true,
            ]],
        ]);
        $account = new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver))->build($good, $variation, $account, $mapping);

        $this->assertSame('g_7_v_11', $built['offer_id']);
        $this->assertSame(100, data_get(collect($built['payload']['attributes'])->firstWhere('id', 10), 'values.0.dictionary_value_id'));
        $this->assertSame('Куртка', data_get(collect($built['payload']['attributes'])->firstWhere('id', 9048), 'values.0.value'));
    }
}
