<?php

namespace Tests\Unit;

use App\Models\ShopGood;
use App\Models\ShopGoodImage;
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
        $attributeValue = new ShopVariationAttributeValue(['attribute_id' => 5, 'value' => '9&amp;quot;']);
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
                'dictionary_map' => ['9"' => 100],
                'uses_dictionary' => true,
            ]],
        ]);
        $account = new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))->build($good, $variation, $account, $mapping);

        $this->assertSame('g_7_v_11', $built['offer_id']);
        $this->assertSame('Куртка', data_get($built, 'payload.name'));
        $this->assertSame('Размер', data_get($built, 'variation_attributes.0.name'));
        $this->assertSame('9"', data_get($built, 'variation_attributes.0.value'));
        $this->assertSame('9"', data_get(collect($built['payload']['attributes'])->firstWhere('id', 10), 'values.0.value'));
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
            'attribute_mappings' => [
                ['id' => 9048, 'name' => 'Название модели', 'required' => true, 'source' => 'computed_model'],
                ['id' => 31, 'name' => 'Бренд', 'source' => 'brand', 'uses_dictionary' => true, 'dictionary_map' => ['Arbor' => 77]],
            ],
        ]);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, $variation, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);

        $this->assertSame('Cadence Camber', $built['computed_model']);
        $this->assertSame('Сноуборд', $built['computed_type']);
        $this->assertSame('Arbor', $built['brand_name']);
        $this->assertSame('Arbor', data_get(collect($built['display_attributes'])->firstWhere('id', 31), 'value'));
        $this->assertSame(77, data_get(collect($built['payload']['attributes'])->firstWhere('id', 31), 'values.0.dictionary_value_id'));
        $this->assertSame('Cadence Camber', data_get(collect($built['payload']['attributes'])->firstWhere('id', 9048), 'values.0.value'));
    }

    public function test_dictionary_mappings_use_ozon_labels_and_normalized_local_values(): void
    {
        $good = new ShopGood(['name' => 'Велосипед детский Author Stylo 2022', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 81;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection([new ShopBrand(['name' => 'Author'])]));

        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'attribute_mappings' => [
                [
                    'id' => 10,
                    'name' => 'Тип',
                    'source' => 'computed_type',
                    'uses_dictionary' => true,
                    'dictionary_map' => ['Детский велосипед' => 501],
                    'dictionary_labels' => ['Детский велосипед' => 'Велосипед'],
                ],
                [
                    'id' => 31,
                    'name' => 'Бренд',
                    'source' => 'brand',
                    'uses_dictionary' => true,
                    'dictionary_map' => [' Author ' => 77],
                    'dictionary_labels' => [' Author ' => 'Author'],
                ],
            ],
        ]);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, null, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);
        $payloadAttributes = collect($built['payload']['attributes']);
        $displayAttributes = collect($built['display_attributes']);

        $this->assertSame(501, data_get($payloadAttributes->firstWhere('id', 10), 'values.0.dictionary_value_id'));
        $this->assertSame('Велосипед', data_get($payloadAttributes->firstWhere('id', 10), 'values.0.value'));
        $this->assertSame('Велосипед детский', data_get($displayAttributes->firstWhere('id', 10), 'source_value'));
        $this->assertSame('Велосипед', data_get($displayAttributes->firstWhere('id', 10), 'value'));
        $this->assertSame(77, data_get($payloadAttributes->firstWhere('id', 31), 'values.0.dictionary_value_id'));
        $this->assertSame('Author', data_get($payloadAttributes->firstWhere('id', 31), 'values.0.value'));
        $this->assertFalse(collect($built['errors'])->contains(fn ($error) => str_contains($error, 'Author')));
    }

    public function test_required_static_attributes_accept_text_and_dictionary_values(): void
    {
        $good = new ShopGood(['name' => 'Товар', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 82;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection);

        $mapping = new ShopOzonCategoryMapping([
            'description_category_id' => 1,
            'type_id' => 2,
            'attribute_mappings' => [
                ['id' => 7001, 'name' => 'Нужен код маркировки', 'type' => 'Boolean', 'required' => true, 'source' => 'static', 'value' => 'Нет'],
                ['id' => 7002, 'name' => 'ТН ВЭД коды ЕАЭС', 'required' => true, 'source' => 'static', 'value' => '', 'dictionary_value_id' => 123456],
            ],
        ]);

        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, null, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);
        $attributes = collect($built['payload']['attributes']);

        $this->assertSame('false', data_get($attributes->firstWhere('id', 7001), 'values.0.value'));
        $this->assertSame(123456, data_get($attributes->firstWhere('id', 7002), 'values.0.dictionary_value_id'));
        $this->assertFalse(collect($built['errors'])->contains(fn ($error) => str_contains($error, 'Нужен код маркировки')));
        $this->assertFalse(collect($built['errors'])->contains(fn ($error) => str_contains($error, 'ТН ВЭД')));
    }

    public function test_prices_and_stocks_use_store_priority_and_remote_availability_formula(): void
    {
        $good = new ShopGood([
            'name' => 'Товар', 'price' => 10000, 'sale_price' => 9000,
            'demping_price' => 8000, 'show_demping' => true,
            'stock_quantity' => 3, 'remote_stock_quantity' => '>10', 'fast_remote_stock_quantity' => '0',
            'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1,
        ]);
        $good->id = 83;
        $good->setRelation('images', new Collection);
        $good->setRelation('brands', new Collection);
        $account = new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0', 'warehouse_id' => '100']);
        $mapping = new ShopOzonCategoryMapping(['description_category_id' => 1, 'type_id' => 2]);
        $builder = new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser);

        $built = $builder->build($good, null, $account, $mapping);

        $this->assertSame('8000.00', data_get($built, 'payload.price'));
        $this->assertSame('10000.00', data_get($built, 'payload.old_price'));
        $this->assertSame('dumping', data_get($built, 'price_summary.source'));
        $this->assertSame(13, data_get($built, 'stock'));
        $this->assertSame(10, data_get($built, 'stock_summary.remote_bonus'));
        $this->assertSame(0, data_get($built, 'stock_summary.fast_remote_bonus'));
        $this->assertSame(13, data_get($builder->stock($good, null, $account), 'stock'));
        $this->assertSame('8000.00', data_get($builder->pricePayload($good, null, $account), 'price'));

        $good->show_demping = false;
        $good->remote_stock_quantity = '';
        $good->fast_remote_stock_quantity = '5';
        $built = $builder->build($good, null, $account, $mapping);

        $this->assertSame('9000.00', data_get($built, 'payload.price'));
        $this->assertSame('sale', data_get($built, 'price_summary.source'));
        $this->assertSame(13, data_get($built, 'stock'));
        $this->assertSame(0, data_get($built, 'stock_summary.remote_bonus'));
        $this->assertSame(10, data_get($built, 'stock_summary.fast_remote_bonus'));

        $variation = new ShopGoodVariation([
            'price' => 5000, 'sale_price' => 4500, 'demping_price' => 4000, 'show_demping' => true,
            'stock_quantity' => 2, 'remote_stock_quantity' => '0', 'fast_remote_stock_quantity' => '>10',
        ]);
        $variation->id = 84;
        $variation->setRelation('images', new Collection);
        $variation->setRelation('attributeValues', new Collection);
        $built = $builder->build($good, $variation, $account, $mapping);

        $this->assertSame('4000.00', data_get($built, 'payload.price'));
        $this->assertSame('5000.00', data_get($built, 'payload.old_price'));
        $this->assertSame(12, data_get($built, 'stock'));
        $this->assertSame(0, data_get($built, 'stock_summary.remote_bonus'));
        $this->assertSame(10, data_get($built, 'stock_summary.fast_remote_bonus'));
    }

    public function test_dimension_sources_always_use_parent_good_for_variations(): void
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
                ['id' => 101, 'name' => 'Вес в собранном состоянии, кг', 'source' => 'dimension_weight', 'multiplier' => 2],
                ['id' => 106, 'name' => 'Вес товара, г', 'source' => 'dimension_weight', 'multiplier' => 1000],
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

        $this->assertSame('5', data_get($attributes->firstWhere('id', 101), 'values.0.value'));
        $this->assertSame('2500', data_get($attributes->firstWhere('id', 106), 'values.0.value'));
        $this->assertSame('350', data_get($attributes->firstWhere('id', 102), 'values.0.value'));
        $this->assertSame('45', data_get($attributes->firstWhere('id', 103), 'values.0.value'));
        $this->assertSame('25', data_get($attributes->firstWhere('id', 104), 'values.0.value'));
        $this->assertSame('45', data_get($attributes->firstWhere('id', 105), 'values.0.value'));
        $this->assertSame(90, data_get($built, 'payload.depth'));
        $this->assertSame(105, data_get($built, 'payload.width'));
        $this->assertSame(100, data_get($built, 'payload.height'));
        $this->assertSame(13, data_get($built, 'payload.weight'));
        $this->assertSame(45.0, data_get($built, 'source_dimensions.length'));
        $this->assertSame(2.5, data_get($built, 'source_dimensions.weight'));
    }

    public function test_images_are_split_into_primary_and_additional_media_for_ozon(): void
    {
        $good = new ShopGood(['name' => 'Товар с изображениями', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 10;
        $good->setRelation('brands', new Collection);
        $good->setRelation('images', new Collection([
            $this->image(1, 'goods/product-detail.png', false, 1),
            $this->image(2, 'goods/product-main.jpg', true, 20),
        ]));

        $variation = new ShopGoodVariation(['name' => 'Красный', 'price' => 10000]);
        $variation->id = 20;
        $variation->setRelation('attributeValues', new Collection);
        $variation->setRelation('images', new Collection([
            $this->image(3, 'goods/variation-detail.jpg', false, 1),
            $this->image(4, 'goods/variation-main.jpg', true, 50),
        ]));

        $mapping = new ShopOzonCategoryMapping(['description_category_id' => 1, 'type_id' => 2]);
        $built = (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))
            ->build($good, $variation, new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']), $mapping);

        $this->assertSame('https://example.test/images/goods/variation-main.jpg', data_get($built, 'payload.primary_image'));
        $this->assertSame([
            'https://example.test/images/goods/variation-detail.jpg',
        ], data_get($built, 'payload.images'));
        $this->assertSame('variation', data_get($built, 'media_summary.primary_source'));
        $this->assertSame(2, data_get($built, 'media_summary.total_count'));
        $this->assertNotContains('У товара нет изображения.', $built['errors']);
    }

    public function test_variation_with_one_image_has_no_additional_images(): void
    {
        $good = $this->goodWithProductImages();
        $variation = new ShopGoodVariation(['name' => 'Синий', 'price' => 10000]);
        $variation->id = 21;
        $variation->setRelation('attributeValues', new Collection);
        $variation->setRelation('images', new Collection([
            $this->image(3, 'goods/variation-only.jpg', true, 1),
        ]));

        $built = $this->buildMedia($good, $variation);

        $this->assertSame('https://example.test/images/goods/variation-only.jpg', data_get($built, 'payload.primary_image'));
        $this->assertSame([], data_get($built, 'payload.images'));
        $this->assertSame('variation', data_get($built, 'media_summary.primary_source'));
    }

    public function test_variation_without_images_uses_only_product_primary_image(): void
    {
        $good = $this->goodWithProductImages();
        $variation = new ShopGoodVariation(['name' => 'Без фото', 'price' => 10000]);
        $variation->id = 22;
        $variation->setRelation('attributeValues', new Collection);
        $variation->setRelation('images', new Collection);

        $built = $this->buildMedia($good, $variation);

        $this->assertSame('https://example.test/images/goods/product-main.jpg', data_get($built, 'payload.primary_image'));
        $this->assertSame([], data_get($built, 'payload.images'));
        $this->assertSame('product', data_get($built, 'media_summary.primary_source'));
        $this->assertSame(1, data_get($built, 'media_summary.total_count'));
    }

    private function goodWithProductImages(): ShopGood
    {
        $good = new ShopGood(['name' => 'Товар с изображениями', 'price' => 10000, 'depth' => 10, 'width' => 20, 'height' => 30, 'weight' => 1]);
        $good->id = 11;
        $good->setRelation('brands', new Collection);
        $good->setRelation('images', new Collection([
            $this->image(1, 'goods/product-detail.png', false, 1),
            $this->image(2, 'goods/product-main.jpg', true, 20),
        ]));
        return $good;
    }

    private function buildMedia(ShopGood $good, ShopGoodVariation $variation): array
    {
        return (new OzonProductPayloadBuilder(new OzonProductResolver, new ProductNameParser))->build(
            $good,
            $variation,
            new ShopOzonAccount(['image_base_url' => 'https://example.test', 'vat' => '0']),
            new ShopOzonCategoryMapping(['description_category_id' => 1, 'type_id' => 2]),
        );
    }

    private function image(int $id, string $path, bool $isMain, int $sortOrder): ShopGoodImage
    {
        $image = new ShopGoodImage(['file_path' => $path, 'is_main' => $isMain, 'sort_order' => $sortOrder]);
        $image->id = $id;
        return $image;
    }
}
