<?php

namespace App\Services\Ozon;

use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OzonProductResolver
{
    public function query(ShopOzonAccount $account, ?array $goodIds = null, ?array $mappingIds = null): Builder
    {
        $query = ShopGood::query()
            ->with([
                'categories:id,name,parent_id',
                'tags:id,name',
                'brands:id,name',
                'properties:id,name',
                'properties.values:id,property_id,value',
                'images',
                'variations.images',
                'variations.attributeValues.attribute',
            ])
            ->where('is_active', true);

        if ($goodIds) {
            $query->whereIn('id', $goodIds);
        }

        $mappings = $this->mappings($account, $mappingIds);
        if ($mappings->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            // A profile is a complete publication rule: local category plus its own tag.
            $query->where(function (Builder $profileQuery) use ($mappings, $account) {
                foreach ($mappings as $mapping) {
                    $tagId = $this->effectiveSelectionTagId($mapping, $account);
                    $categoryIds = $mapping->categoryIds();
                    if (! $tagId || $categoryIds === []) continue;

                    $profileQuery->orWhere(function (Builder $ruleQuery) use ($tagId, $categoryIds) {
                        $ruleQuery
                            ->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('shop_tags.id', $tagId))
                            ->whereHas('categories', fn (Builder $categoryQuery) => $categoryQuery->whereIn('shop_categories.id', $categoryIds));
                    });
                }
            });
        }

        return $query;
    }

    public function mappings(ShopOzonAccount $account, ?array $mappingIds = null): Collection
    {
        $query = ShopOzonCategoryMapping::query()
            ->where('account_id', $account->id)
            ->where('is_active', true);

        if ($mappingIds !== null) {
            $query->whereIn('id', array_values(array_unique(array_map('intval', $mappingIds))));
        }

        return $query->get();
    }

    public function mappingFor(ShopGood $good, Collection $mappings, ShopOzonAccount $account): ?ShopOzonCategoryMapping
    {
        $categoryIds = $good->categories->pluck('id')->map(fn ($id) => (int) $id);
        $tagIds = $good->tags->pluck('id')->map(fn ($id) => (int) $id);

        return $mappings->first(function (ShopOzonCategoryMapping $mapping) use ($categoryIds, $tagIds, $account) {
            $tagId = $this->effectiveSelectionTagId($mapping, $account);

            return $tagId
                && $tagIds->contains($tagId)
                && $categoryIds->intersect($mapping->categoryIds())->isNotEmpty();
        });
    }

    public function effectiveSelectionTagId(ShopOzonCategoryMapping $mapping, ShopOzonAccount $account): ?int
    {
        $tagId = $mapping->selection_tag_id ?: $account->selection_tag_id;
        return $tagId ? (int) $tagId : null;
    }

    public function effectiveWarehouseId(ShopOzonCategoryMapping $mapping, ShopOzonAccount $account): ?string
    {
        $warehouseId = trim((string) ($mapping->warehouse_id ?: $account->warehouse_id));
        return $warehouseId !== '' ? $warehouseId : null;
    }

    public function rowsForGood(ShopGood $good, ?ShopOzonCategoryMapping $mapping): array
    {
        if (($mapping?->variation_mode ?? 'grouped') === 'single') {
            return [['good' => $good, 'variation' => null, 'mapping' => $mapping, 'row_errors' => []]];
        }

        $variations = $good->variations->where('is_active', true)->values();
        if ($variations->isEmpty()) {
            return [['good' => $good, 'variation' => null, 'mapping' => $mapping, 'row_errors' => []]];
        }

        $rows = $variations->map(fn ($variation) => [
            'good' => $good,
            'variation' => $variation,
            'mapping' => $mapping,
            'row_errors' => [],
        ])->all();

        if (($mapping?->variation_mode ?? 'grouped') !== 'grouped' || count($rows) < 2) {
            return $rows;
        }

        if (! $mapping?->group_attribute_id) {
            foreach ($rows as &$row) {
                $row['row_errors'][] = 'Для объединения вариаций не выбрана характеристика Ozon «Название модели».';
            }
            unset($row);
        }

        if (empty($mapping?->variation_attribute_mappings)) {
            foreach ($rows as &$row) {
                $row['row_errors'][] = 'Для объединения не сопоставлена ни одна характеристика вариации.';
            }
            unset($row);
            return $rows;
        }

        $signatures = [];
        $variationMappings = collect($mapping->variation_attribute_mappings);
        $mappedAttributeIds = $variationMappings->pluck('local_attribute_id')->map(fn ($id) => (int) $id);
        foreach ($rows as $index => &$row) {
            $values = $variationMappings
                ->map(fn (array $axis) => $this->variationAttributeValue($row['variation'], (int) ($axis['local_attribute_id'] ?? 0)))
                ->all();
            $signature = mb_strtolower(implode('|', $values));
            if (in_array('', $values, true)) {
                $row['row_errors'][] = 'Не заполнены все сопоставленные характеристики вариации.';
            }
            if ($signature !== '' && isset($signatures[$signature])) {
                $combination = $variationMappings->map(function (array $axis, int $axisIndex) use ($values) {
                    $name = trim((string) ($axis['local_attribute_name'] ?? '')) ?: 'Характеристика '.($axisIndex + 1);
                    return $name.': '.($values[$axisIndex] ?? 'не задано');
                })->implode(', ');
                $unmappedNames = $row['variation']->attributeValues
                    ->reject(fn ($value) => $mappedAttributeIds->contains((int) $value->attribute_id))
                    ->map(fn ($value) => trim((string) ($value->attribute?->name ?? '')))
                    ->filter()
                    ->unique()
                    ->implode(', ');
                $error = 'Повторяется передаваемая в Ozon комбинация: '.$combination.'.';
                if ($unmappedNames !== '') {
                    $error .= ' Локальные характеристики «'.$unmappedNames.'» сейчас не передаются. Сопоставьте их с соответствующими характеристиками Ozon.';
                }
                $row['row_errors'][] = $error;
                $rows[$signatures[$signature]]['row_errors'][] = $error;
            } else {
                $signatures[$signature] = $index;
            }
        }
        unset($row);

        return $rows;
    }

    public function variationAttributeValue($variation, int $attributeId): string
    {
        if (! $variation || $attributeId <= 0) {
            return '';
        }

        $value = $variation->attributeValues->first(fn ($item) => (int) $item->attribute_id === $attributeId);
        $decoded = trim((string) ($value?->value ?? ''));
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) break;
            $decoded = $next;
        }

        return trim($decoded);
    }
}
