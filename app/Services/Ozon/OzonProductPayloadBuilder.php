<?php

namespace App\Services\Ozon;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;

class OzonProductPayloadBuilder
{
    public function build(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        $mapping = $this->mappingFor($good, $account);
        $offerId = $this->offerId($good, $variation);
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

        return ['offer_id' => $offerId, 'payload' => $payload, 'errors' => $this->validate($payload, $mapping)];
    }

    public function stock(ShopGood $good, ?ShopGoodVariation $variation, ShopOzonAccount $account): array
    {
        return [
            'offer_id' => $this->offerId($good, $variation),
            'stock' => max(0, (int) ($variation?->stock_quantity ?? $good->stock_quantity)),
            'warehouse_id' => (string) $account->warehouse_id,
        ];
    }

    public function pricePayload(ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $price = $this->price($good, $variation);
        return [
            'offer_id' => $this->offerId($good, $variation),
            'currency_code' => 'RUB',
            'price' => $this->money($price),
            'old_price' => $this->oldPrice($good, $variation, $price),
            'auto_action_enabled' => 'UNKNOWN',
        ];
    }

    private function mappingFor(ShopGood $good, ShopOzonAccount $account): ?ShopOzonCategoryMapping
    {
        $ids = $good->categories->pluck('id');
        return ShopOzonCategoryMapping::query()->where('account_id', $account->id)->where('is_active', true)->whereIn('category_id', $ids)->first();
    }

    private function offerId(ShopGood $good, ?ShopGoodVariation $variation): string
    {
        return (string) ($variation?->sku ?: ($variation ? "g_{$good->id}_v_{$variation->id}" : ($good->sku ?: "g_{$good->id}")));
    }

    private function price(ShopGood $good, ?ShopGoodVariation $variation): float
    {
        $base = (float) ($variation?->price ?? $good->price);
        $sale = (float) ($variation?->sale_price ?? $good->sale_price);
        return $sale > 0 && $sale < $base ? $sale : $base;
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
        return collect($mapping?->attribute_mappings ?? [])->map(function (array $item) use ($good, $variation) {
            $source = $item['source'] ?? '';
            if ($source === '') return null;
            $value = $source === 'static'
                ? ($item['value'] ?? '')
                : ($source === 'variation'
                    ? data_get($variation, $item['source_key'] ?? '')
                    : data_get($good, $item['source_key'] ?? ''));
            if ($value === null || $value === '') return null;
            $dictionaryId = $item['dictionary_value_id'] ?? data_get($item, 'dictionary_map.'.(string) $value);
            $attributeValue = $dictionaryId
                ? ['dictionary_value_id' => (int) $dictionaryId, 'value' => (string) $value]
                : ['value' => (string) $value];
            return ['id' => (int) ($item['id'] ?? 0), 'complex_id' => 0, 'values' => [$attributeValue]];
        })->filter(fn ($item) => $item && $item['id'] > 0)->values()->all();
    }

    private function validate(array $payload, ?ShopOzonCategoryMapping $mapping): array
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
        }
        return $errors;
    }
}
