<?php

namespace Tests\Unit;

use App\Models\ShopBrand;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Models\ShopVariationAttribute;
use App\Models\ShopVariationAttributeValue;
use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketCategoryMapping;
use App\Services\YandexMarket\YandexMarketPayloadBuilder;
use App\Services\YandexMarket\YandexMarketProductResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class YandexMarketPayloadBuilderTest extends TestCase
{
    public function test_variation_payload_uses_parent_logistics_prices_stocks_and_dictionary_values(): void
    {
        $good = new ShopGood([
            'name' => 'Велосипед Author Stylo', 'sku' => 'GOOD', 'description' => '<p>Описание</p>',
            'price' => 10000, 'sale_price' => 9000, 'stock_quantity' => 1,
            'remote_stock_quantity' => '>10', 'fast_remote_stock_quantity' => '0',
            'shipping_length' => 120, 'shipping_width' => 20, 'shipping_height' => 60, 'shipping_weight' => 12,
        ]);
        $good->id = 10;
        $good->setRelation('brands', new Collection([new ShopBrand(['name' => 'Author'])]));
        $good->setRelation('properties', new Collection);
        $good->setRelation('images', new Collection([$this->image(1, 'goods/bike.jpg', true)]));

        $variation = new ShopGoodVariation([
            'name' => '9&quot; / Белый', 'sku' => 'VAR', 'price' => 8000, 'sale_price' => 7500,
            'stock_quantity' => 2, 'remote_stock_quantity' => '0', 'fast_remote_stock_quantity' => '5',
            'shipping_length' => 999, 'shipping_weight' => 999, 'is_active' => true,
        ]);
        $variation->id = 20;
        $variation->setRelation('images', new Collection);
        $attribute = new ShopVariationAttribute(['name' => 'Размер']);
        $attribute->id = 5;
        $attributeValue = new ShopVariationAttributeValue(['attribute_id' => 5, 'value' => '9&amp;quot;']);
        $attributeValue->setRelation('attribute', $attribute);
        $variation->setRelation('attributeValues', new Collection([$attributeValue]));
        $good->setRelation('variations', new Collection([$variation, new ShopGoodVariation(['is_active' => true])]));

        $mapping = new ShopYandexMarketCategoryMapping([
            'market_category_id' => 123,
            'market_category_name' => 'Детские велосипеды',
            'dimension_settings' => ['length_multiplier' => 1, 'width_multiplier' => 1, 'height_multiplier' => 1, 'weight_multiplier' => 1],
            'price_adjustment' => ['operation' => 'add', 'type' => 'percent', 'value' => 10],
            'attribute_mappings' => [
                ['id' => 1, 'name' => 'Бренд', 'required' => true, 'source' => 'brand'],
                ['id' => 2, 'name' => 'Размер', 'required' => true, 'distinctive' => true, 'source' => 'variation_attribute', 'source_key' => 5, 'values' => [['id' => 77]], 'allow_custom_values' => false, 'dictionary_map' => ['9"' => 77], 'dictionary_labels' => ['9"' => '9 дюймов']],
                ['id' => 200, 'name' => 'Название группы вариантов', 'source' => 'variation_group'],
            ],
        ]);
        $account = new ShopYandexMarketAccount(['image_base_url' => 'https://example.test']);

        $built = (new YandexMarketPayloadBuilder(new YandexMarketProductResolver))->build($good, $variation, $account, $mapping);
        $offer = $built['offer_mapping']['offer'];

        $this->assertSame('g_10_v_20', $built['offer_id']);
        $this->assertSame(8250.0, $offer['basicPrice']['value']);
        $this->assertSame(8800.0, $offer['basicPrice']['discountBase']);
        $this->assertSame(12, $built['stock']);
        $this->assertSame(['length' => 120.0, 'width' => 20.0, 'height' => 60.0, 'weight' => 12.0], $offer['weightDimensions']);
        $this->assertSame(['length' => 120.0, 'width' => 20.0, 'height' => 60.0, 'weight' => 12.0], $built['weight_dimensions']);
        $this->assertSame('shop-good-10', $built['variation_group_id']);
        $this->assertSame(['https://example.test/images/goods/bike.jpg'], $offer['pictures']);
        $this->assertSame(77, data_get(collect($offer['parameterValues'])->firstWhere('parameterId', 2), 'valueId'));
        $this->assertSame('shop-good-10', data_get(collect($offer['parameterValues'])->firstWhere('parameterId', 200), 'value'));
        $this->assertSame('9"', data_get(collect($built['display_parameters'])->firstWhere('id', 2), 'source_value'));
        $this->assertSame('9 дюймов', data_get(collect($built['display_parameters'])->firstWhere('id', 2), 'value'));
        $this->assertSame([], $built['errors']);
    }

    public function test_required_static_false_is_not_empty_and_unmapped_closed_dictionary_is_blocked(): void
    {
        $good = new ShopGood(['name' => 'Товар', 'price' => 100, 'depth' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]);
        $good->id = 11;
        $good->setRelation('brands', new Collection([new ShopBrand(['name' => 'Brand'])]));
        $good->setRelation('properties', new Collection);
        $good->setRelation('images', new Collection([$this->image(2, 'goods/item.jpg', true)]));
        $good->setRelation('variations', new Collection);
        $mapping = new ShopYandexMarketCategoryMapping([
            'market_category_id' => 5,
            'attribute_mappings' => [
                ['id' => 1, 'name' => 'Маркировка', 'required' => true, 'source' => 'static', 'value' => false],
                ['id' => 2, 'name' => 'Тип', 'required' => true, 'source' => 'static', 'value' => 'Локальный тип', 'uses_dictionary' => true, 'allow_custom_values' => false],
            ],
        ]);

        $built = (new YandexMarketPayloadBuilder(new YandexMarketProductResolver))->build($good, null, new ShopYandexMarketAccount(['image_base_url' => 'https://example.test']), $mapping);
        $offer = $built['offer_mapping']['offer'];

        $this->assertSame('false', data_get(collect($offer['parameterValues'])->firstWhere('parameterId', 1), 'value'));
        $this->assertTrue(collect($built['errors'])->contains(fn ($error) => str_contains($error, 'Локальный тип')));
        $this->assertFalse(collect($built['errors'])->contains(fn ($error) => str_contains($error, 'Маркировка')));
    }

    private function image(int $id, string $path, bool $main): ShopGoodImage
    {
        $image = new ShopGoodImage(['file_path' => $path, 'is_main' => $main, 'sort_order' => 1]);
        $image->id = $id;
        return $image;
    }
}
