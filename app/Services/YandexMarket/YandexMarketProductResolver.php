<?php

namespace App\Services\YandexMarket;

use App\Models\ShopGood;
use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketCategoryMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class YandexMarketProductResolver
{
    public function query(ShopYandexMarketAccount $account, ?array $goodIds = null): Builder
    {
        $query = ShopGood::query()->with([
            'categories:id,name,parent_id', 'tags:id,name', 'brands:id,name',
            'properties:id,name', 'properties.values:id,property_id,value', 'images',
            'variations.images', 'variations.attributeValues.attribute',
        ])->where('is_active', true);

        if ($goodIds) $query->whereIn('id', $goodIds);
        $mappings = $this->mappings($account);
        if ($mappings->isEmpty()) return $query->whereRaw('1 = 0');

        $query->where(function (Builder $rules) use ($mappings, $account) {
            foreach ($mappings as $mapping) {
                $tagId = $this->selectionTagId($mapping, $account);
                if (! $tagId || $mapping->categoryIds()->isEmpty()) continue;
                $rules->orWhere(function (Builder $rule) use ($mapping, $tagId) {
                    $rule->whereHas('tags', fn (Builder $tags) => $tags->where('shop_tags.id', $tagId))
                        ->whereHas('categories', fn (Builder $categories) => $categories->whereIn('shop_categories.id', $mapping->categoryIds()->all()));
                });
            }
        });

        return $query;
    }

    public function mappings(ShopYandexMarketAccount $account): Collection
    {
        return ShopYandexMarketCategoryMapping::query()->with('selectionTag:id,name')->where('account_id', $account->id)->where('is_active', true)->get();
    }

    public function mappingFor(ShopGood $good, Collection $mappings, ShopYandexMarketAccount $account): ?ShopYandexMarketCategoryMapping
    {
        $ids = $good->categories->pluck('id')->map(fn ($id) => (int) $id);
        return $mappings->first(fn ($mapping) => $ids->intersect($mapping->categoryIds())->isNotEmpty()
            && $good->tags->contains('id', $this->selectionTagId($mapping, $account)));
    }

    public function rowsForGood(ShopGood $good, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        $variations = $good->variations->where('is_active', true)->values();
        if ($variations->isEmpty()) return [['good' => $good, 'variation' => null, 'mapping' => $mapping, 'row_errors' => []]];

        $rows = $variations->map(fn ($variation) => [
            'good' => $good, 'variation' => $variation, 'mapping' => $mapping, 'row_errors' => [],
        ])->all();

        if (count($rows) < 2) return $rows;

        $axes = collect($mapping?->attribute_mappings ?? [])->where('distinctive', true);
        if ($axes->isEmpty()) {
            foreach ($rows as &$row) $row['row_errors'][] = 'Для категории не сопоставлены отличительные характеристики вариаций Яндекс Маркета.';
            unset($row);
            return $rows;
        }

        $signatures = [];
        foreach ($rows as $index => &$row) {
            $values = $axes->map(fn ($axis) => (string) $this->sourceValue($axis, $good, $row['variation']))->all();
            if (in_array('', $values, true)) $row['row_errors'][] = 'Не заполнены все характеристики, отличающие вариации.';
            $signature = mb_strtolower(implode('|', $values));
            if ($signature !== '' && isset($signatures[$signature])) {
                $error = 'Комбинация отличительных характеристик повторяет другую активную вариацию.';
                $row['row_errors'][] = $error;
                $rows[$signatures[$signature]]['row_errors'][] = $error;
            } else $signatures[$signature] = $index;
        }
        unset($row);

        return $rows;
    }

    public function selectionTagId(ShopYandexMarketCategoryMapping $mapping, ShopYandexMarketAccount $account): ?int
    {
        return (int) ($mapping->selection_tag_id ?: $account->selection_tag_id) ?: null;
    }

    public function sourceValue(array $mapping, ShopGood $good, $variation): mixed
    {
        $source = $mapping['source'] ?? '';
        $value = match ($source) {
            'static' => $mapping['value'] ?? '',
            'brand' => $good->brands->first()?->name ?? '',
            'variation_attribute' => $this->variationValue($variation, (int) ($mapping['source_key'] ?? 0)),
            'property' => $this->propertyValue($good, (int) ($mapping['source_key'] ?? 0)),
            'dimension_weight' => $this->firstPositive($good->shipping_weight, $good->weight),
            'dimension_length' => $this->firstPositive($good->shipping_length, $good->length, $good->depth),
            'dimension_width' => $this->firstPositive($good->shipping_width, $good->width),
            'dimension_height' => $this->firstPositive($good->shipping_height, $good->height),
            default => data_get($variation, $mapping['source_key'] ?? '') ?: data_get($good, $mapping['source_key'] ?? ''),
        };

        if (str_starts_with($source, 'dimension_') && is_numeric($value)) $value *= max(0.000001, (float) ($mapping['multiplier'] ?? 1));
        return is_string($value) ? $this->decoded($value) : $value;
    }

    public function variationSummary($variation): array
    {
        if (! $variation) return [];
        return $variation->attributeValues->map(fn ($value) => [
            'attribute_id' => (int) $value->attribute_id,
            'name' => $this->decoded($value->attribute?->name),
            'value' => $this->decoded($value->value),
        ])->values()->all();
    }

    private function variationValue($variation, int $attributeId): string
    {
        return $this->decoded($variation?->attributeValues->first(fn ($value) => (int) $value->attribute_id === $attributeId)?->value);
    }

    private function propertyValue(ShopGood $good, int $propertyId): string
    {
        $property = $good->properties->firstWhere('id', $propertyId);
        if (! $property) return '';
        $valueId = (int) ($property->pivot?->shop_property_value_id ?? 0);
        return $this->decoded($valueId ? $property->values->firstWhere('id', $valueId)?->value : $property->pivot?->value);
    }

    private function firstPositive(mixed ...$values): float
    {
        foreach ($values as $value) if (is_numeric($value) && (float) $value > 0) return (float) $value;
        return 0;
    }

    private function decoded(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        for ($i = 0; $i < 5; $i++) { $next = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); if ($next === $value) break; $value = $next; }
        return trim($value);
    }
}
