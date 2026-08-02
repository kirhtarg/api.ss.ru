<?php

namespace App\Services\Partner;

use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PartnerCatalogService
{
    public function __construct(private readonly PartnerStockAvailabilityService $availability) {}

    public function activeCategories()
    {
        return ShopCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function activeBrands(bool $includeUnavailable = false)
    {
        return ShopBrand::query()->active()->whereHas(
            'goods',
            fn (Builder $goods) => $includeUnavailable
                ? $goods->where('is_active', true)
                : $this->availability->applyCatalogEligibleQuery($goods),
        )->ordered()->get();
    }

    public function categories(array $filters = [])
    {
        return $this->applySyncFilters(ShopCategory::query(), $filters)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate($this->perPage($filters));
    }

    public function products(array $filters)
    {
        $query = $this->applySyncFilters(ShopGood::query(), $filters);
        if (empty($filters['updated_since']) && empty($filters['include_unavailable'])) {
            $this->availability->applyCatalogEligibleQuery($query);
        } elseif (empty($filters['updated_since'])) {
            Log::info('[FIX:partner-admin-catalog] Including unavailable products for partner administration', [
                'resource' => $query->getModel()->getTable(),
            ]);
        }

        return $query
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'categories',
                fn ($categories) => $categories->where('shop_categories.id', $id),
            ))
            ->when($filters['brand_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'brands', fn ($brands) => $brands->where('shop_brands.id', $id),
            ))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(
                fn ($search) => $search->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"),
            ))
            ->with([
                'images' => fn ($query) => $query->orderByDesc('is_main')->orderBy('sort_order'),
                'variations.images',
                'variations.stock',
                'variations.attributeValues.attribute',
                'variations.good',
                'categories',
                'brands',
                'properties.values',
                'stock',
            ])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate($this->perPage($filters));
    }

    public function product(ShopGood $good): ShopGood
    {
        $good = $this->availability->applyCatalogEligibleQuery(ShopGood::query())->whereKey($good->id)->firstOrFail();

        return $good->load([
            'images', 'variations.images', 'variations.stock',
            'variations.attributeValues.attribute', 'variations.good',
            'categories', 'brands', 'properties.values', 'stock',
        ]);
    }

    private function applySyncFilters(Builder $query, array $filters): Builder
    {
        $updatedSince = $filters['updated_since'] ?? null;
        if ($updatedSince) {
            $query->where('updated_at', '>=', Carbon::parse($updatedSince)->format('Y-m-d H:i:s.u'));
        } else {
            $query->where('is_active', true);
        }

        if (! empty($filters['cursor'])) {
            [$updatedAt, $id] = $this->decodeCursor($filters['cursor']);
            $query->where(function (Builder $cursorQuery) use ($updatedAt, $id): void {
                $cursorQuery->where('updated_at', '>', $updatedAt)
                    ->orWhere(fn (Builder $sameTimestamp) => $sameTimestamp
                        ->where('updated_at', $updatedAt)
                        ->where('id', '>', $id));
            });
        }

        Log::debug('Partner catalog synchronization query prepared', [
            'resource' => $query->getModel()->getTable(),
            'updated_since' => $updatedSince,
            'has_cursor' => ! empty($filters['cursor']),
            'per_page' => $this->perPage($filters),
        ]);

        return $query;
    }

    public function cursorFor(?object $model): ?string
    {
        if (! $model?->updated_at) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            $model->updated_at->format('Y-m-d H:i:s.u'),
            (int) $model->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): array
    {
        $normalized = strtr($cursor, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;
        if (! is_array($payload) || count($payload) !== 2 || ! is_string($payload[0]) || ! is_numeric($payload[1])) {
            abort(422, 'Invalid catalog cursor');
        }

        return [$payload[0], (int) $payload[1]];
    }

    private function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 24), 1), 100);
    }
}
