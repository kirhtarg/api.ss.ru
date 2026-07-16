<?php

namespace App\Services\Ozon;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use App\Services\ProductNameParser;

class OzonProductPayloadBuilder
{
    public function __construct(
        private readonly OzonProductResolver $resolver,
        private readonly ProductNameParser $nameParser,
    ) {}

    public function build(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account, ?ShopOzonCategoryMapping $mapping = null): array
    {
        $mapping ??= $this->resolver->mappingFor($good, $this->resolver->mappings($account));
        $offerId = $this->offerId($good, $variation, $account);
        $price = $this->price($good, $variation);
        $dimensions = $this->dimensions($good, $mapping);
        $media = $this->media($good, $variation, $account);

        $payload = [
            'offer_id' => $offerId,
            'name' => $this->decodedText($good->name),
            'description' => $this->decodedText($good->description ?: $good->short_description ?: $good->name),
            'description_category_id' => $mapping?->description_category_id,
            'type_id' => $mapping?->type_id,
            'price' => $this->money($price),
            'old_price' => $this->oldPrice($good, $variation, $price),
            'currency_code' => 'RUB',
            'vat' => (string) $account->vat,
            'barcode' => '',
            'depth' => $dimensions['depth'],
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'dimension_unit' => 'mm',
            'weight' => $dimensions['weight'],
            'weight_unit' => 'g',
            'images' => $media['images'],
            'primary_image' => $media['primary_image'],
            'attributes' => $this->attributes($mapping, $good, $variation),
            'complex_attributes' => [],
        ];

        return [
            'offer_id' => $offerId,
            'brand_name' => $this->decodedText($good->brands->first()?->name),
            'source_dimensions' => $this->sourceDimensions($good),
            'media_summary' => $media['summary'],
            'payload' => $payload,
            'errors' => $this->validate($payload, $mapping, $good, $variation),
            'mapping_id' => $mapping?->id,
            'variation_mode' => $mapping?->variation_mode ?? 'grouped',
            'variation_attributes' => $this->variationSummary($mapping, $variation),
            'display_attributes' => $this->attributeSummary($mapping, $good, $variation),
            'computed_model' => $this->decodedText($this->nameParser->modelForGood($good)),
            'computed_type' => $this->decodedText($this->nameParser->typeForGood($good)),
        ];
    }

    public function stock(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        return [
            'offer_id' => $this->offerId($good, $variation, $account),
            'stock' => max(0, (int) ($variation?->stock_quantity ?? $good->stock_quantity)),
            'warehouse_id' => (string) $account->warehouse_id,
        ];
    }

    public function pricePayload(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        $price = $this->price($good, $variation);
        return [
            'offer_id' => $this->offerId($good, $variation, $account),
            'currency_code' => 'RUB',
            'price' => $this->money($price),
            'old_price' => $this->oldPrice($good, $variation, $price),
            'auto_action_enabled' => 'UNKNOWN',
        ];
    }

    public function offerId(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): string
    {
        $binding = $account->exists ? \App\Models\ShopOzonProductBinding::query()
            ->where('account_id', $account->id)
            ->where('good_id', $good->id)
            ->when($variation, fn ($query) => $query->where('variation_id', $variation->id), fn ($query) => $query->whereNull('variation_id'))
            ->oldest('id')
            ->first() : null;

        return (string) ($binding?->offer_id ?: ($variation ? "g_{$good->id}_v_{$variation->id}" : "g_{$good->id}"));
    }

    private function price(ShopGood $good, ?ShopGoodVariation $variation): float
    {
        return (float) ($variation?->final_price ?? $good->final_price);
    }

    private function oldPrice(ShopGood $good, ?ShopGoodVariation $variation, float $price): string
    {
        $base = (float) ($variation?->price ?? $good->price);
        return $base > $price ? $this->money($base) : '0';
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function dimensions(ShopGood $good, ?ShopOzonCategoryMapping $mapping): array
    {
        // Ozon offers inherit package dimensions from the parent product.
        $length = $this->firstPositive($good->shipping_length, $good->depth);
        $width = $this->firstPositive($good->shipping_width, $good->width);
        $height = $this->firstPositive($good->shipping_height, $good->height);
        $weight = $this->firstPositive($good->shipping_weight, $good->weight);
        $settings = $mapping?->dimension_settings ?? [];
        return [
            'depth' => (int) round($length * (float) ($settings['depth_multiplier'] ?? 10)),
            'width' => (int) round($width * (float) ($settings['width_multiplier'] ?? 10)),
            'height' => (int) round($height * (float) ($settings['height_multiplier'] ?? 10)),
            'weight' => (int) round($weight * (float) ($settings['weight_multiplier'] ?? 1000)),
        ];
    }

    private function media(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        $productImages = $this->orderedImages($good->images);
        $variationImages = $variation ? $this->orderedImages($variation->images) : collect();

        // If a variation has its own gallery, it must not be mixed with the parent gallery.
        // The parent gallery is used only when the variation has no images at all.
        $usesVariationImages = $variationImages->isNotEmpty();
        $items = $usesVariationImages ? $variationImages : $productImages;
        $urls = $items
            ->map(fn ($image) => $this->imageUrl((string) $image->url, $account))
            ->filter()
            ->unique()
            ->values();

        $primaryImage = (string) ($urls->first() ?? '');
        $additionalImages = $variation && (! $usesVariationImages || $urls->count() < 2)
            ? []
            : $urls->skip(1)->take(14)->values()->all();

        return [
            'primary_image' => $primaryImage,
            'images' => $additionalImages,
            'summary' => [
                'primary_source' => $usesVariationImages ? 'variation' : ($productImages->isNotEmpty() ? 'product' : null),
                'additional_count' => count($additionalImages),
                'total_count' => ($primaryImage !== '' ? 1 : 0) + count($additionalImages),
            ],
        ];
    }

    private function orderedImages($images)
    {
        return $images->sortBy([
            [fn ($image) => $image->is_main ? 0 : 1, 'asc'],
            [fn ($image) => (int) $image->sort_order, 'asc'],
            [fn ($image) => (int) $image->id, 'asc'],
        ])->values();
    }

    private function imageUrl(string $url, ShopOzonAccount $account): ?string
    {
        $url = trim($url);
        if ($url === '') return null;

        if (str_starts_with($url, '//')) $url = 'https:'.$url;
        if (! preg_match('~^https?://~i', $url)) {
            $baseUrl = rtrim((string) $account->image_base_url, '/');
            if ($baseUrl === '') return null;
            $url = $baseUrl.'/'.ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function attributes(?ShopOzonCategoryMapping $mapping, ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $attributes = collect($mapping?->attribute_mappings ?? [])->map(function (array $item) use ($good, $variation) {
            $source = $item['source'] ?? '';
            if ($source === '') return null;
            $value = $this->sourceValue($item, $good, $variation);
            if ($value === null || $value === '') return null;
            $dictionaryId = $item['dictionary_value_id'] ?? $this->dictionaryValueId($item['dictionary_map'] ?? [], (string) $value);
            $attributeValue = $dictionaryId
                ? ['dictionary_value_id' => (int) $dictionaryId, 'value' => (string) $value]
                : ['value' => (string) $value];
            return ['id' => (int) ($item['id'] ?? 0), 'complex_id' => 0, 'values' => [$attributeValue]];
        })->filter(fn ($item) => $item && $item['id'] > 0);

        foreach ($mapping?->variation_attribute_mappings ?? [] as $axis) {
            $value = $this->resolver->variationAttributeValue($variation, (int) ($axis['local_attribute_id'] ?? 0));
            if ($value === '') continue;
            $dictionaryId = $this->dictionaryValueId($axis['dictionary_map'] ?? [], $value);
            $attributes->push([
                'id' => (int) ($axis['ozon_attribute_id'] ?? 0),
                'complex_id' => 0,
                'values' => [$dictionaryId ? ['dictionary_value_id' => $dictionaryId, 'value' => $value] : ['value' => $value]],
            ]);
        }

        if ($variation && ($mapping?->variation_mode ?? '') === 'grouped' && $mapping?->group_attribute_id) {
            $groupAttributeId = (int) $mapping->group_attribute_id;
            $groupAttributeConfigured = collect($mapping->attribute_mappings ?? [])->contains(fn ($item) => (int) ($item['id'] ?? 0) === $groupAttributeId);
            if (! $groupAttributeConfigured) {
                $model = $this->nameParser->modelForGood($good) ?: trim((string) $good->name);
                $attributes->push(['id' => $groupAttributeId, 'complex_id' => 0, 'values' => [['value' => $model]]]);
            }
        }

        return $attributes->filter(fn ($item) => $item && $item['id'] > 0)
            ->groupBy('id')
            ->map(fn ($items) => $items->last())
            ->values()
            ->all();
    }

    private function validate(array $payload, ?ShopOzonCategoryMapping $mapping, ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $errors = [];
        if (! $mapping) $errors[] = 'Для категории товара не настроено сопоставление Ozon.';
        foreach (['offer_id', 'name'] as $field) if (empty($payload[$field])) $errors[] = "Не заполнено поле {$field}.";
        if ((float) $payload['price'] <= 0) $errors[] = 'Цена должна быть больше нуля.';
        foreach (['depth', 'width', 'height', 'weight'] as $field) if ((int) $payload[$field] <= 0) $errors[] = "Не заполнено поле {$field}.";
        if (empty($payload['primary_image']) && empty($payload['images'])) $errors[] = 'У товара нет изображения.';
        $sentAttributeIds = collect($payload['attributes'])->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($mapping?->attribute_mappings ?? [] as $attribute) {
            if (($attribute['required'] ?? false) && ! $sentAttributeIds->contains((int) ($attribute['id'] ?? 0))) {
                $errors[] = 'Не заполнен обязательный атрибут Ozon: '.($attribute['name'] ?? $attribute['id'] ?? 'без названия').'.';
            }
            if (($attribute['uses_dictionary'] ?? false) && ($attribute['source'] ?? '') !== 'static') {
                $value = (string) $this->sourceValue($attribute, $good, $variation);
                if ($value !== '' && ! $this->dictionaryValueId($attribute['dictionary_map'] ?? [], $value)) {
                    $errors[] = 'Значение «'.$value.'» не сопоставлено со словарём Ozon для атрибута '.($attribute['name'] ?? $attribute['id']).'. Откройте профиль категории, нажмите «Справочник Ozon», выберите соответствие и сохраните профиль.';
                }
            }
        }
        foreach ($mapping?->variation_attribute_mappings ?? [] as $axis) {
            $value = $this->resolver->variationAttributeValue($variation, (int) ($axis['local_attribute_id'] ?? 0));
            if ($variation && $value === '') {
                $errors[] = 'Не заполнена характеристика вариации: '.($axis['local_attribute_name'] ?? 'ID '.$axis['local_attribute_id']).'.';
            }
            if ($value !== '' && ! empty($axis['uses_dictionary']) && ! $this->dictionaryValueId($axis['dictionary_map'] ?? [], $value)) {
                $errors[] = 'Значение «'.$value.'» не сопоставлено со словарём Ozon для характеристики '.($axis['ozon_attribute_name'] ?? $axis['ozon_attribute_id']).'. Откройте профиль категории, нажмите «Справочник Ozon», сопоставьте значение вариации и сохраните профиль.';
            }
        }
        if ($variation && ($mapping?->variation_mode ?? '') === 'grouped' && $mapping?->group_attribute_id
            && ! $sentAttributeIds->contains((int) $mapping->group_attribute_id)) {
            $errors[] = 'Не удалось вычислить «Название модели» для объединения. Проверьте название товара и бренд.';
        }
        return $errors;
    }

    private function dictionaryValueId(array $map, string $value): ?int
    {
        $direct = $map[$value] ?? null;
        if ($direct) return (int) $direct;
        $needle = mb_strtolower($this->decodedText($value));
        foreach ($map as $key => $id) {
            if (mb_strtolower($this->decodedText($key)) === $needle && $id) return (int) $id;
        }
        return null;
    }

    private function variationSummary(?ShopOzonCategoryMapping $mapping, ?ShopGoodVariation $variation): array
    {
        if (! $variation) return [];
        $axes = collect($mapping?->variation_attribute_mappings ?? [])->keyBy(fn (array $axis) => (int) ($axis['local_attribute_id'] ?? 0));

        return $variation->attributeValues->map(function ($value) use ($variation, $axes) {
            $localAttributeId = (int) $value->attribute_id;
            $axis = $axes->get($localAttributeId, []);
            return [
                'local_attribute_id' => $localAttributeId,
                'name' => $this->decodedText($this->variationAttributeName($variation, $localAttributeId, $axis)),
                'value' => $this->decodedText($value->value),
                'ozon_attribute_id' => (int) ($axis['ozon_attribute_id'] ?? 0),
                'ozon_attribute_name' => $axis['ozon_attribute_name'] ?? null,
                'is_mapped' => $axes->has($localAttributeId),
            ];
        })->filter(fn (array $item) => $item['value'] !== '')->values()->all();
    }

    private function attributeSummary(?ShopOzonCategoryMapping $mapping, ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $items = collect($mapping?->attribute_mappings ?? [])->map(function (array $item) use ($good, $variation) {
            $value = $this->sourceValue($item, $good, $variation);
            if ($value === null || $value === '') return null;

            return [
                'id' => (int) ($item['id'] ?? 0),
                'name' => $this->decodedText($item['name'] ?? 'Атрибут Ozon'),
                'value' => $this->decodedText($value),
                'is_variation' => false,
            ];
        })->filter();

        foreach ($mapping?->variation_attribute_mappings ?? [] as $item) {
            $value = $this->resolver->variationAttributeValue($variation, (int) ($item['local_attribute_id'] ?? 0));
            if ($value === '') continue;
            $items->push([
                'id' => (int) ($item['ozon_attribute_id'] ?? 0),
                'name' => $this->decodedText($item['ozon_attribute_name'] ?? $this->variationAttributeName($variation, (int) ($item['local_attribute_id'] ?? 0), $item)),
                'value' => $this->decodedText($value),
                'is_variation' => true,
            ]);
        }

        return $items->filter(fn (array $item) => $item['id'] > 0)
            ->unique('id')
            ->values()
            ->all();
    }

    private function variationAttributeName(ShopGoodVariation $variation, int $attributeId, array $mapping): string
    {
        $storedName = trim((string) ($mapping['local_attribute_name'] ?? ''));
        if ($storedName !== '') return $storedName;

        $value = $variation->attributeValues->first(fn ($item) => (int) $item->attribute_id === $attributeId);
        return trim((string) ($value?->attribute?->name ?? '')) ?: 'Характеристика вариации';
    }

    private function propertyValue(ShopGood $good, int $propertyId): string
    {
        $property = $good->properties->first(fn ($item) => (int) $item->id === $propertyId);
        if (! $property) return '';
        $valueId = (int) ($property->pivot?->shop_property_value_id ?? 0);
        if ($valueId > 0) {
            return $this->decodedText($property->values->firstWhere('id', $valueId)?->value);
        }
        return $this->decodedText($property->pivot?->value);
    }

    private function sourceValue(array $item, ShopGood $good, ?ShopGoodVariation $variation): mixed
    {
        $source = $item['source'] ?? '';
        $value = match ($source) {
            'static' => $item['value'] ?? '',
            'variation' => data_get($variation, $item['source_key'] ?? ''),
            'property' => $this->propertyValue($good, (int) ($item['source_key'] ?? 0)),
            'brand' => $good->brands->first()?->name ?? '',
            'computed_model' => $this->nameParser->modelForGood($good),
            'computed_type' => $this->nameParser->typeForGood($good),
            'dimension_weight' => $this->firstPositive($good->shipping_weight, $good->weight),
            'dimension_width' => $this->firstPositive($good->shipping_width, $good->width),
            'dimension_length', 'dimension_depth' => $this->firstPositive($good->shipping_length, $good->depth),
            'dimension_height' => $this->firstPositive($good->shipping_height, $good->height),
            default => data_get($good, $item['source_key'] ?? ''),
        };

        if (str_starts_with($source, 'dimension_') && is_numeric($value)) {
            return (float) $value * $this->dimensionAttributeMultiplier($item, $source);
        }

        return is_string($value) ? $this->decodedText($value) : $value;
    }

    private function firstPositive(mixed ...$values): float
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0) return (float) $value;
        }

        return 0.0;
    }

    private function sourceDimensions(ShopGood $good): array
    {
        return [
            'length' => $this->firstPositive($good->shipping_length, $good->depth),
            'width' => $this->firstPositive($good->shipping_width, $good->width),
            'height' => $this->firstPositive($good->shipping_height, $good->height),
            'weight' => $this->firstPositive($good->shipping_weight, $good->weight),
            'dimension_unit' => 'cm',
            'weight_unit' => 'kg',
            'uses_shipping_dimensions' => $this->firstPositive($good->shipping_length, $good->shipping_width, $good->shipping_height, $good->shipping_weight) > 0,
        ];
    }

    private function dimensionAttributeMultiplier(array $item, string $source): float
    {
        $name = mb_strtolower($this->decodedText($item['name'] ?? ''));
        if ($source === 'dimension_weight') {
            if ($this->hasUnit($name, 'кг')) return 1.0;
            if ($this->hasUnit($name, 'г')) return 1000.0;
        } else {
            if ($this->hasUnit($name, 'мм')) return 10.0;
            if ($this->hasUnit($name, 'см')) return 1.0;
            if ($this->hasUnit($name, 'м')) return 0.01;
        }

        return (float) ($item['multiplier'] ?? 1);
    }

    private function hasUnit(string $name, string $unit): bool
    {
        return preg_match('/(?:^|[^\p{L}])'.preg_quote($unit, '/').'(?:$|[^\p{L}])/u', $name) === 1;
    }

    private function decodedText(mixed $value): string
    {
        $decoded = trim((string) ($value ?? ''));
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) break;
            $decoded = $next;
        }

        return trim($decoded);
    }
}
