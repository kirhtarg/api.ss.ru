<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use Illuminate\Support\Collection;

class DeliveryPackageService
{
    /**
     * Build carrier-neutral cargo places. Until an order is packed and confirmed,
     * regular units are combined by total volume. Products marked as separate
     * remain individual places. A warehouse-confirmed package plan always wins.
     */
    public function fromCartItems(array $items, object|array|null $settings = null): array
    {
        $defaults = $this->defaults($settings);
        $goods = $this->loadGoods($items);
        $variations = $this->loadVariations($items);
        $packages = [];
        $combinedUnits = [];

        foreach ($items as $itemIndex => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $good = $goods->get((int) ($item['good_id'] ?? 0));
            $variation = $variations->get((int) ($item['variation_id'] ?? 0));
            $dimensions = $this->resolveDimensions($item, $variation, $good, $defaults);
            $shipsSeparately = $this->shipsSeparately($item, $variation, $good);

            for ($unit = 1; $unit <= $quantity; $unit++) {
                $unitData = [
                    'dimensions' => $dimensions,
                    'item' => [
                        'item_index' => $itemIndex,
                        'good_id' => $item['good_id'] ?? null,
                        'variation_id' => $item['variation_id'] ?? null,
                        'quantity' => 1,
                        'weight' => $dimensions['weight'],
                        'length' => $dimensions['length'],
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ],
                ];

                if ($shipsSeparately) {
                    $packages[] = $this->makePackage($dimensions, [$unitData['item']], count($packages) + 1);
                } else {
                    $combinedUnits[] = $unitData;
                }
            }
        }

        if ($combinedUnits) {
            $totalWeight = array_sum(array_map(fn ($unit) => $unit['dimensions']['weight'], $combinedUnits));
            $maxLength = max(array_map(fn ($unit) => $unit['dimensions']['length'], $combinedUnits));
            $maxWidth = max(array_map(fn ($unit) => $unit['dimensions']['width'], $combinedUnits));
            $maxHeight = max(array_map(fn ($unit) => $unit['dimensions']['height'], $combinedUnits));
            $totalVolumeCm3 = array_sum(array_map(fn ($unit) =>
                $unit['dimensions']['length'] * $unit['dimensions']['width'] * $unit['dimensions']['height'],
                $combinedUnits
            ));
            $estimatedHeight = max($maxHeight, $totalVolumeCm3 / max(1, $maxLength * $maxWidth));
            $packages[] = $this->makePackage([
                'weight' => $totalWeight,
                'length' => $maxLength,
                'width' => $maxWidth,
                'height' => $estimatedHeight,
            ], array_column($combinedUnits, 'item'), count($packages) + 1);
        }

        foreach ($packages as $index => &$package) {
            $package['number'] = $index + 1;
        }
        unset($package);

        return $packages ?: [$this->makePackage($defaults, [], 1)];
    }

    public function fromOrder(ShopOrder $order, object|array|null $settings = null): array
    {
        $saved = $order->relationLoaded('packages') ? $order->packages : $order->packages()->get();

        if ($saved->isNotEmpty()) {
            return $saved->sortBy('number')->values()->map(fn ($package) => $this->makePackage([
                'weight' => $package->weight,
                'length' => $package->length,
                'width' => $package->width,
                'height' => $package->height,
            ], $package->items ?: [], (int) $package->number, $package->source))->all();
        }

        $items = is_array($order->items) ? $order->items : (json_decode((string) $order->items, true) ?: []);

        return $this->fromCartItems($items, $settings);
    }

    public function snapshotEstimatedForOrder(ShopOrder $order, object|array|null $settings = null): array
    {
        if ($order->packages()->exists()) {
            return $this->fromOrder($order, $settings);
        }

        $items = is_array($order->items) ? $order->items : (json_decode((string) $order->items, true) ?: []);
        $packages = $this->fromCartItems($items, $settings);

        foreach ($packages as $package) {
            $order->packages()->create([
                'number' => $package['number'],
                'weight' => $package['weight'],
                'length' => $package['length'],
                'width' => $package['width'],
                'height' => $package['height'],
                'source' => 'estimated',
                'items' => $package['items'],
            ]);
        }

        return $packages;
    }

    public function summary(array $packages): array
    {
        $totalWeight = array_sum(array_column($packages, 'weight'));
        $totalVolume = array_sum(array_column($packages, 'volume_m3'));

        return [
            'quantity' => count($packages),
            'total_weight' => round($totalWeight, 3),
            'total_volume' => round($totalVolume, 6),
            'max_weight' => round(max(array_column($packages, 'weight') ?: [0.1]), 3),
            'max_length' => round(max(array_column($packages, 'length') ?: [1]), 2),
            'max_width' => round(max(array_column($packages, 'width') ?: [1]), 2),
            'max_height' => round(max(array_column($packages, 'height') ?: [1]), 2),
            'total_volumetric_weight' => round(array_sum(array_column($packages, 'volumetric_weight')), 3),
            'total_billable_weight' => round(array_sum(array_column($packages, 'billable_weight')), 3),
        ];
    }

    public function dellinCargo(array $packages): array
    {
        $summary = $this->summary($packages);

        return [
            'quantity' => $summary['quantity'],
            'length' => max(0.01, round($summary['max_length'] / 100, 3)),
            'width' => max(0.01, round($summary['max_width'] / 100, 3)),
            'height' => max(0.01, round($summary['max_height'] / 100, 3)),
            'weight' => max(0.1, $summary['max_weight']),
            'totalVolume' => max(0.001, round($summary['total_volume'], 3)),
            'totalWeight' => max(0.1, $summary['total_weight']),
            'hazardClass' => 0,
        ];
    }

    private function resolveDimensions(array $item, ?ShopGoodVariation $variation, ?ShopGood $good, array $defaults): array
    {
        return [
            'weight' => $this->firstPositive(
                $variation?->shipping_weight,
                $good?->shipping_weight,
                $item['shipping_weight'] ?? null,
                $variation?->weight,
                $good?->weight,
                $item['weight'] ?? null,
                $defaults['weight']
            ),
            'length' => $this->firstPositive(
                $variation?->shipping_length,
                $good?->shipping_length,
                $item['shipping_length'] ?? null,
                $variation?->length,
                $good?->depth,
                $item['length'] ?? ($item['depth'] ?? null),
                $defaults['length']
            ),
            'width' => $this->firstPositive(
                $variation?->shipping_width,
                $good?->shipping_width,
                $item['shipping_width'] ?? null,
                $variation?->width,
                $good?->width,
                $item['width'] ?? null,
                $defaults['width']
            ),
            'height' => $this->firstPositive(
                $variation?->shipping_height,
                $good?->shipping_height,
                $item['shipping_height'] ?? null,
                $variation?->height,
                $good?->height,
                $item['height'] ?? null,
                $defaults['height']
            ),
        ];
    }

    private function shipsSeparately(array $item, ?ShopGoodVariation $variation, ?ShopGood $good): bool
    {
        if ($variation && $variation->ships_separately !== null) {
            return (bool) $variation->ships_separately;
        }

        if ($good) {
            return (bool) $good->ships_separately;
        }

        return filter_var($item['ships_separately'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function makePackage(array $dimensions, array $items, int $number, string $source = 'estimated'): array
    {
        $weight = max(0.001, (float) $dimensions['weight']);
        $length = max(1, (float) $dimensions['length']);
        $width = max(1, (float) $dimensions['width']);
        $height = max(1, (float) $dimensions['height']);
        $volume = ($length / 100) * ($width / 100) * ($height / 100);
        $volumetricWeight = ($length * $width * $height) / 5000;

        return [
            'number' => $number,
            'weight' => round($weight, 3),
            'length' => round($length, 2),
            'width' => round($width, 2),
            'height' => round($height, 2),
            'volume_m3' => round($volume, 6),
            'volumetric_weight' => round($volumetricWeight, 3),
            'billable_weight' => round(max($weight, $volumetricWeight), 3),
            'source' => $source,
            'items' => $items,
        ];
    }

    private function defaults(object|array|null $settings): array
    {
        $read = static function (string $key, mixed $fallback) use ($settings): mixed {
            if (is_array($settings)) {
                return $settings[$key] ?? $fallback;
            }

            return $settings?->{$key} ?? $fallback;
        };

        return [
            'weight' => $this->firstPositive($read('default_weight', 0.5), 0.5),
            'length' => $this->firstPositive($read('default_length', 10), 10),
            'width' => $this->firstPositive($read('default_width', 10), 10),
            'height' => $this->firstPositive($read('default_height', 10), 10),
        ];
    }

    private function loadGoods(array $items): Collection
    {
        $ids = collect($items)->pluck('good_id')->filter()->map(fn ($id) => (int) $id)->unique();

        return $ids->isEmpty() ? collect() : ShopGood::whereIn('id', $ids)->get()->keyBy('id');
    }

    private function loadVariations(array $items): Collection
    {
        $ids = collect($items)->pluck('variation_id')->filter()->map(fn ($id) => (int) $id)->unique();

        return $ids->isEmpty() ? collect() : ShopGoodVariation::whereIn('id', $ids)->get()->keyBy('id');
    }

    private function firstPositive(mixed ...$values): float
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        return 1.0;
    }
}
