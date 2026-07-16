<?php

namespace App\Services\Ozon;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;

class OzonProductPayloadBuilder
{
    public function __construct(private readonly OzonProductResolver $resolver) {}

    public function build(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account, ?ShopOzonCategoryMapping $mapping = null): array
    {
        $mapping ??= $this->resolver->mappingFor($good, $this->resolver->mappings($account));
        $offerId = $this->offerId($good, $variation, $account);
        $price = $this->price($good, $variation);
        $dimensions = $this->dimensions($good, $variation);
        $images = $this->images($good, $variation, $account);

        $payload = [
            'offer_id' => $offerId,
            'name' => trim($good->name.($variation?->name ? ' '.$variation->name : '')),
            'description' => (string) ($good->description ?: $good->short_description ?: $good->name),
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
            'images' => $images,
            'primary_image' => $images[0] ?? '',
            'attributes' => $this->attributes($mapping, $good, $variation),
            'complex_attributes' => [],
        ];

        return [
            'offer_id' => $offerId,
            'payload' => $payload,
            'errors' => $this->validate($payload, $mapping, $good, $variation),
            'mapping_id' => $mapping?->id,
            'variation_mode' => $mapping?->variation_mode ?? 'grouped',
            'variation_attributes' => $this->variationSummary($mapping, $variation),
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

    private function dimensions(ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $length = (float) ($variation?->shipping_length ?: $variation?->length ?: $good->shipping_length ?: $good->depth);
        $width = (float) ($variation?->shipping_width ?: $variation?->width ?: $good->shipping_width ?: $good->width);
        $height = (float) ($variation?->shipping_height ?: $variation?->height ?: $good->shipping_height ?: $good->height);
        $weight = (float) ($variation?->shipping_weight ?: $variation?->weight ?: $good->shipping_weight ?: $good->weight);
        return [
            'depth' => (int) round($length * 10),
            'width' => (int) round($width * 10),
            'height' => (int) round($height * 10),
            'weight' => (int) round($weight * 1000),
        ];
    }

    private function images(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        $items = $variation && $variation->images->isNotEmpty() ? $variation->images : $good->images;
        return $items->map(function ($image) use ($account) {
            $url = $image->url;
            return str_starts_with((string) $url, 'http') ? $url : rtrim($account->image_base_url, '/').'/'.ltrim((string) $url, '/');
        })->filter()->unique()->take(15)->values()->all();
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
            $attributes->push([
                'id' => (int) $mapping->group_attribute_id,
                'complex_id' => 0,
                'values' => [['value' => trim((string) $good->name)]],
            ]);
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
        if (empty($payload['images'])) $errors[] = 'У товара нет изображения.';
        $sentAttributeIds = collect($payload['attributes'])->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($mapping?->attribute_mappings ?? [] as $attribute) {
            if (($attribute['required'] ?? false) && ! $sentAttributeIds->contains((int) ($attribute['id'] ?? 0))) {
                $errors[] = 'Не заполнен обязательный атрибут Ozon: '.($attribute['name'] ?? $attribute['id'] ?? 'без названия').'.';
            }
            if (($attribute['uses_dictionary'] ?? false) && ($attribute['source'] ?? '') !== 'static') {
                $value = (string) $this->sourceValue($attribute, $good, $variation);
                if ($value !== '' && ! $this->dictionaryValueId($attribute['dictionary_map'] ?? [], $value)) {
                    $errors[] = 'Значение «'.$value.'» не сопоставлено со словарём Ozon для атрибута '.($attribute['name'] ?? $attribute['id']).'.';
                }
            }
        }
        foreach ($mapping?->variation_attribute_mappings ?? [] as $axis) {
            $value = $this->resolver->variationAttributeValue($variation, (int) ($axis['local_attribute_id'] ?? 0));
            if ($variation && $value === '') {
                $errors[] = 'Не заполнена характеристика вариации: '.($axis['local_attribute_name'] ?? 'ID '.$axis['local_attribute_id']).'.';
            }
            if ($value !== '' && ! empty($axis['uses_dictionary']) && ! $this->dictionaryValueId($axis['dictionary_map'] ?? [], $value)) {
                $errors[] = 'Значение «'.$value.'» не сопоставлено со словарём Ozon для характеристики '.($axis['ozon_attribute_name'] ?? $axis['ozon_attribute_id']).'.';
            }
        }
        return $errors;
    }

    private function dictionaryValueId(array $map, string $value): ?int
    {
        $direct = $map[$value] ?? null;
        if ($direct) return (int) $direct;
        $needle = mb_strtolower(trim($value));
        foreach ($map as $key => $id) {
            if (mb_strtolower(trim((string) $key)) === $needle && $id) return (int) $id;
        }
        return null;
    }

    private function variationSummary(?ShopOzonCategoryMapping $mapping, ?ShopGoodVariation $variation): array
    {
        if (! $variation) return [];
        return collect($mapping?->variation_attribute_mappings ?? [])->map(fn (array $axis) => [
            'local_attribute_id' => (int) ($axis['local_attribute_id'] ?? 0),
            'name' => $axis['local_attribute_name'] ?? 'Характеристика',
            'value' => $this->resolver->variationAttributeValue($variation, (int) ($axis['local_attribute_id'] ?? 0)),
            'ozon_attribute_id' => (int) ($axis['ozon_attribute_id'] ?? 0),
            'ozon_attribute_name' => $axis['ozon_attribute_name'] ?? null,
        ])->all();
    }

    private function propertyValue(ShopGood $good, int $propertyId): string
    {
        $property = $good->properties->first(fn ($item) => (int) $item->id === $propertyId);
        if (! $property) return '';
        $valueId = (int) ($property->pivot?->shop_property_value_id ?? 0);
        if ($valueId > 0) {
            return trim((string) ($property->values->firstWhere('id', $valueId)?->value ?? ''));
        }
        return trim((string) ($property->pivot?->value ?? ''));
    }

    private function sourceValue(array $item, ShopGood $good, ?ShopGoodVariation $variation): mixed
    {
        return match ($item['source'] ?? '') {
            'static' => $item['value'] ?? '',
            'variation' => data_get($variation, $item['source_key'] ?? ''),
            'property' => $this->propertyValue($good, (int) ($item['source_key'] ?? 0)),
            'brand' => (string) ($good->brands->first()?->name ?? ''),
            default => data_get($good, $item['source_key'] ?? ''),
        };
    }
}
