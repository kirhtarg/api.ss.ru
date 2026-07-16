<?php

namespace App\Services\Ozon;

use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OzonProductResolver
{
    public function query(ShopOzonAccount $account, ?array $goodIds = null): Builder
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

        if ($account->selection_tag_id) {
            $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('shop_tags.id', $account->selection_tag_id));
        } else {
            // An unconfigured account must never accidentally publish the whole catalogue.
            $query->whereRaw('1 = 0');
        }

        $categoryIds = $this->mappings($account)
            ->flatMap(fn (ShopOzonCategoryMapping $mapping) => $mapping->categoryIds())
            ->unique()
            ->values();
        if ($categoryIds->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('categories', fn (Builder $categoryQuery) => $categoryQuery->whereIn('shop_categories.id', $categoryIds));
        }

        return $query;
    }

    public function mappings(ShopOzonAccount $account): Collection
    {
        return ShopOzonCategoryMapping::query()
            ->where('account_id', $account->id)
            ->where('is_active', true)
            ->get();
    }

    public function mappingFor(ShopGood $good, Collection $mappings): ?ShopOzonCategoryMapping
    {
        $categoryIds = $good->categories->pluck('id')->map(fn ($id) => (int) $id);

        return $mappings->first(fn (ShopOzonCategoryMapping $mapping) => $categoryIds->intersect($mapping->categoryIds())->isNotEmpty());
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
        foreach ($rows as $index => &$row) {
            $values = collect($mapping->variation_attribute_mappings)
                ->map(fn (array $axis) => $this->variationAttributeValue($row['variation'], (int) ($axis['local_attribute_id'] ?? 0)))
                ->all();
            $signature = mb_strtolower(implode('|', $values));
            if (in_array('', $values, true)) {
                $row['row_errors'][] = 'Не заполнены все сопоставленные характеристики вариации.';
            }
            if ($signature !== '' && isset($signatures[$signature])) {
                $row['row_errors'][] = 'Комбинация характеристик вариации повторяет другую активную вариацию товара.';
                $rows[$signatures[$signature]]['row_errors'][] = 'Комбинация характеристик вариации повторяет другую активную вариацию товара.';
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
