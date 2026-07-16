<?php

namespace Tests\Unit;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopBrand;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use App\Models\ShopVariationAttribute;
use App\Models\ShopVariationAttributeValue;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use App\Services\ProductNameParser;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class OzonProductPayloadBuilderTest extends TestCase
{
    public function test_variation_uses_stable_offer_id_and_group_attributes(): void
    {
        $good = new ShopGood(['name' => 'Куртка', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 7;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection);

        $variation = new ShopGoodVariation(['name' => 'M', 'sku' => 'SKU-CAN-CHANGE', 'price' => 9000]);
        $variation->id = 11;
        $variation->setRelation('images', new Collection);
        $attribute = new ShopVariationAttribute(['name' => 'Размер']);
        $attribute->id = 5;
        $attributeValue = new ShopVariationAttributeValue(['attribute_id' => 5, 'value' => 'M']);
        $attributeValue->setRelation('attribute', $attribute);
        $variation->setRelation('attributeValues', new Collection([$attributeValue]));

        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'variation_mode' => 'grouped',
            'group_attribute_id' => 9048,
            'variation_attribute_mappings' => [[
                'local_attribute_id' => 5,
                'ozon_attribute_id' => 10,
                'dictionary_map' => ['M' => 100],
                'uses_dictionary' => true,
            ]],
        ]);
        $account = new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))->build($good, $variation, $account, $mapping);

        $this->assertSame('g_7_v_11', $built['offer_id']);
        $this->assertSame('Куртка', data_get($built, 'payload.name'));
        $this->assertSame('Размер', data_get($built, 'variation_attributes.0.name'));
        $this->assertSame(100, data_get(collect($built['payload']['attributes'])->firstWhere('id', 10), 'values.0.dictionary_value_id'));
        $this->assertSame('Куртка', data_get(collect($built['payload']['attributes'])->firstWhere('id', 9048), 'values.0.value'));
    }

    public function test_computed_model_source_uses_export_parser_and_is_not_overwritten(): void
    {
        $good = new ShopGood(['name' => 'Сноуборд Arbor Cadence Camber 2025', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 8;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection([new ShopBrand(['name' => 'Arbor'])]));
        $variation = new ShopGoodVariation(['name' => '147', 'price' => 10000]);
        $variation->id = 12;
        $variation->setRelation('images', new Collection);
        $variation->setRelation('attributeValues', new Collection);
        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'variation_mode' => 'grouped',
            'group_attribute_id' => 9048,
            'attribute_mappings' => [['id' => 9048, 'name' => 'Название модели', 'required' => true, 'source' => 'computed_model']],
        ]);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, $variation, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);

        $this->assertSame('Cadence Camber', $built['computed_model']);
        $this->assertSame('Сноуборд', $built['computed_type']);
        $this->assertSame('Cadence Camber', data_get(collect($built['payload']['attributes'])->firstWhere('id', 9048), 'values.0.value'));
    }

    public function test_dimension_sources_prefer_variation_and_fall_back_to_parent_good(): void
    {
        $good = new ShopGood([
            'name' => 'Товар с габаритами',
            'price' => 10000,
            'depth' => 40,
            'width' => 30,
            'height' => 20,
            'weight' => 2,
            'shipping_length' => 45,
            'shipping_width' => 35,
            'shipping_height' => 25,
            'shipping_weight' => 2.5,
        ]);
        $good->id = 9;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection);
        $variation = new ShopGoodVariation([
            'name' => 'Большой',
            'price' => 10000,
            'length' => 50,
            'shipping_length' => 55,
            'shipping_width' => 36,
            'shipping_weight' => 3.25,
        ]);
        $variation->id = 13;
        $variation->setRelation('images', new Collection);
        $variation->setRelation('attributeValues', new Collection);
        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'attribute_mappings' => [
                ['id' => 101, 'source' => 'dimension_weight', 'multiplier' => 1000],
                ['id' => 102, 'source' => 'dimension_width', 'multiplier' => 10],
                ['id' => 103, 'source' => 'dimension_length'],
                ['id' => 104, 'source' => 'dimension_height'],
                ['id' => 105, 'source' => 'dimension_depth'],
            ],
            'dimension_settings' => [
                'depth_multiplier' => 2,
                'width_multiplier' => 3,
                'height_multiplier' => 4,
                'weight_multiplier' => 5,
            ],
        ]);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, $variation, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);
        $attributes = collect($built['payload']['attributes']);

        $this->assertSame('3250', data_get($attributes->firstWhere('id', 101), 'values.0.value'));
        $this->assertSame('360', data_get($attributes->firstWhere('id', 102), 'values.0.value'));
        $this->assertSame('50', data_get($attributes->firstWhere('id', 103), 'values.0.value'));
        $this->assertSame('25', data_get($attributes->firstWhere('id', 104), 'values.0.value'));
        $this->assertSame('55', data_get($attributes->firstWhere('id', 105), 'values.0.value'));
        $this->assertSame(110, data_get($built, 'payload.depth'));
        $this->assertSame(108, data_get($built, 'payload.width'));
        $this->assertSame(100, data_get($built, 'payload.height'));
        $this->assertSame(16, data_get($built, 'payload.weight'));
    }
}
