<?php

namespace App\Services;

use App\Models\ShopGoodImage;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopBrand;
use App\Models\Shop\PropertyValue;
use App\Models\Shop\ShopGoodProperty;
use App\Models\ShopVariationAttribute;
use App\Models\ShopVariationAttributeValue;
use App\Models\SupplierCatalogFieldMapping;
use App\Models\SupplierCatalogImageAudit;
use App\Models\SupplierCatalogActionRun;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierCatalogSnapshot;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\AuditSupplierCatalogImagesJob;

class BikeproductsCatalogService
{
    private const IMAGE_AUDIT_BATCH_SIZE = 5;

    /** @var array{width: int, height: int}|null */
    private ?array $imageAuditTargetSize = null;

    /** @var array<string, int> */
    private array $variationAttributeIds = [];

    /** @param array<int, int> $itemIds */
    public function downloadAttachedImages(int $snapshotId, array $itemIds): void
    {
        $snapshot = SupplierCatalogSnapshot::find($snapshotId);
        if (! $snapshot || $snapshot->status !== 'ready') return;
        $this->ensureDefaultMappings($snapshot->supplier_code);
        $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
        $imageSourceFields = $this->imageSourceFields($snapshot->supplier_code);
        $items = $this->bindingParticipatingItems($snapshot->items()->whereIn('id', $itemIds)->get(), $snapshot->supplier_code);
        $matches = $this->resolveImageSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code)
            ->mapWithKeys(fn (array $match, string $sku) => [$this->normalizeSku($sku) => $match]);
        $sourceAudits = SupplierCatalogImageAudit::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('status', 'available')
            ->get(['source_url_hash', 'content_hash', 'perceptual_hash', 'width', 'height'])
            ->keyBy('source_url_hash');
        $contentAudit = $this->imageContentAuditStatus($snapshot);
        foreach ($items as $item) {
            $match = $matches->get($this->normalizeSku($item->external_sku));
            if (! $match) continue;
            $variation = $match['type'] === 'variation' ? $match['model'] : null;
            $good = $variation ? $variation->good : $match['model'];
            foreach ($this->imageSourcesForItem($item, $imageSourceFields, $imageBaseUrl) as $source) {
                $url = $source['url'];
                if ($contentAudit['status'] === 'completed' && ! $sourceAudits->has($this->sourceUrlHash($url))) continue;
                if (! str_starts_with($url, 'http')) continue;
                try {
                    $response = Http::timeout(45)->connectTimeout(10)->get($url);
                    if (! $response->successful() || $response->body() === '') throw new \RuntimeException('HTTP '.$response->status());
                    $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
                    $sourceAudit = $sourceAudits->get($this->sourceUrlHash($url));
                    $contentHash = $sourceAudit?->content_hash ?? hash('sha256', $response->body());
                    $relativePath = 'images/shop/goods/supplier/'.$contentHash.'.'.$extension;
                    $absolutePath = frontend_public_path($relativePath);
                    if (! is_dir(dirname($absolutePath))) mkdir(dirname($absolutePath), 0755, true);
                    if (! is_file($absolutePath)) file_put_contents($absolutePath, $response->body());
                    $imageQuery = ShopGoodImage::query()->where('file_path', $url);
                    if ($variation) {
                        $imageQuery->where('variation_id', $variation->id);
                    } else {
                        $imageQuery->where('good_id', $good->id)->whereNull('variation_id');
                    }
                    $imageQuery->update([
                        'file_path' => '/'.$relativePath,
                        'source_content_hash' => $contentHash,
                        'content_hash' => $contentHash,
                        'perceptual_hash' => $sourceAudit?->perceptual_hash ?? $this->perceptualImageHash($response->body()),
                        'image_width' => $sourceAudit?->width,
                        'image_height' => $sourceAudit?->height,
                        'content_checked_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    Log::warning('Supplier image download failed', ['snapshot_id' => $snapshotId, 'sku' => $item->external_sku, 'url' => $url, 'error' => $exception->getMessage()]);
                }
            }
        }
    }

    /** @return array{affected: int, skipped: int, message: string} */
    public function applyGoodAction(SupplierCatalogSnapshot $snapshot, string $action, array $ids): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);

        if ($action === 'delete') {
            $supplierNames = $this->supplierNames($snapshot->supplier_code);
            $sourceSkus = $snapshot->items()
                ->get(['external_sku', 'raw_payload'])
                ->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code))
                ->filter()
                ->map(fn ($sku) => (string) $sku)
                ->all();
            $goods = ShopGood::query()
                ->whereIn('id', $ids)
                ->whereIn('supplier', $supplierNames)
                ->with('variations:id,good_id,sku')
                ->get()
                ->filter(function (ShopGood $good) use ($sourceSkus): bool {
                    if (in_array((string) $good->sku, $sourceSkus, true)) {
                        return false;
                    }

                    return $good->variations->every(fn (ShopGoodVariation $variation) => ! in_array((string) $variation->sku, $sourceSkus, true));
                });

            DB::transaction(function () use ($goods): void {
                $goods->each->delete();
            });

            return ['affected' => $goods->count(), 'skipped' => count($ids) - $goods->count(), 'message' => 'Товары, отсутствующие в файле, удалены'];
        }

        if ($action !== 'create') {
            throw new \InvalidArgumentException('Неизвестное действие над товарами.');
        }

        $items = $this->bindingParticipatingItems($snapshot->items()->whereIn('id', $ids)->get(), $snapshot->supplier_code);
        $sourceSkus = $items->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code));
        $matches = $this->resolveSkuMatches($sourceSkus, $snapshot->supplier_code)
            ->mapWithKeys(fn (array $match, string $sku) => [$this->normalizeSku($sku) => $match]);
        $goodMappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'good')
            ->where('is_check_enabled', true)
            ->get();
        $propertyMappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'product')
            ->where('is_check_enabled', true)
            ->whereNotNull('property_id')
            ->with('property:id,property_type')
            ->get();
        $supplierName = $this->supplierNames($snapshot->supplier_code)[0] ?? $snapshot->supplier_code;
        $sourceVariationItems = $this->sourceVariationItems($snapshot);
        $imageSourceFields = $this->imageSourceFields($snapshot->supplier_code);
        $sourceGroupCounts = $sourceVariationItems
            ->groupBy(fn (SupplierCatalogItem $item) => $item->clean_name ?: $this->sourceGroupName($item->name, $item->external_sku))
            ->map(fn (Collection $group) => $group->count())
            ->all();
        $sourceVariationSkus = $sourceVariationItems->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code))->filter()->unique()->values();
        $existingVariationsBySku = $this->findVariationsBySku(
            $sourceVariationSkus,
            $snapshot->supplier_code,
        );
        $existingGoodsBySku = ShopGood::query()
            ->where('supplier', $supplierName)
            ->whereIn(DB::raw('TRIM(`sku`)'), $sourceVariationSkus->all())
            ->get()
            ->keyBy(fn (ShopGood $good) => $this->normalizeSku($good->sku));
        $parentGoodsBySourceGroup = [];
        foreach ($sourceVariationItems as $sourceVariationItem) {
            $sourceSku = $this->sourceSkuForItem($sourceVariationItem, $snapshot->supplier_code);
            $existingVariation = $existingVariationsBySku->get($this->normalizeSku($sourceSku));
            $existingGood = $existingVariation?->good ?? $existingGoodsBySku->get($this->normalizeSku($sourceSku));
            if ($existingGood) {
                $groupKey = $sourceVariationItem->clean_name
                    ?: $this->sourceGroupName($sourceVariationItem->name, $sourceVariationItem->external_sku);
                $parentGoodsBySourceGroup[$groupKey] = $existingGood;
            }
        }
        $affected = 0;
        $skipped = 0;

        $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
        $propertySyncedGoodIds = [];
        DB::transaction(function () use ($items, $matches, $goodMappings, $propertyMappings, $supplierName, $snapshot, $sourceGroupCounts, $imageBaseUrl, $imageSourceFields, &$parentGoodsBySourceGroup, &$propertySyncedGoodIds, &$affected, &$skipped): void {
            foreach ($items as $item) {
                $payload = $item->raw_payload ?? [];
                $attributes = $this->mappedGoodAttributes($item, $goodMappings);
                $name = $this->nullableString($attributes['name'] ?? null)
                    ?? $this->nullableString($item->clean_name)
                    ?? $this->nullableString($item->name);
                if ($name === null || $item->external_sku === null) {
                    $skipped++;
                    continue;
                }

                $sourceSku = $this->sourceSkuForItem($item, $snapshot->supplier_code);
                $isSourceVariation = $this->isSourceVariationItem($item);
                $match = $matches->get($this->normalizeSku($sourceSku));
                if (! $isSourceVariation && $match) {
                    $good = $match['type'] === 'variation' ? $match['model']->good : $match['model'];
                    $values = collect($attributes)->except('brand')->all();
                    if ($values !== []) {
                        $good->update($values);
                    }
                    $brand = $this->nullableString($attributes['brand'] ?? null);
                    if ($brand !== null) {
                        $good->brands()->syncWithoutDetaching([ShopBrand::firstOrCreate(['name' => $brand], ['is_active' => true])->id]);
                    }
                    $this->syncMappedProperties($good, $payload, $propertyMappings, true);
                    $propertySyncedGoodIds[$good->id] = true;
                    $affected++;
                    continue;
                }
                if ($isSourceVariation && $match && $match['type'] === 'variation') {
                    $skipped++;
                    continue;
                }

                if ($isSourceVariation) {
                    if (ShopGoodVariation::query()->where('sku', $sourceSku)->exists()) {
                        $skipped++;
                        continue;
                    }
                    $groupKey = $item->clean_name ?: $this->sourceGroupName($item->name, $item->external_sku);
                    $parentSku = $this->nullableString($item->external_group_key) ?? (string) $sourceSku;
                    $good = $match && $match['type'] === 'good' ? $match['model'] : ($parentGoodsBySourceGroup[$groupKey] ?? null);
                    if (! $good && $item->clean_name) {
                        $candidates = ShopGood::query()->where('supplier', $supplierName)->where('name', $item->clean_name)->doesntHave('variations')->limit(2)->get();
                        $good = $candidates->count() === 1 ? $candidates->first() : null;
                    }
                    if (($sourceGroupCounts[$groupKey] ?? 0) === 1 && $good && ! ShopGoodVariation::query()->where('good_id', $good->id)->exists()) {
                        $values = collect($attributes)->except('brand')->all();
                        if ($values !== []) {
                            $good->update($values);
                        }
                        $brand = $this->nullableString($attributes['brand'] ?? null);
                        if ($brand !== null) {
                            $good->brands()->syncWithoutDetaching([ShopBrand::firstOrCreate(['name' => $brand], ['is_active' => true])->id]);
                        }
                        $this->syncMappedProperties($good, $payload, $propertyMappings, true);
                        $propertySyncedGoodIds[$good->id] = true;
                        $affected++;
                        continue;
                    }
                    if (! $good) {
                        $good = ShopGood::query()->where('supplier', $supplierName)->where('sku', $parentSku)->first();
                    }
                    if (! $good && $item->clean_name) {
                        $candidates = ShopGood::query()->where('supplier', $supplierName)->where('name', $item->clean_name)->doesntHave('variations')->limit(2)->get();
                        $good = $candidates->count() === 1 ? $candidates->first() : null;
                    }
                    if (! $good) {
                        $good = $this->createMappedGood($name, $parentSku, $supplierName, $attributes, $payload, $propertyMappings);
                        $propertySyncedGoodIds[$good->id] = true;
                    }
                    if (! isset($propertySyncedGoodIds[$good->id])) {
                        $this->syncMappedProperties($good, $payload, $propertyMappings, true);
                        $propertySyncedGoodIds[$good->id] = true;
                    }
                    $parentGoodsBySourceGroup[$groupKey] = $good;
                    $variation = ShopGoodVariation::create([
                        'good_id' => $good->id,
                        'supplier' => $supplierName,
                        'sku' => $sourceSku,
                        'name' => $name,
                        'is_active' => false,
                        ...collect($attributes)->only(['price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'])->all(),
                    ]);
                    $axes = $this->creationAxesForItem($item, $snapshot->supplier_code);
                    $valueIds = collect($axes)->map(fn (array $axis) => ShopVariationAttributeValue::firstOrCreate([
                        'attribute_id' => $axis['attribute_id'],
                        'value' => $axis['value'],
                    ])->id)->all();
                    $variation->attributeValues()->sync($valueIds);
                    $this->attachSourceImages($good, $variation, $item, $imageBaseUrl, $imageSourceFields);
                    $affected++;
                    continue;
                }

                $good = $this->createMappedGood($name, (string) $sourceSku, $supplierName, $attributes, $payload, $propertyMappings);
                $variation = ShopGoodVariation::create([
                    'good_id' => $good->id,
                    'supplier' => $supplierName,
                    'sku' => $sourceSku,
                    'name' => $name,
                    'is_active' => false,
                    ...collect($attributes)->only(['price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'])->all(),
                ]);
                $valueIds = collect($this->creationAxesForItem($item, $snapshot->supplier_code))->map(fn (array $axis) => ShopVariationAttributeValue::firstOrCreate([
                    'attribute_id' => $axis['attribute_id'],
                    'value' => $axis['value'],
                ])->id)->all();
                $variation->attributeValues()->sync($valueIds);
                $this->attachSourceImages($good, $variation, $item, $imageBaseUrl, $imageSourceFields);
                $affected++;
            }
        });

        return ['affected' => $affected, 'skipped' => $skipped, 'message' => 'Товары из файла созданы как скрытые'];
    }

    /** @return array{affected: int, skipped: int, message: string} */
    public function applyMappedUpdate(SupplierCatalogSnapshot $snapshot, string $scope, array $itemIds, string $imageMode = 'append'): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        $items = $this->bindingParticipatingItems($snapshot->items()->whereIn('id', $itemIds)->get(), $snapshot->supplier_code);
        $matches = $this->resolveSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
        $affected = 0;
        $skipped = 0;

        if ($scope === 'goods') {
            $mappings = SupplierCatalogFieldMapping::query()->where('supplier_code', $snapshot->supplier_code)->where('scope', 'good')->where('is_check_enabled', true)->get();
            DB::transaction(function () use ($items, $matches, $mappings, &$affected, &$skipped): void {
                foreach ($items as $item) {
                    $match = $matches->get($item->external_sku);
                    if (! $match) { $skipped++; continue; }
                    $good = $match['type'] === 'variation' ? $match['model']->good : $match['model'];
                    $values = collect($this->mappedGoodAttributes($item, $mappings))->only(['name', 'short_description', 'description', 'weight', 'depth', 'width', 'height', 'price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'])->all();
                    if ($values === []) { $skipped++; continue; }
                    $good->update($values);
                    $brand = $this->nullableString($this->mappedGoodAttributes($item, $mappings)['brand'] ?? null);
                    if ($brand !== null) $good->brands()->sync([ShopBrand::firstOrCreate(['name' => $brand], ['is_active' => true])->id]);
                    $affected++;
                }
            });
            return ['affected' => $affected, 'skipped' => $skipped, 'message' => 'Поля товаров обновлены'];
        }

        if (in_array($scope, ['prices', 'stocks'], true)) {
            $mappings = SupplierCatalogFieldMapping::query()->where('supplier_code', $snapshot->supplier_code)->where('scope', 'good')->where('is_check_enabled', true)->get();
            $targets = $scope === 'prices'
                ? ['price', 'sale_price', 'demping_price']
                : ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'];
            $priceStockMatches = $this->resolveSkuMatches(
                $items->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code)),
                $snapshot->supplier_code,
                true,
            );
            DB::transaction(function () use ($items, $priceStockMatches, $snapshot, $mappings, $targets, &$affected, &$skipped): void {
                foreach ($items as $item) {
                    $sourceSku = $this->sourceSkuForItem($item, $snapshot->supplier_code);
                    $match = $priceStockMatches->get($sourceSku);
                    if (! $match) { $skipped++; continue; }
                    $values = collect($this->mappedGoodAttributes($item, $mappings))->only($targets)->all();
                    if ($values === []) { $skipped++; continue; }
                    $model = $match['model'];
                    $model->update($values);
                    $affected++;
                }
            });
            return ['affected' => $affected, 'skipped' => $skipped, 'message' => $scope === 'prices' ? 'Цены обновлены' : 'Остатки обновлены'];
        }
        if ($scope === 'properties') {
            $mappings = SupplierCatalogFieldMapping::query()->where('supplier_code', $snapshot->supplier_code)->where('scope', 'product')->where('is_check_enabled', true)->whereNotNull('property_id')->get();
            $differentItemIds = array_flip($this->propertySelection($snapshot));
            DB::transaction(function () use ($items, $matches, $mappings, $differentItemIds, &$affected, &$skipped): void {
                foreach ($items as $item) {
                    $match = $matches->get($item->external_sku);
                    if (! $match || ! isset($differentItemIds[$item->id])) { $skipped++; continue; }
                    $good = $match['type'] === 'variation' ? $match['model']->good : $match['model'];
                    $actionableMappings = $mappings->filter(fn (SupplierCatalogFieldMapping $mapping) => $this->propertySourceValueFromPayload($item->raw_payload ?? [], $mapping) !== null);
                    if ($actionableMappings->isEmpty()) { $skipped++; continue; }
                    $this->syncMappedProperties($good, $item->raw_payload ?? [], $actionableMappings, true);
                    $affected++;
                }
            });
            return ['affected' => $affected, 'skipped' => $skipped, 'message' => 'Характеристики обновлены'];
        }

        if ($scope === 'images') {
            $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
            $imageSourceFields = $this->imageSourceFields($snapshot->supplier_code);
            $imageMatches = $this->resolveImageSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
            $contentAudit = $this->imageContentAuditStatus($snapshot);
            $sourceAudits = $contentAudit['status'] === 'completed'
                ? SupplierCatalogImageAudit::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->get(['source_url_hash', 'status', 'content_hash', 'perceptual_hash', 'normalized_crop_hash', 'normalized_white_hash'])
                    ->keyBy('source_url_hash')
                : collect();
            DB::transaction(function () use ($items, $imageMatches, $imageBaseUrl, $imageSourceFields, $imageMode, $contentAudit, $sourceAudits, &$affected, &$skipped): void {
                foreach ($items as $item) {
                    $match = $imageMatches->get($item->external_sku);
                    $allUrls = collect($this->imageSourcesForItem($item, $imageSourceFields, $imageBaseUrl))
                        ->pluck('url')
                        ->values();
                    if ($contentAudit['status'] !== 'completed') { $skipped++; continue; }
                    $urls = $allUrls->filter(fn (string $url) => $sourceAudits->get($this->sourceUrlHash($url))?->status === 'available')->values();
                    // With a completed audit, never erase working images when
                    // every source URL for this variation is broken.
                    if ($imageMode === 'replace' && $allUrls->isNotEmpty() && $urls->isEmpty()) { $skipped++; continue; }
                    if ($imageMode === 'reconcile' && $contentAudit['status'] !== 'completed') { $skipped++; continue; }
                    if (! $match || ($urls->isEmpty() && $imageMode !== 'replace')) { $skipped++; continue; }
                    $variation = $match['type'] === 'variation' ? $match['model'] : null;
                    $good = $variation ? $variation->good : $match['model'];
                    $query = $variation
                        ? ShopGoodImage::query()->where('variation_id', $variation->id)
                        : ShopGoodImage::query()->where('good_id', $good->id)->whereNull('variation_id');
                    $existingImages = $query->orderBy('sort_order')->orderBy('id')->get();
                    $existing = $existingImages->pluck('file_path')->all();
                    if ($imageMode === 'replace') {
                        $query->delete();
                        $existingImages = collect();
                        $existing = [];
                    }
                    if ($imageMode === 'reconcile') {
                        // A broken supplier URL is not evidence that the local
                        // image is obsolete. Keep existing bindings in that case.
                        $hasUnavailableSource = $allUrls->contains(function (string $url) use ($sourceAudits): bool {
                            return $sourceAudits->get($this->sourceUrlHash($url))?->status !== 'available';
                        });
                        $availableImages = $existingImages->values()->all();
                        $matchedImageIds = [];
                        $urlsToAdd = [];
                        foreach ($urls as $url) {
                            $sourceAudit = $sourceAudits->get($this->sourceUrlHash($url));
                            $matchIndex = $this->matchingDatabaseImageIndex($availableImages, $sourceAudit, $url);
                            if ($matchIndex === false) {
                                $urlsToAdd[] = $url;
                                continue;
                            }
                            $matchedImageIds[] = $availableImages[$matchIndex]->id;
                            unset($availableImages[$matchIndex]);
                        }
                        if (! $hasUnavailableSource && $availableImages !== []) {
                            ShopGoodImage::query()->whereIn('id', collect($availableImages)->pluck('id'))->delete();
                        }
                        $existing = $existingImages
                            ->whereIn('id', $matchedImageIds)
                            ->pluck('file_path')
                            ->all();
                        $urls = collect($urlsToAdd)->values();
                    }
                    $nextSort = (int) $query->max('sort_order') + 1;
                    foreach ($urls as $url) {
                        if (collect($existing)->contains(fn (string $path) => $this->sourceImageMatchesDatabasePath($url, $path))) continue;
                        ShopGoodImage::create([
                            'good_id' => $variation ? null : $good->id,
                            'variation_id' => $variation?->id,
                            'file_path' => $url,
                            'is_main' => $existing === [] && $nextSort === 1,
                            'sort_order' => $nextSort++,
                        ]);
                    }
                    $affected++;
                }
            });
            return ['affected' => $affected, 'skipped' => $skipped, 'message' => $imageMode === 'replace'
                ? 'Привязки изображений заменены'
                : ($imageMode === 'reconcile' ? 'Лишние привязки удалены, недостающие изображения добавлены' : 'Недостающие URL изображений привязаны')];
        }

        throw new \InvalidArgumentException('Неизвестная область обновления.');
    }

    /** @return array{affected: int, message: string} */
    public function applyVariationAction(SupplierCatalogSnapshot $snapshot, string $action, array $variationIds): array
    {
        $this->assertReadySnapshot($snapshot);
        $supplierNames = $this->supplierNames($snapshot->supplier_code);
        return DB::transaction(function () use ($snapshot, $action, $variationIds, $supplierNames): array {
            $variations = ShopGoodVariation::query()
                ->whereIn('id', $variationIds)
                ->where(function ($query) use ($supplierNames) {
                    $query->whereIn('supplier', $supplierNames)
                        ->orWhere(fn ($query) => $query->where(fn ($supplier) => $supplier->whereNull('supplier')->orWhere('supplier', ''))
                            ->whereHas('good', fn ($good) => $good->whereIn('supplier', $supplierNames)));
                })
                ->with(['good:id,sku,supplier', 'attributeValues.attribute'])
            ->lockForUpdate()
            ->get();
            if ($action === 'delete') {
                $parentGoods = $variations->pluck('good')->filter()->keyBy('id');
                $sourceSkus = $snapshot->items()
                    ->get(['external_sku', 'raw_payload'])
                    ->map(fn (SupplierCatalogItem $item) => $this->normalizeSku($this->sourceSkuForItem($item, $snapshot->supplier_code)))
                    ->filter()
                    ->flip();
                foreach ($variations as $variation) {
                    $variation->delete();
                }
                $deletedGoods = 0;
                foreach ($parentGoods as $good) {
                    $hasVariations = ShopGoodVariation::query()->where('good_id', $good->id)->exists();
                    $isInSource = $good->sku && $sourceSkus->has($this->normalizeSku($good->sku));
                    if (! $hasVariations && ! $isInSource && in_array($good->supplier, $supplierNames, true)) {
                        $good->delete();
                        $deletedGoods++;
                    }
                }
                return ['affected' => $variations->count(), 'message' => $deletedGoods
                    ? "Вариации удалены, удалено пустых товаров: {$deletedGoods}"
                    : 'Вариации удалены'];
            }
            if ($action === 'zero_remote_stocks') {
                foreach ($variations as $variation) {
                    $variation->update(['remote_stock_quantity' => null, 'fast_remote_stock_quantity' => null]);
                }
                return ['affected' => $variations->count(), 'message' => 'Остатки у/с вариаций обнулены'];
            }
            if ($action === 'zero_main_stock') {
                foreach ($variations as $variation) {
                    $variation->update(['stock_quantity' => 0]);
                }
                return ['affected' => $variations->count(), 'message' => 'Основной остаток вариаций обнулен'];
            }
            if ($action === 'zero_stocks') {
                foreach ($variations as $variation) {
                    $variation->update(['stock_quantity' => 0, 'remote_stock_quantity' => null, 'fast_remote_stock_quantity' => null]);
                }
                return ['affected' => $variations->count(), 'message' => 'Остатки вариаций обнулены'];
            }
            if (! in_array($action, ['repair_axes', 'add_missing_axes', 'replace_axis_values', 'remove_extra_axes'], true)) {
                throw new \InvalidArgumentException('Неизвестное действие над вариациями.');
            }

            $itemsBySku = $this->sourceVariationItems($snapshot)
                ->keyBy(fn (SupplierCatalogItem $item) => $this->normalizeSku($item->external_sku));
            $singleItemsBySku = $this->sourceSingleItems($snapshot)
                ->keyBy(fn (SupplierCatalogItem $item) => $this->normalizeSku($item->external_sku));
            $variationCountByGood = ShopGoodVariation::query()
                ->whereIn('good_id', $variations->pluck('good_id')->unique())
                ->get(['good_id'])
                ->countBy('good_id');
            $mappings = $this->getMappings($snapshot->supplier_code);
            $affected = 0;
            foreach ($variations as $variation) {
                $source = $itemsBySku->get($this->normalizeSku($variation->sku));
                $isSingleProductSource = false;
                if ($source === null && ($variationCountByGood->get($variation->good_id) ?? 0) === 1) {
                    $source = $singleItemsBySku->get($this->normalizeSku($variation->good?->sku))
                        ?? $singleItemsBySku->get($this->normalizeSku($variation->sku));
                    $isSingleProductSource = $source !== null;
                }
                if (! $source) {
                    continue;
                }
                $targetAxes = $isSingleProductSource
                    ? $this->creationAxesForItem($source, $snapshot->supplier_code)
                    : $this->sourceAxesForItem($source, $mappings, $snapshot->supplier_code);
                if ($targetAxes === []) {
                    continue;
                }
                $changed = $this->applyVariationAxisRepair($variation, $targetAxes, $action);
                if ($changed) {
                    $affected++;
                }
            }
            $message = match ($action) {
                'add_missing_axes' => 'Недостающие оси добавлены из файла',
                'replace_axis_values' => 'Значения осей заменены значениями из файла',
                'remove_extra_axes' => 'Лишние оси базы удалены',
                default => 'Оси вариаций полностью заменены значениями из файла',
            };
            return ['affected' => $affected, 'message' => $message];
        });
    }

    /** @var array<string, string> */
    private const SOURCE_COLUMNS = [
        'sku' => 'CML2_ARTICLE',
        'group' => 'CML2_LINK',
        'name' => 'NAME',
        'brand' => 'BRAND',
        'section' => 'SECTION',
        'sub_section' => 'SUB_SECTION',
        'price' => 'PRICE',
        'opt_price' => 'OPT_PRICE',
        'quantity' => 'QUANTITY',
        'in_stock' => 'IN_STOCK',
        'preview_image' => 'PREVIEW_PICTURE',
        'detail_image' => 'DETAIL_PICTURE',
        'more_images' => 'MORE_PHOTO',
    ];

    public function queueExcelImport(UploadedFile $file, string $supplierCode): SupplierCatalogSnapshot
    {
        $supplierCode = $this->normalizeSupplierCode($supplierCode);
        $this->ensureDefaultMappings($supplierCode);

        if (SupplierCatalogSnapshot::query()->where('supplier_code', $supplierCode)->exists()) {
            throw new \RuntimeException('Текущие разобранные данные уже существуют. Сначала очистите их в интерфейсе поставщика.');
        }

        $temporaryPath = 'supplier-catalog-tmp/'.$supplierCode.'/'.Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('local')->put($temporaryPath, file_get_contents($file->getRealPath()));

        $snapshot = SupplierCatalogSnapshot::create([
            'supplier_code' => $supplierCode,
            'source_type' => strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx',
            'source_name' => $file->getClientOriginalName(),
            'storage_path' => $temporaryPath,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'summary' => [
                'source_file_size' => $file->getSize(),
                'source_original_name' => $file->getClientOriginalName(),
            ],
            'status' => 'queued',
            'stage' => 'Файл поставлен в очередь',
        ]);

        return $snapshot;
    }

    public function processExcelSnapshot(int $snapshotId): void
    {
        $snapshot = SupplierCatalogSnapshot::find($snapshotId);
        if (! $snapshot || ! $snapshot->storage_path) {
            return;
        }

        $temporaryPath = $snapshot->storage_path;

        try {
            $path = Storage::disk('local')->path($snapshot->storage_path);
            if (! is_file($path)) {
                throw new \RuntimeException('Временный файл разбора не найден. Загрузите файл заново.');
            }
            $sourceLabel = $snapshot->source_type === 'csv' ? 'CSV' : 'Excel';
            $snapshot->update(['status' => 'processing', 'progress' => 1, 'stage' => 'Чтение '.$sourceLabel]);
            $itemsCount = 0;
            $sourceFileSize = is_file($path) ? filesize($path) : null;
            $sourceChecksum = is_file($path) ? hash_file('sha256', $path) : null;
            $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
            $result = $this->parseExcel($path, $snapshot->supplier_code, $imageBaseUrl, function (int $processedRows, int $totalRows) use ($snapshot): void {
                $snapshot->update([
                    'progress' => $totalRows > 0
                        ? min(92, max(2, (int) floor($processedRows * 92 / $totalRows)))
                        : 5,
                    'stage' => 'Разбор строк '.($snapshot->source_type === 'csv' ? 'CSV' : 'Excel'),
                    'processed_rows' => $processedRows,
                    'total_rows' => $totalRows,
                ]);
            }, function (array $items) use ($snapshot, &$itemsCount): void {
                foreach ($items as &$item) {
                    $item['snapshot_id'] = $snapshot->id;
                }
                unset($item);
                DB::table('supplier_catalog_items')->insert($items);
                $itemsCount += count($items);
            });

            $snapshot->update(['progress' => 94, 'stage' => 'Формирование итоговой статистики']);

            $snapshot->update([
                'status' => 'ready',
                'progress' => 100,
                'stage' => 'Готово',
                'processed_rows' => $result['summary']['rows_total'],
                'total_rows' => $result['summary']['rows_total'],
                'items_count' => $itemsCount,
                'summary' => array_merge($result['summary'], [
                    'items_inserted' => $itemsCount,
                    'source_file_size' => $sourceFileSize,
                    'source_checksum' => $sourceChecksum,
                ]),
                'processed_at' => now(),
                'storage_path' => null,
            ]);
        } catch (\Throwable $exception) {
            $snapshot->update([
                'status' => 'failed',
                'stage' => 'Ошибка',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
                'storage_path' => null,
            ]);
            report($exception);
        } finally {
            Storage::disk('local')->delete($temporaryPath);
        }
    }

    public function clearSnapshot(SupplierCatalogSnapshot $snapshot): void
    {
        if ($snapshot->storage_path) {
            Storage::disk('local')->delete($snapshot->storage_path);
        }

        $snapshot->delete();
    }

    /** @return array{delimiter: string, encoding: string} */
    private function detectCsvFormat(string $path): array
    {
        $sample = file_get_contents($path, false, null, 0, 131072);
        if ($sample === false || $sample === '') {
            throw new \RuntimeException('CSV-файл пустой или недоступен для чтения.');
        }

        $encoding = match (true) {
            str_starts_with($sample, "\xEF\xBB\xBF") => 'UTF-8',
            str_starts_with($sample, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($sample, "\xFE\xFF") => 'UTF-16BE',
            mb_check_encoding($sample, 'UTF-8') => 'UTF-8',
            default => 'Windows-1251',
        };

        $firstLine = strtok($sample, "\r\n");
        $delimiterCounts = [
            ';' => substr_count((string) $firstLine, ';'),
            ',' => substr_count((string) $firstLine, ','),
            "\t" => substr_count((string) $firstLine, "\t"),
        ];
        arsort($delimiterCounts);
        $delimiter = (string) array_key_first($delimiterCounts);
        if (($delimiterCounts[$delimiter] ?? 0) === 0) {
            throw new \RuntimeException('Не удалось определить разделитель CSV. Допустимы ;, , или табуляция.');
        }

        return ['delimiter' => $delimiter, 'encoding' => $encoding];
    }

    /** @return array{summary: array<string, mixed>} */
    private function parseExcel(string $path, string $supplierCode, ?string $imageBaseUrl = null, ?callable $onProgress = null, ?callable $onItems = null): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw new \RuntimeException('Поддерживаются файлы .xlsx и .csv.');
        }

        $csvFormat = null;
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        if ($extension === 'csv') {
            $csvFormat = $this->detectCsvFormat($path);
            $reader = new \OpenSpout\Reader\CSV\Reader(new \OpenSpout\Reader\CSV\Options(
                FIELD_DELIMITER: $csvFormat['delimiter'],
                ENCODING: $csvFormat['encoding'],
            ));
        }
        $reader->open($path);
        $mappings = $this->getMappings($supplierCode);
        $sheetName = null;
        $headerKeys = [];
        $rowsTotal = 0;
        $fieldStats = [];
        $groupKeys = [];
        $sourceImageUrls = [];
        $sourceSkuCounts = [];
        $sourceSkuSamples = [];
        $rowsWithoutName = 0;
        $rowsWithoutSku = 0;
        $itemsWithSku = 0;
        $itemsWithGroup = 0;
        $itemsWithImages = 0;
        $now = now();
        $items = [];
        $batchSize = 100;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rowNumber = 0;
                $candidateHeaders = [];
                $isCatalogSheet = false;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $values = $row->toArray();
                    if ($rowNumber === 1) {
                        foreach ($values as $index => $header) {
                            $header = trim(ltrim((string) $header, "\xEF\xBB\xBF"));
                            if ($header !== '') {
                                $candidateHeaders[$index] = $header;
                            }
                        }
                        if (! in_array(self::SOURCE_COLUMNS['sku'], $candidateHeaders, true)) {
                            break;
                        }
                        $isCatalogSheet = true;
                        $sheetName = $sheet->getName();
                        $headerKeys = $candidateHeaders;
                        continue;
                    }

                    if (! $isCatalogSheet) {
                        break;
                    }

                    $rowsTotal++;
                    $payload = [];
                    foreach ($headerKeys as $index => $header) {
                        $value = $this->normalizeCellValue($values[$index] ?? null);
                    if ($value === null) {
                        continue;
                    }

                    $payload[$header] = $value;
                    $this->addFieldStat($fieldStats, $header, $value);
                }

                foreach ([self::SOURCE_COLUMNS['price'], self::SOURCE_COLUMNS['opt_price']] as $priceField) {
                    if (isset($payload[$priceField]) && trim((string) $payload[$priceField]) === '-') {
                        $payload[$priceField] = '0';
                    }
                }

                $sku = trim((string) ($payload[self::SOURCE_COLUMNS['sku']] ?? ''));
                if ($sku === '') {
                    $rowsWithoutSku++;
                    continue;
                }

                $name = $payload[self::SOURCE_COLUMNS['name']] ?? null;
                if (! $name) {
                    $rowsWithoutName++;
                }

                $groupKey = $this->nullableString($payload[self::SOURCE_COLUMNS['group']] ?? null);
                if ($groupKey !== null) {
                    $groupKeys[$groupKey] = true;
                    $itemsWithGroup++;
                }

                $imageUrls = $this->extractImageUrls($payload, $imageBaseUrl);
                $normalizedVariation = $this->normalizeSourceVariation($payload, $supplierCode, $sku);
                $sku = $this->sourceSkuForPayload($payload, $supplierCode, $sku);
                $normalizedSku = $this->normalizeSku($sku);
                if ($normalizedSku !== '') {
                    $sourceSkuCounts[$normalizedSku] = ($sourceSkuCounts[$normalizedSku] ?? 0) + 1;
                    if (! isset($sourceSkuSamples[$normalizedSku])) {
                        $sourceSkuSamples[$normalizedSku] = ['sku' => $sku, 'name' => $this->nullableString($name)];
                    }
                }
                if ($imageUrls !== []) {
                    $itemsWithImages++;
                }
                foreach ($imageUrls as $url) {
                    $sourceImageUrls[$url] = ($sourceImageUrls[$url] ?? 0) + 1;
                }

                $items[] = [
                'snapshot_id' => 0, // Replaced after snapshot creation below.
                'external_sku' => $sku,
                'external_group_key' => $groupKey,
                'name' => $this->nullableString($name),
                'clean_name' => $normalizedVariation['clean_name'],
                'source_color' => $normalizedVariation['color'],
                'source_size' => $normalizedVariation['size'],
                'source_year' => $normalizedVariation['year'],
                'is_source_variation' => $normalizedVariation['is_variation'],
                'brand' => $this->nullableString($payload[self::SOURCE_COLUMNS['brand']] ?? null),
                'section' => $this->nullableString($payload[self::SOURCE_COLUMNS['section']] ?? null),
                'sub_section' => $this->nullableString($payload[self::SOURCE_COLUMNS['sub_section']] ?? null),
                'price' => $this->nullableDecimal($payload[self::SOURCE_COLUMNS['price']] ?? null),
                'opt_price' => $this->nullableDecimal($payload[self::SOURCE_COLUMNS['opt_price']] ?? null),
                'quantity' => $this->nullableInteger($payload[self::SOURCE_COLUMNS['quantity']] ?? null),
                'in_stock' => $this->nullableBool($payload[self::SOURCE_COLUMNS['in_stock']] ?? null),
                'source_axes' => json_encode($this->resolveSourceAxes($payload, $mappings, $supplierCode), JSON_UNESCAPED_UNICODE),
                'image_urls' => json_encode($imageUrls, JSON_UNESCAPED_UNICODE),
                'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
                ];
                $itemsWithSku++;

                    if (count($items) >= $batchSize) {
                        if ($onItems !== null) {
                            $onItems($items);
                        }
                        $items = [];
                    }

                    if ($onProgress !== null && ($rowsTotal === 1 || $rowsTotal % 100 === 0)) {
                        $onProgress($rowsTotal, 0);
                    }
                }

                if ($isCatalogSheet) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        if ($sheetName === null) {
            throw new \RuntimeException('Колонка CML2_ARTICLE не найдена в файле.');
        }
        if ($items !== [] && $onItems !== null) {
            $onItems($items);
        }
        if ($onProgress !== null) {
            $onProgress($rowsTotal, $rowsTotal);
        }

        return [
            'summary' => [
                'sheet_name' => $sheetName,
                'source_type' => $extension,
                'source_encoding' => $csvFormat['encoding'] ?? 'UTF-8',
                'delimiter' => $csvFormat['delimiter'] ?? null,
                'rows_total' => $rowsTotal,
                'items_with_sku' => $itemsWithSku,
                'rows_without_sku' => $rowsWithoutSku,
                'rows_without_name' => $rowsWithoutName,
                'groups_count' => count($groupKeys),
                'items_with_group' => $itemsWithGroup,
                'items_with_images' => $itemsWithImages,
                'source_duplicate_image_urls' => count(array_filter($sourceImageUrls, static fn (int $count) => $count > 1)),
                'duplicate_skus' => count(array_filter($sourceSkuCounts, static fn (int $count) => $count > 1)),
                'duplicate_sku_samples' => collect($sourceSkuCounts)
                    ->filter(static fn (int $count) => $count > 1)
                    ->take(20)
                    ->map(fn (int $count, string $sku) => [
                        'sku' => $sourceSkuSamples[$sku]['sku'] ?? $sku,
                        'name' => $sourceSkuSamples[$sku]['name'] ?? null,
                        'count' => $count,
                    ])
                    ->values()
                    ->all(),
                'fields' => $this->finishFieldStats($fieldStats),
            ],
        ];
    }

    /** @return Collection<int, SupplierCatalogFieldMapping> */
    public function getMappings(string $supplierCode): Collection
    {
        return SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $supplierCode)
            ->where('scope', 'variation')
            ->with('variationAttribute:id,name')
            ->get();
    }

    public function ensureDefaultMappings(string $supplierCode): void
    {
        $attributes = ShopVariationAttribute::query()->pluck('id', 'name');
        $defaults = [
            ['SKU_TSVET', 'TSVET_TOVARA', 'Цвет'],
            ['SKU_RAZMER_ODEZHDY', 'RAZMER_ODEZHDY', 'Размер'],
            ['SKU_RAZMER_OBUVI', 'RAZMER_OBUVI', 'Размер'],
            ['SKU_RAZMER_GOLOVY', 'RAZMER_GOLOVY', 'Размер'],
            ['SKU_RAZMER_RAMY', 'RAZMER_RAMY', 'Размер'],
            ['SKU_RAZMER_KOLESA', 'RAZMER_KOLESA', 'Размер'],
            ['SKU_RAZMER_NAKOLENNIKA', 'RAZMER_NAKOLENNIKA', 'Размер'],
            ['SKU_RAZMER_NALOKOTNIKI', 'RAZMER_NALOKOTNIKI', 'Размер'],
            ['SKU_RAZMER_TORMOZNOGO_DISKA', 'RAZMER_TORMOZNOGO_DISKA', 'Размер'],
            ['SKU_RAZMER_TROSIKA', 'RAZMER_TROSIKA', 'Размер'],
            ['SKU_RAZMER_ROKRINGA', 'RAZMER_ROKRINGA', 'Размер'],
            ['SKU_GOD', 'MODELNYY_GOD', 'Год'],
        ];

        foreach ($defaults as [$sourceField, $displayField, $attributeName]) {
            $mapping = SupplierCatalogFieldMapping::firstOrCreate(
                ['supplier_code' => $supplierCode, 'source_field' => $sourceField, 'scope' => 'variation'],
                [
                    'variation_attribute_id' => $attributes[$attributeName] ?? null,
                    'display_field' => $displayField,
                    'is_check_enabled' => true,
                    'is_update_enabled' => false,
                ]
            );

            // Ранние версии профиля использовали технические SKU_* GUID вместо
            // соседнего текстового поля. Обновляем только пустое значение, не
            // перезаписывая сознательно настроенные пользователем правила.
            if ($displayField !== null && $mapping->display_field === null) {
                $mapping->update(['display_field' => $displayField]);
            }
        }

        if ($supplierCode === 'bikeproducts') {
            SupplierCatalogFieldMapping::query()
                ->where('supplier_code', $supplierCode)
                ->where('scope', 'variation')
                ->where('source_field', 'SKU_GOD')
                ->update(['is_check_enabled' => false]);
        }

        foreach ([
            'NAME' => 'name',
            'BRAND' => 'brand',
            'PREVIEW_TEXT' => 'short_description',
            'DETAIL_TEXT' => 'description',
            'PRICE' => 'price',
            'QUANTITY' => 'stock_quantity',
        ] as $sourceField => $target) {
            SupplierCatalogFieldMapping::firstOrCreate(
                ['supplier_code' => $supplierCode, 'source_field' => $sourceField, 'scope' => 'good'],
                [
                    'conditions' => ['target' => $target],
                    'is_check_enabled' => true,
                    'is_update_enabled' => false,
                ]
            );
        }

        foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE', 'MORE_PHOTO'] as $sourceField) {
            SupplierCatalogFieldMapping::firstOrCreate(
                ['supplier_code' => $supplierCode, 'source_field' => $sourceField, 'scope' => 'image'],
                [
                    'is_check_enabled' => true,
                    'is_update_enabled' => false,
                ]
            );
        }
    }

    /** @param array<string, string> $payload @param Collection<int, SupplierCatalogFieldMapping> $mappings */
    private function resolveSourceAxes(array $payload, Collection $mappings, string $supplierCode): array
    {
        $axes = [];
        $parsedNameAxes = $this->bikeproductsNameAxes($payload, $supplierCode);
        $parsedAttributeIds = array_column($parsedNameAxes, 'attribute_id');

        foreach ($parsedNameAxes as $axis) {
            $axes[] = $axis;
        }

        foreach ($mappings as $mapping) {
            if (! $mapping->variation_attribute_id) {
                continue;
            }

            // Для Байкпродакс значения цвета и размера берутся из NAME. SKU_*
            // содержит служебные идентификаторы, а год в этой задаче не является
            // вариацией и не должен создавать ложные расхождения.
            if ($supplierCode === 'bikeproducts' && (
                in_array((int) $mapping->variation_attribute_id, $parsedAttributeIds, true)
                || $mapping->variationAttribute?->name === 'Год'
            )) {
                continue;
            }

            $rawValue = $this->nullableString($payload[$mapping->source_field] ?? null);
            if ($rawValue === null) {
                continue;
            }

            $displayValue = $mapping->display_field
                ? $this->nullableString($payload[$mapping->display_field] ?? null)
                : null;

            $axes[] = [
                'attribute_id' => $mapping->variation_attribute_id,
                'attribute_name' => $mapping->variationAttribute?->name,
                'source_field' => $mapping->source_field,
                'source_code' => $rawValue,
                'value' => $displayValue ?? $rawValue,
                'is_check_enabled' => $mapping->is_check_enabled,
            ];
        }

        return $axes;
    }

    public function overview(SupplierCatalogSnapshot $snapshot): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        $items = $snapshot->items()->get(['external_sku', 'external_group_key', 'name', 'clean_name', 'source_color', 'source_size', 'is_source_variation', 'source_axes', 'image_urls', 'raw_payload']);
        $matches = $this->resolveSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
        $mappings = $this->getMappings($snapshot->supplier_code);
        $sourceAxes = [];
        $sourceAxisValues = [];
        $sourceUrls = [];

        foreach ($items as $item) {
            foreach ($this->sourceAxesForItem($item, $mappings, $snapshot->supplier_code) as $axis) {
                $name = $axis['attribute_name'] ?: $axis['source_field'];
                $sourceAxes[$name] = ($sourceAxes[$name] ?? 0) + 1;
                $sourceAxisValues[$name][$this->normalizeComparisonValue((string) ($axis['value'] ?? ''))] = true;
            }
            foreach ($item->image_urls ?? [] as $url) {
                $sourceUrls[$url] = ($sourceUrls[$url] ?? 0) + 1;
            }
        }

        $databaseAxes = [];
        $databaseAxisValues = [];
        $supplierNames = $this->supplierNames($snapshot->supplier_code);
        $catalogVariations = ShopGoodVariation::query()
            ->where(function ($query) use ($supplierNames) {
                $query->whereIn('supplier', $supplierNames)
                    ->orWhere(function ($query) use ($supplierNames) {
                        $query->where(fn ($supplier) => $supplier->whereNull('supplier')->orWhere('supplier', ''))
                            ->whereHas('good', fn ($good) => $good->whereIn('supplier', $supplierNames));
                    });
            })
            ->with('attributeValues.attribute')
            ->get(['id', 'good_id']);
        foreach ($catalogVariations as $variation) {
            foreach ($variation->attributeValues as $value) {
                $name = $value->attribute?->name ?? 'Неизвестная ось';
                $databaseAxes[$name] = ($databaseAxes[$name] ?? 0) + 1;
                $databaseAxisValues[$name][$this->normalizeComparisonValue((string) $value->value)] = true;
            }
        }

        $databaseGoods = ShopGood::query()
            ->where(function ($query) use ($supplierNames) {
                $query->whereIn('supplier', $supplierNames)
                    ->orWhereHas('variations', fn ($variations) => $variations->whereIn('supplier', $supplierNames));
            })
            ->get(['id']);
        $databaseGoodIds = $databaseGoods->pluck('id');
        $goodsWithSupplierVariations = $catalogVariations->pluck('good_id')->unique();
        $sourceNameGroups = $items->groupBy(fn (SupplierCatalogItem $item) => $item->clean_name ?: $this->sourceGroupName($item->name, $item->external_sku));
        $sourceVariationRows = $items->filter(fn (SupplierCatalogItem $item) => $this->isSourceVariationItem($item));
        $sourceVariationGroups = $sourceVariationRows->groupBy(fn (SupplierCatalogItem $item) => $item->clean_name ?: $this->sourceGroupName($item->name, $item->external_sku));
        $sourceProducts = $sourceNameGroups->count();

        return [
            'snapshot' => $snapshot,
            'stats' => [
                'source_items' => $items->count(),
                'source_products' => $sourceProducts,
                'source_groups' => $sourceVariationGroups->count(),
                'source_items_without_group' => $sourceNameGroups->filter(fn (Collection $group) => $group->count() === 1)->count(),
                'source_goods_without_variations' => $sourceNameGroups->keys()->diff($sourceVariationGroups->keys())->count(),
                'source_goods_with_variations' => $sourceVariationGroups->count(),
                'source_variations' => $sourceVariationRows->count(),
                'source_unique_variation_axes' => count($sourceAxisValues),
                'source_unique_variation_values' => array_sum(array_map('count', $sourceAxisValues)),
                'source_items_with_images' => $items->filter(fn ($item) => ! empty($item->image_urls))->count(),
                'source_duplicate_image_urls' => count(array_filter($sourceUrls, static fn (int $count) => $count > 1)),
                'database_goods' => $databaseGoods->count(),
                'database_goods_without_variations' => $databaseGoodIds->diff($goodsWithSupplierVariations)->count(),
                'database_goods_with_variations' => $goodsWithSupplierVariations->count(),
                'database_variations' => $catalogVariations->count(),
                'database_unique_variation_axes' => count($databaseAxisValues),
                'database_unique_variation_values' => array_sum(array_map('count', $databaseAxisValues)),
                'database_unique_properties' => $databaseGoodIds->isEmpty() ? 0 : DB::table('shop_good_properties')->whereIn('good_id', $databaseGoodIds)->distinct('property_id')->count('property_id'),
                'database_matching_goods' => $matches->where('type', 'good')->count(),
                'database_matching_variations' => $matches->where('type', 'variation')->count(),
                'database_unmatched_source_skus' => $items->count() - $matches->count(),
            ],
            'source_axes' => $this->sortStats($sourceAxes),
            'database_axes' => $this->sortStats($databaseAxes),
        ];
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function variationAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?string $search = null, array $filters = [], string $variationCount = 'all', ?int $goodId = null, string $mainStockFilter = 'all', string $remoteStockFilter = 'all'): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        // Построение сверки проходит по всему снимку и всем вариациям поставщика.
        // Сохраняем эту неизменяемую часть отдельно от поиска, фильтров и пагинации.
        $baseCacheKey = 'supplier-catalog:variation-audit-base:v19:'.$snapshot->id.':'.($snapshot->updated_at?->format('Uu') ?? '0');
        $cachedBase = Cache::store('file')->get($baseCacheKey);
        if (is_array($cachedBase) && isset($cachedBase['rows'], $cachedBase['variation_counts'], $cachedBase['stats'])) {
            $allRows = collect($cachedBase['rows']);
            $variationCountByGood = collect($cachedBase['variation_counts']);
            $baseStats = $cachedBase['stats'];
        } else {
        $mappings = $this->getMappings($snapshot->supplier_code);
        $priceMappings = $this->priceFieldMappings($snapshot->supplier_code);
        $allSourceVariationItems = $this->sourceVariationItems($snapshot)
            ->filter(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code) !== '')
            ->values();
        $allSourceSingleItems = $this->sourceSingleItems($snapshot)
            ->filter(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code) !== '')
            ->values();
        $allSourcePayloadSkuItems = $snapshot->items()
            ->get(['id', 'name', 'clean_name', 'source_color', 'source_size', 'source_year', 'is_source_variation', 'external_sku', 'source_axes', 'raw_payload'])
            ->filter(fn (SupplierCatalogItem $item) => $this->nullableString($item->raw_payload[self::SOURCE_COLUMNS['sku']] ?? null) !== null)
            ->values();
        $priceExcludedSourceItems = $allSourceVariationItems
            ->merge($allSourceSingleItems)
            ->reject(fn (SupplierCatalogItem $item) => $this->sourceItemPassesMinPrice($item, $snapshot->supplier_code, $priceMappings))
            ->unique('id')
            ->values();
        $sourceItemsByLookupKeys = function (Collection $items) use ($snapshot): Collection {
            return $items->flatMap(function (SupplierCatalogItem $item) use ($snapshot) {
                return collect($this->skuLookupKeys($this->sourceSkuForItem($item, $snapshot->supplier_code), $snapshot->supplier_code))
                    ->mapWithKeys(fn (string $sku) => [$sku => $item]);
            });
        };
        $safeSkuKey = fn (string $sku): string => 'sku:'.$this->normalizeSku($sku);
        $sourceItemsBySafeLookupKeys = function (Collection $items) use ($snapshot, $safeSkuKey): Collection {
            return $items->flatMap(function (SupplierCatalogItem $item) use ($snapshot, $safeSkuKey) {
                return collect($this->skuLookupKeys($this->sourceSkuForItem($item, $snapshot->supplier_code), $snapshot->supplier_code))
                    ->mapWithKeys(fn (string $sku) => [$safeSkuKey($sku) => $item]);
            });
        };
        $sourcePresenceItems = $sourceItemsByLookupKeys($allSourceVariationItems);
        $sourceSinglePresenceItems = $sourceItemsByLookupKeys($allSourceSingleItems);
        $sourcePresenceItemsSafe = $sourceItemsBySafeLookupKeys($allSourceVariationItems);
        $sourceSinglePresenceItemsSafe = $sourceItemsBySafeLookupKeys($allSourceSingleItems);
        $sourcePayloadSkuPresenceItems = $allSourcePayloadSkuItems->flatMap(function (SupplierCatalogItem $item) use ($snapshot, $safeSkuKey) {
            return collect($this->skuLookupKeys($item->raw_payload[self::SOURCE_COLUMNS['sku']] ?? '', $snapshot->supplier_code))
                ->mapWithKeys(fn (string $sku) => [$safeSkuKey($sku) => $item]);
        });
        $sourceItems = $sourceItemsByLookupKeys($allSourceVariationItems
            ->filter(fn (SupplierCatalogItem $item) => $this->sourceItemPassesMinPrice($item, $snapshot->supplier_code, $priceMappings)));
        $sourceSingleItems = $sourceItemsByLookupKeys($allSourceSingleItems
            ->filter(fn (SupplierCatalogItem $item) => $this->sourceItemPassesMinPrice($item, $snapshot->supplier_code, $priceMappings)));
        $supplierNames = $this->supplierNames($snapshot->supplier_code);
        $databaseVariations = ShopGoodVariation::query()
            ->where(function ($query) use ($supplierNames) {
                $query->whereIn('supplier', $supplierNames)
                    ->orWhere(function ($query) use ($supplierNames) {
                        $query->where(fn ($supplier) => $supplier->whereNull('supplier')->orWhere('supplier', ''))
                            ->whereHas('good', fn ($good) => $good->whereIn('supplier', $supplierNames));
                    });
            })
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->with(['good:id,name,slug,sku', 'attributeValues.attribute'])
            ->get();
        $variationCountByGood = $databaseVariations->countBy('good_id');
        $databaseVariationsBySku = $databaseVariations
            ->keyBy(fn (ShopGoodVariation $variation) => $this->normalizeSku($variation->sku));
        $databaseSkus = $databaseVariationsBySku->keys()->flip();
        $sourceDirectGoods = ShopGood::query()
            ->whereIn('supplier', $supplierNames)
            ->whereIn(DB::raw('TRIM(`sku`)'), $sourceItems->keys()->all())
            ->get(['id', 'sku', 'name', 'slug'])
            ->keyBy(fn (ShopGood $good) => $this->normalizeSku($good->sku));
        // Keep supplier boundaries strict for actions, but distinguish a real
        // SKU assigned to another supplier from a SKU that is truly absent.
        $allVariationsBySku = ShopGoodVariation::query()
            ->whereIn(DB::raw('TRIM(`sku`)'), $sourceItems->keys()->all())
            ->with('good:id,name,slug,supplier')
            ->get(['id', 'good_id', 'sku', 'supplier'])
            ->keyBy(fn (ShopGoodVariation $variation) => $this->normalizeSku($variation->sku));
        $allGoodsBySku = ShopGood::query()
            ->whereIn(DB::raw('TRIM(`sku`)'), $sourceItems->keys()->all())
            ->get(['id', 'sku', 'name', 'slug', 'supplier'])
            ->keyBy(fn (ShopGood $good) => $this->normalizeSku($good->sku));
        $sourceGroupKey = fn (SupplierCatalogItem $item): string => $item->clean_name
            ?: $this->sourceGroupName($item->name, $item->external_sku);
        $databaseGoodsByGroupName = ShopGood::query()
            ->whereIn('supplier', $supplierNames)
            ->get(['id', 'sku', 'name', 'slug'])
            ->groupBy(fn (ShopGood $good) => $this->normalizeSourceGroupKey($this->sourceGroupName($good->name, $good->sku)));
        $sourceGroupDatabaseGoods = [];
        foreach ($allSourceVariationItems as $sourceItem) {
            $matchedVariation = collect($this->skuLookupKeys($this->sourceSkuForItem($sourceItem, $snapshot->supplier_code), $snapshot->supplier_code))
                ->map(fn (string $sku) => $databaseVariationsBySku->get($sku))
                ->first();
            $matchedGood = $matchedVariation?->good;
            if (! $matchedGood) {
                $nameCandidates = $databaseGoodsByGroupName->get($this->normalizeSourceGroupKey($sourceGroupKey($sourceItem)), collect());
                $matchedGood = $nameCandidates->count() === 1 ? $nameCandidates->first() : null;
            }
            if ($matchedGood) {
                $sourceGroupDatabaseGoods[$sourceGroupKey($sourceItem)] = $matchedGood;
            }
        }
        $rows = $databaseVariations->map(function (ShopGoodVariation $variation) use ($sourceItems, $sourceSingleItems, $sourcePresenceItems, $sourceSinglePresenceItems, $sourcePresenceItemsSafe, $sourceSinglePresenceItemsSafe, $sourcePayloadSkuPresenceItems, $safeSkuKey, $variationCountByGood, $mappings, $priceMappings, $snapshot) {
            $variationSku = $this->normalizeSku($variation->sku);
            $variationSafeSku = $safeSkuKey($variationSku);
            $source = $sourceItems->get($variationSku)
                ?? $sourcePresenceItems->get($variationSku)
                ?? $sourcePresenceItemsSafe->get($variationSafeSku)
                ?? $sourcePayloadSkuPresenceItems->get($variationSafeSku);

            $isSingleProductSource = false;
            if ($source === null) {
                $source = $sourceSingleItems->get($variationSku)
                    ?? $sourceSinglePresenceItems->get($variationSku)
                    ?? $sourceSinglePresenceItemsSafe->get($variationSafeSku);
                $isSingleProductSource = $source !== null;
            }
            if ($source === null && $variationCountByGood->get($variation->good_id) === 1) {
                $source = $sourceSingleItems->get($this->normalizeSku($variation->good?->sku));
                $isSingleProductSource = $source !== null;
            }
            $comparisons = $isSingleProductSource
                ? $this->singleProductVariationComparisons($variation, $source, $snapshot->supplier_code)
                : $this->variationComparisons($variation, $source, $mappings, $snapshot->supplier_code);
            $hasDifferences = collect($comparisons)->contains(fn (array $item) => ! in_array($item['status'], ['match', 'not_checked'], true));
            $status = ! $source ? 'delete_candidate_variation' : ($hasDifferences ? ($variationCountByGood->get($variation->good_id) === 1 ? 'attention_single_variation' : 'attention') : 'match');

            return [
                'item_id' => $source?->id ?? 'db-'.$variation->id,
                'external_sku' => $variation->sku,
                'source_group_name' => $source ? ($source->clean_name ?: $this->sourceGroupName($source->name, $source->external_sku)) : null,
                'source_name' => $source?->name,
                'source_kind' => $isSingleProductSource ? 'single_product' : ($source ? 'variation' : null),
                'source_sku' => $source ? $this->sourceSkuForItem($source, $snapshot->supplier_code) : null,
                'sku_matched_by_rule' => $source ? $this->skuMatchedByReplacementRule($this->sourceSkuForItem($source, $snapshot->supplier_code), $variation->sku, $snapshot->supplier_code) : false,
                'source_price_label' => $source ? $this->sourcePriceLabelForItem($source, $priceMappings) : null,
                'database_match_type' => 'variation',
                'database_variation_id' => $variation->id,
                'database_good_id' => $variation->good_id,
                'database_name' => $variation->good?->name,
                'database_slug' => $variation->good?->slug,
                'database_axes' => $variation->attributeValues
                    ->groupBy(fn ($value) => $value->attribute?->name ?? '')
                    ->map(fn (Collection $values) => $values->pluck('value')->filter()->values()->all())
                    ->filter(fn (array $values, string $attribute) => $attribute !== '' && $values !== [])
                    ->all(),
                'stock_quantity' => $variation->stock_quantity,
                'remote_stock_quantity' => $variation->remote_stock_quantity,
                'fast_remote_stock_quantity' => $variation->fast_remote_stock_quantity,
                'has_main_stock' => $this->hasPositiveStockValue($variation->stock_quantity),
                'has_remote_stock' => $this->hasPositiveStockValue($variation->remote_stock_quantity) || $this->hasPositiveStockValue($variation->fast_remote_stock_quantity),
                'comparisons' => $comparisons,
                'differences' => array_values(array_filter($comparisons, fn (array $item) => ! in_array($item['status'], ['match', 'not_checked'], true))),
                'axis_issue_types' => $this->axisIssueTypes($comparisons),
                'status' => $status,
                'source_exists' => $source !== null,
            ];
        })->values();

        $goodsWithSource = $rows->where('source_exists', true)->pluck('database_good_id')->unique();
        $goodsWithSourceByGroupName = collect($sourceGroupDatabaseGoods)->pluck('id')->filter()->unique()->values();
        $rows = $rows->map(function (array $row) use ($goodsWithSource, $goodsWithSourceByGroupName) {
            if (! $row['source_exists'] && ! $goodsWithSource->contains($row['database_good_id']) && ! $goodsWithSourceByGroupName->contains($row['database_good_id'])) {
                $row['status'] = 'delete_candidate_good';
                $row['comparisons'] = [['status' => 'delete_candidate_good', 'attribute' => 'Вариации товара', 'source_value' => 'Нет в файле', 'database_values' => []]];
                $row['differences'] = $row['comparisons'];
            }
            return $row;
        });

        $processedSourceOnlyItemIds = [];
        foreach ($sourceItems as $sourceSku => $source) {
            if (isset($processedSourceOnlyItemIds[$source->id])) {
                continue;
            }
            $processedSourceOnlyItemIds[$source->id] = true;
            $sourceHasDatabaseSku = collect($this->skuLookupKeys($this->sourceSkuForItem($source, $snapshot->supplier_code), $snapshot->supplier_code))
                ->contains(fn (string $sku) => $databaseSkus->has($sku));
            if ($sourceHasDatabaseSku) continue;
            $hasDatabaseProduct = $sourceDirectGoods->has($sourceSku);
            $databaseProduct = $sourceDirectGoods->get($sourceSku);
            $groupKey = $sourceGroupKey($source);
            $groupDatabaseGood = $sourceGroupDatabaseGoods[$groupKey] ?? null;
            $sameAxesVariation = $groupDatabaseGood
                ? $databaseVariations
                    ->where('good_id', $groupDatabaseGood->id)
                    ->first(fn (ShopGoodVariation $variation) => $this->hasSameSourceAxes($variation, $source, $mappings, $snapshot->supplier_code))
                : null;
            $otherSupplierVariation = $allVariationsBySku->get($sourceSku);
            $otherSupplierGood = $allGoodsBySku->get($sourceSku);
            $otherSupplier = $otherSupplierVariation?->supplier
                ?: $otherSupplierVariation?->good?->supplier
                ?: $otherSupplierGood?->supplier;
            $status = $sameAxesVariation
                ? 'source_variation_sku_mismatch'
                : ($groupDatabaseGood || $hasDatabaseProduct
                    ? 'source_variation_missing'
                : ($otherSupplierVariation || $otherSupplierGood
                    ? 'source_sku_other_supplier'
                    : 'source_good_missing'));
            $comparisons = $this->sourceOnlyVariationComparisons($source, $mappings, $snapshot->supplier_code, $status);
            if ($sameAxesVariation) {
                array_unshift($comparisons, [
                    'status' => 'sku_mismatch',
                    'attribute' => 'Артикул',
                    'source_value' => $source->external_sku,
                    'database_values' => [$sameAxesVariation->sku],
                    'message' => 'Оси совпадают, но артикул вариации в файле отличается от артикула в базе.',
                ]);
            }
            if ($status === 'source_sku_other_supplier') {
                array_unshift($comparisons, [
                    'status' => $status,
                    'attribute' => 'Артикул',
                    'source_value' => $source->external_sku,
                    'database_values' => [trim(implode(' · ', array_filter([
                        $otherSupplierVariation ? 'Вариация #'.$otherSupplierVariation->id : null,
                        $otherSupplierGood ? 'Товар #'.$otherSupplierGood->id : null,
                        $otherSupplier ? 'Поставщик: '.$otherSupplier : 'поставщик не указан',
                    ])))],
                ]);
            }
            $rows->push([
                'item_id' => $source->id,
                'source_item_id' => $source->id,
                'external_sku' => $source->external_sku,
                'source_group_name' => $groupKey,
                'source_name' => $source->name,
                'source_sku' => $this->sourceSkuForItem($source, $snapshot->supplier_code),
                'sku_matched_by_rule' => false,
                'source_price_label' => $this->sourcePriceLabelForItem($source, $priceMappings),
                'database_match_type' => null,
                'database_variation_id' => null,
                'duplicate_database_variation_id' => $sameAxesVariation?->id,
                'database_good_id' => $sameAxesVariation?->good_id ?? ($groupDatabaseGood ?? $databaseProduct)?->id,
                'database_name' => $sameAxesVariation?->good?->name ?? ($groupDatabaseGood ?? $databaseProduct)?->name,
                'database_slug' => $sameAxesVariation?->good?->slug ?? ($groupDatabaseGood ?? $databaseProduct)?->slug,
                'database_axes' => [],
                'stock_quantity' => null,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null,
                'has_main_stock' => false,
                'has_remote_stock' => false,
                'comparisons' => $comparisons,
                'differences' => $comparisons,
                'status' => $status,
                'source_exists' => true,
            ]);
        }

        foreach ($priceExcludedSourceItems as $source) {
            $sourceSku = $this->sourceSkuForItem($source, $snapshot->supplier_code);
            $priceLabel = $this->sourcePriceLabelForItem($source, $priceMappings);
            $groupKey = $sourceGroupKey($source);
            $comparisons = [[
                'status' => 'source_price_excluded',
                'attribute' => 'Цена',
                'source_value' => $priceLabel ?: 'Цена не заполнена',
                'database_values' => [],
                'message' => 'Строка файла исключена из сопоставлений настройкой минимальной цены.',
            ]];
            $rows->push([
                'item_id' => $source->id,
                'source_item_id' => $source->id,
                'external_sku' => $source->external_sku,
                'source_group_name' => $groupKey,
                'source_name' => $source->name,
                'source_sku' => $sourceSku,
                'sku_matched_by_rule' => false,
                'source_price_label' => $priceLabel,
                'database_match_type' => null,
                'database_variation_id' => null,
                'database_good_id' => null,
                'database_name' => null,
                'database_slug' => null,
                'database_axes' => [],
                'stock_quantity' => null,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null,
                'has_main_stock' => false,
                'has_remote_stock' => false,
                'comparisons' => $comparisons,
                'differences' => $comparisons,
                'status' => 'source_price_excluded',
                'source_exists' => true,
            ]);
        }
        $allRows = $rows;
        $baseStats = [
            'database_goods_with_variations' => $databaseVariations->pluck('good_id')->unique()->count(),
            'database_variations' => $databaseVariations->count(),
        ];
        Cache::store('file')->put($baseCacheKey, [
            'rows' => $allRows->all(),
            'variation_counts' => $variationCountByGood->all(),
            'stats' => $baseStats,
        ], now()->addMinutes(15));
        }

        $rows = $allRows;
        if ($goodId !== null && $goodId > 0) {
            $rows = $rows->filter(fn (array $row) => (int) ($row['database_good_id'] ?? 0) === $goodId)->values();
        }
        if ($search) {
            $needle = mb_strtolower(trim($search));
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) $row['external_sku'].' '.$row['source_name'].' '.$row['database_name']), $needle))->values();
        }
        $filters = array_values(array_intersect($filters, [
            'match',
            'attention',
            'attention_single_variation',
            'delete_candidate_good',
            'delete_candidate_variation',
            'source_good_missing',
            'source_variation_missing',
            'source_variation_sku_mismatch',
            'source_sku_other_supplier',
            'source_price_excluded',
        ]));
        if ($filters !== []) {
            $matchedRows = $rows->whereIn('status', $filters);
            $expandGoodIds = $matchedRows
                ->whereIn('status', [
                    'delete_candidate_variation',
                    'source_variation_missing',
                    'source_variation_sku_mismatch',
                    'attention',
                ])
                ->pluck('database_good_id')
                ->filter()
                ->unique();
            $rows = $rows
                ->filter(fn (array $row) => in_array($row['status'], $filters, true) || $expandGoodIds->contains($row['database_good_id']))
                ->values();
        }
        // Фильтр относится к товарам базы. Строки, которые есть только в
        // файле, не имеют родительского товара базы и остаются видимыми лишь
        // в режиме «Все».
        if ($variationCount === 'single') {
            $rows = $rows->filter(fn (array $row) => $row['database_good_id']
                && ($variationCountByGood->get($row['database_good_id']) ?? 0) === 1)->values();
        } elseif ($variationCount === 'multiple') {
            $rows = $rows->filter(fn (array $row) => $row['database_good_id']
                && ($variationCountByGood->get($row['database_good_id']) ?? 0) > 1)->values();
        }
        if ($mainStockFilter === 'zero') {
            $rows = $rows->filter(fn (array $row) => $row['database_variation_id'] && ! ($row['has_main_stock'] ?? false))->values();
        } elseif ($mainStockFilter === 'in_stock') {
            $rows = $rows->filter(fn (array $row) => $row['database_variation_id'] && ($row['has_main_stock'] ?? false))->values();
        }
        if ($remoteStockFilter === 'empty') {
            $rows = $rows->filter(fn (array $row) => $row['database_variation_id'] && ! ($row['has_remote_stock'] ?? false))->values();
        } elseif ($remoteStockFilter === 'not_empty') {
            $rows = $rows->filter(fn (array $row) => $row['database_variation_id'] && ($row['has_remote_stock'] ?? false))->values();
        }
        // Пагинируем товарами, а не отдельными SKU: все вариации одного товара
        // всегда остаются на одной странице для наглядной сверки.
        $groups = $rows->groupBy(fn (array $row) => $row['database_good_id']
            ? 'good-'.$row['database_good_id']
            : 'source-'.($row['source_group_name'] ?: $row['external_sku']));
        $total = $groups->count();
        $pageRows = $groups->forPage($page, $perPage)->flatten(1)->values();

        $countGroups = fn (Collection $items): int => $items->groupBy(fn (array $row) => $row['database_good_id'] ? 'good-'.$row['database_good_id'] : 'source-'.($row['source_group_name'] ?: $row['external_sku']))->count();

        return [
            'data' => $pageRows,
            'stats' => [
                'database_goods_with_variations' => $baseStats['database_goods_with_variations'],
                'database_variations' => $baseStats['database_variations'],
                'comparison_groups' => $total,
                'filtered_goods' => $total,
                'filtered_variations' => $rows->whereNotNull('database_variation_id')->count(),
                'delete_candidate_goods' => $allRows->where('status', 'delete_candidate_good')->pluck('database_good_id')->unique()->count(),
                'delete_candidate_variations' => $allRows->where('status', 'delete_candidate_variation')->count(),
                'delete_candidate_variation_goods' => $countGroups($allRows->where('status', 'delete_candidate_variation')),
                'delete_candidate_main_stock_variations' => $allRows->where('status', 'delete_candidate_variation')->where('has_main_stock', true)->count(),
                'delete_candidate_remote_stock_variations' => $allRows->where('status', 'delete_candidate_variation')->where('has_remote_stock', true)->count(),
                'source_good_missing' => $allRows->where('status', 'source_good_missing')->pluck('item_id')->unique()->count(),
                'source_good_missing_groups' => $countGroups($allRows->where('status', 'source_good_missing')),
                'source_variation_missing' => $allRows->where('status', 'source_variation_missing')->pluck('item_id')->unique()->count(),
                'source_variation_missing_groups' => $countGroups($allRows->where('status', 'source_variation_missing')),
                'source_variation_sku_mismatch' => $allRows->where('status', 'source_variation_sku_mismatch')->pluck('item_id')->unique()->count(),
                'source_variation_sku_mismatch_groups' => $countGroups($allRows->where('status', 'source_variation_sku_mismatch')),
                'source_sku_other_supplier' => $countGroups($allRows->where('status', 'source_sku_other_supplier')),
                'source_price_excluded' => $allRows->where('status', 'source_price_excluded')->pluck('item_id')->unique()->count(),
                'source_price_excluded_groups' => $countGroups($allRows->where('status', 'source_price_excluded')),
                'attention_goods' => $countGroups($allRows->where('status', 'attention')),
                'attention_single_variation_goods' => $countGroups($allRows->where('status', 'attention_single_variation')),
                'match_goods' => $countGroups($allRows->where('status', 'match')),
            ],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** @return array{database_variation_ids: array<int, int>, database_good_ids: array<int, int>, source_item_ids: array<int, int>, groups_total: int} */
    public function variationSelection(SupplierCatalogSnapshot $snapshot, ?string $search = null, array $filters = [], string $variationCount = 'all', ?int $goodId = null, string $mainStockFilter = 'all', string $remoteStockFilter = 'all'): array
    {
        $audit = $this->variationAudit($snapshot, 1, 100000, $search, $filters, $variationCount, $goodId, $mainStockFilter, $remoteStockFilter);
        $rows = collect($audit['data']);
        $selectedRows = $filters === []
            ? $rows
            : $rows->whereIn('status', $filters)->values();

        return [
            'database_variation_ids' => $selectedRows
                ->pluck('database_variation_id')
                ->merge($selectedRows->where('status', 'source_variation_sku_mismatch')->pluck('duplicate_database_variation_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'database_good_ids' => $selectedRows->where('status', 'delete_candidate_good')->pluck('database_good_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'source_item_ids' => $selectedRows
                ->whereIn('status', ['source_good_missing', 'source_variation_missing', 'source_variation_sku_mismatch'])
                ->pluck('source_item_id', 'item_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'groups_total' => (int) data_get($audit, 'meta.total', 0),
        ];
    }

    /** @param array<int, int> $ids @return array<string, mixed> */
    public function actionBackup(SupplierCatalogSnapshot $snapshot, string $scope, string $action, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $backup = ['scope' => $scope, 'action' => $action, 'ids' => $ids];

        if ($scope === 'variations') {
            $variations = ShopGoodVariation::query()->whereIn('id', $ids)->get();
            $goodIds = $variations->pluck('good_id')->unique()->values();
            $backup['variations'] = $variations->map(fn (ShopGoodVariation $variation) => $variation->getAttributes())->all();
            $backup['goods'] = ShopGood::query()->whereIn('id', $goodIds)->get()->map(fn (ShopGood $good) => $good->getAttributes())->all();
            $backup['variation_attribute_links'] = DB::table('shop_variation_attributes_values')->whereIn('variation_id', $ids)->get()->map(fn ($row) => (array) $row)->all();
            return $backup;
        }

        if ($scope === 'goods' && $action === 'delete') {
            $goods = ShopGood::query()->whereIn('id', $ids)->get();
            $variationIds = ShopGoodVariation::query()->whereIn('good_id', $goods->pluck('id'))->pluck('id');
            $backup['goods'] = $goods->map(fn (ShopGood $good) => $good->getAttributes())->all();
            $backup['variations'] = ShopGoodVariation::query()->whereIn('id', $variationIds)->get()->map(fn (ShopGoodVariation $variation) => $variation->getAttributes())->all();
            $backup['variation_attribute_links'] = DB::table('shop_variation_attributes_values')->whereIn('variation_id', $variationIds)->get()->map(fn ($row) => (array) $row)->all();
            $backup['good_properties'] = DB::table('shop_good_properties')->whereIn('good_id', $goods->pluck('id'))->get()->map(fn ($row) => (array) $row)->all();
            $backup['images'] = ShopGoodImage::query()->whereIn('good_id', $goods->pluck('id'))->orWhereIn('variation_id', $variationIds)->get()->map(fn (ShopGoodImage $image) => $image->getAttributes())->all();
            return $backup;
        }

        $items = $this->bindingParticipatingItems($snapshot->items()->whereIn('id', $ids)->get(), $snapshot->supplier_code);
        $matches = $this->resolveSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
        $goodIds = $matches->map(fn (array $match) => $match['type'] === 'variation' ? $match['model']->good_id : $match['model']->id)->filter()->unique()->values();
        $variationIds = $matches->where('type', 'variation')->pluck('model.id')->filter()->values();
        $backup['source_items'] = $items->map(fn (SupplierCatalogItem $item) => $item->getAttributes())->all();
        $backup['goods'] = ShopGood::query()->whereIn('id', $goodIds)->get()->map(fn (ShopGood $good) => $good->getAttributes())->all();
        $backup['variations'] = ShopGoodVariation::query()->whereIn('id', $variationIds)->get()->map(fn (ShopGoodVariation $variation) => $variation->getAttributes())->all();
        $backup['variation_attribute_links'] = DB::table('shop_variation_attributes_values')->whereIn('variation_id', $variationIds)->get()->map(fn ($row) => (array) $row)->all();
        if ($scope === 'images') {
            $backup['images'] = ShopGoodImage::query()
                ->whereIn('good_id', $goodIds)
                ->orWhereIn('variation_id', $variationIds)
                ->get()
                ->map(fn (ShopGoodImage $image) => $image->getAttributes())
                ->all();
        }
        return $backup;
    }

    /** @return array{affected: int, message: string} */
    public function rollbackVariationAction(SupplierCatalogActionRun $run): array
    {
        if ($run->scope !== 'variations' || $run->status !== 'completed') {
            throw new \InvalidArgumentException('Для этой операции откат недоступен.');
        }

        $backup = $run->backup ?? [];
        $variations = collect($backup['variations'] ?? [])->filter(fn (array $row) => ! empty($row['id']))->values();
        if ($variations->isEmpty()) {
            throw new \InvalidArgumentException('Резервные данные вариаций не найдены.');
        }

        return DB::transaction(function () use ($run, $backup, $variations): array {
            foreach ($backup['goods'] ?? [] as $good) {
                if (! empty($good['id'])) {
                    DB::table('shop_goods')->updateOrInsert(['id' => $good['id']], $good);
                }
            }
            foreach ($variations as $variation) {
                DB::table('shop_good_variations')->updateOrInsert(['id' => $variation['id']], $variation);
            }

            $variationIds = $variations->pluck('id')->all();
            DB::table('shop_variation_attributes_values')->whereIn('variation_id', $variationIds)->delete();
            $links = collect($backup['variation_attribute_links'] ?? [])->filter(fn (array $link) => in_array($link['variation_id'] ?? null, $variationIds, true))->values()->all();
            if ($links !== []) {
                DB::table('shop_variation_attributes_values')->insert($links);
            }

            $run->update([
                'status' => 'rolled_back',
                'result' => array_merge($run->result ?? [], ['rollback_affected' => count($variationIds)]),
                'executed_at' => now(),
            ]);

            return ['affected' => count($variationIds), 'message' => 'Вариации и их оси восстановлены из резервной копии'];
        });
    }

    /** @return array<string, int|string|bool|null> */
    public function startImageContentAudit(SupplierCatalogSnapshot $snapshot): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        $existing = $this->imageContentAuditStatus($snapshot);
        if ($existing['total'] > 0) {
            $needsNormalization = SupplierCatalogImageAudit::query()
                ->where('snapshot_id', $snapshot->id)
                ->where('status', 'available')
                ->where(function ($query): void {
                    $query->whereNull('normalized_crop_hash')->orWhereNull('normalized_white_hash');
                })
                ->exists();
            if ($needsNormalization) {
                SupplierCatalogImageAudit::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->where('status', 'available')
                    ->where(function ($query): void {
                        $query->whereNull('normalized_crop_hash')->orWhereNull('normalized_white_hash');
                    })
                    ->update(['status' => 'pending', 'updated_at' => now()]);
                $this->updateImageAuditSummary($snapshot, 'processing');

                return ['started' => true, ...$this->imageContentAuditStatus($snapshot)];
            }
            if (($existing['database_pending'] ?? 0) > 0) {
                $this->updateImageAuditSummary($snapshot, 'processing');
                return ['started' => true, ...$this->imageContentAuditStatus($snapshot)];
            }
            return ['started' => false, ...$existing];
        }

        $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
        $sourceFields = $this->imageSourceFields($snapshot->supplier_code);
        $urls = [];
        $snapshot->items()->select(['id', 'raw_payload'])->orderBy('id')->chunkById(500, function (Collection $items) use (&$urls, $sourceFields, $imageBaseUrl): void {
            foreach ($items as $item) {
                foreach ($this->imageSourcesForItem($item, $sourceFields, $imageBaseUrl) as $source) {
                    $urls[$source['url']] = true;
                }
            }
        });

        $now = now();
        foreach (array_chunk(array_keys($urls), 500) as $chunk) {
            SupplierCatalogImageAudit::query()->insertOrIgnore(array_map(fn (string $url) => [
                'snapshot_id' => $snapshot->id,
                'source_url' => $url,
                'source_url_hash' => $this->sourceUrlHash($url),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        if ($urls === []) {
            $this->updateImageAuditSummary($snapshot, 'completed');
            return ['started' => false, ...$this->imageContentAuditStatus($snapshot)];
        }

        $this->updateImageAuditSummary($snapshot, 'processing');
        return ['started' => true, ...$this->imageContentAuditStatus($snapshot)];
    }

    /** @return array<string, int|string|bool|null> */
    public function imageContentAuditStatus(SupplierCatalogSnapshot $snapshot): array
    {
        $counts = SupplierCatalogImageAudit::query()
            ->where('snapshot_id', $snapshot->id)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $total = (int) $counts->sum();
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $databaseCoverage = $pending > 0 || $total === 0
            ? ['total' => 0, 'hashed' => 0, 'unavailable' => 0, 'pending' => 0]
            : $this->databaseImageHashCoverage($snapshot);
        $completed = $total > 0 && $pending === 0 && $databaseCoverage['pending'] === 0;
        $summary = $snapshot->summary['image_content_audit'] ?? [];

        $status = $total === 0
            ? (($summary['status'] ?? null) === 'completed' ? 'completed' : 'not_started')
            : ($completed ? 'completed' : 'processing');

        return [
            'started' => $total > 0,
            'status' => $status,
            'total' => $total,
            'checked' => max(0, $total - $pending),
            'pending' => $pending,
            'available' => (int) ($counts['available'] ?? 0),
            'broken' => (int) ($counts['broken'] ?? 0),
            'unsupported' => (int) ($counts['unsupported'] ?? 0),
            'database_total' => $databaseCoverage['total'],
            'database_hashed' => $databaseCoverage['hashed'],
            'database_unavailable' => $databaseCoverage['unavailable'],
            'database_pending' => $databaseCoverage['pending'],
            'completed_at' => $summary['completed_at'] ?? null,
        ];
    }

    public function invalidateImageContentAudits(string $supplierCode): void
    {
        SupplierCatalogSnapshot::query()
            ->where('supplier_code', $supplierCode)
            ->where('status', 'ready')
            ->orderBy('id')
            ->each(function (SupplierCatalogSnapshot $snapshot): void {
                SupplierCatalogImageAudit::query()->where('snapshot_id', $snapshot->id)->delete();
                $summary = $snapshot->summary ?? [];
                unset($summary['image_content_audit']);
                $snapshot->update(['summary' => $summary]);
            });
    }

    public function processImageContentAuditBatch(int $snapshotId): void
    {
        $snapshot = SupplierCatalogSnapshot::find($snapshotId);
        if (! $snapshot || $snapshot->status !== 'ready') {
            return;
        }

        // A worker killed by timeout must not leave the audit permanently stuck.
        SupplierCatalogImageAudit::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(20))
            ->update(['status' => 'pending']);

        $recordIds = DB::transaction(function () use ($snapshot): array {
            $records = SupplierCatalogImageAudit::query()
                ->where('snapshot_id', $snapshot->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit(self::IMAGE_AUDIT_BATCH_SIZE)
                ->lockForUpdate()
                ->get(['id']);
            if ($records->isEmpty()) {
                return [];
            }

            SupplierCatalogImageAudit::query()
                ->whereIn('id', $records->pluck('id'))
                ->update(['status' => 'processing', 'error_message' => null, 'updated_at' => now()]);

            return $records->pluck('id')->map(fn ($id) => (int) $id)->all();
        });
        $records = SupplierCatalogImageAudit::query()->whereIn('id', $recordIds)->orderBy('id')->get();

        foreach ($records as $record) {
            $this->inspectSupplierImageUrl($record);
        }

        if (SupplierCatalogImageAudit::query()->where('snapshot_id', $snapshot->id)->where('status', 'pending')->exists()) {
            AuditSupplierCatalogImagesJob::dispatch($snapshot->id);
            return;
        }

        if (SupplierCatalogImageAudit::query()->where('snapshot_id', $snapshot->id)->where('status', 'processing')->exists()) {
            return;
        }

        $databaseAudit = $this->auditDatabaseImagesForSnapshot($snapshot);
        if ($databaseAudit['pending'] > 0) {
            AuditSupplierCatalogImagesJob::dispatch($snapshot->id)->delay(now()->addSecond());
            return;
        }
        $this->updateImageAuditSummary($snapshot, 'completed');
    }

    public function imageAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 10, ?string $search = null, array $statuses = []): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        // This endpoint is paginated, therefore it must not hydrate the large
        // raw payload of every source row or return every mismatch to the UI.
        // Both made the image tab transfer several megabytes before it could
        // render the first ten rows.
        $items = $this->snapshotItemsQuery($snapshot, $search)
            ->get(['id', 'snapshot_id', 'external_sku', 'name', 'raw_payload']);
        $imageBaseUrl = $this->supplierImageBaseUrl($snapshot->supplier_code);
        $imageSourceFields = $this->imageSourceFields($snapshot->supplier_code);
        $sourceUrlGroups = [];
        $sourceItemsWithImages = 0;
        foreach ($items as $item) {
            $sources = $this->imageSourcesForItem($item, $imageSourceFields, $imageBaseUrl);
            if ($sources !== []) {
                $sourceItemsWithImages++;
            }
            foreach ($sources as $source) {
                $sourceUrlGroups[$source['url']][] = $item->external_sku;
            }
        }

        $matches = $this->resolveImageSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code)
            ->mapWithKeys(fn (array $match, string $sku) => [$this->normalizeSku($sku) => $match]);
        $contentAuditStatus = $this->imageContentAuditStatus($snapshot);
        $sourceAudits = SupplierCatalogImageAudit::query()
            ->where('snapshot_id', $snapshot->id)
            ->get(['source_url_hash', 'status', 'content_hash', 'perceptual_hash', 'normalized_crop_hash', 'normalized_white_hash', 'mime_type', 'width', 'height', 'error_message'])
            ->keyBy('source_url_hash');
        $variations = $matches->where('type', 'variation')->pluck('model');
        $goods = $matches
            ->map(fn (array $match) => $match['type'] === 'good' ? $match['model'] : $match['model']->good)
            ->filter()
            ->unique('id')
            ->values();
        $variationIds = $variations->pluck('id');
        $goodIds = $goods->pluck('id');
        $images = ($variationIds->isEmpty() && $goodIds->isEmpty())
            ? collect()
            : ShopGoodImage::query()
                ->where(function ($query) use ($variationIds, $goodIds) {
                    if ($variationIds->isNotEmpty()) $query->whereIn('variation_id', $variationIds);
                    if ($goodIds->isNotEmpty()) $query->orWhereIn('good_id', $goodIds);
                })
                ->get(['id', 'good_id', 'variation_id', 'file_path', 'is_main', 'source_content_hash', 'content_hash', 'perceptual_hash', 'image_width', 'image_height']);
        $pathGroups = $images->groupBy('file_path')->filter(fn (Collection $group) => $group->count() > 1);
        // Lookups by owner avoid scanning every database image for every row in
        // the source file (6k source rows can otherwise become tens of millions
        // of comparisons).
        $imagesByVariation = $images->whereNotNull('variation_id')->groupBy('variation_id');
        $imagesByGood = $images->whereNull('variation_id')->groupBy('good_id');
        $coverageMismatches = [];
        $imageComparisons = [];
        $sourceHashBackfill = [];
        $excludedWithoutDatabaseMatch = 0;
        foreach ($items as $item) {
            $match = $matches->get($this->normalizeSku($item->external_sku));
            $sourceSources = collect($this->imageSourcesForItem($item, $imageSourceFields, $imageBaseUrl));
            $sourceUrls = $sourceSources->pluck('url')->values();
            $sourceFieldsByUrl = $sourceSources->mapWithKeys(fn (array $source) => [$source['url'] => $source['source_fields']]);
            if (! $match) {
                $excludedWithoutDatabaseMatch++;
                continue;
            }
            $variation = $match['type'] === 'variation' ? $match['model'] : null;
            $good = $variation ? $variation->good : $match['model'];
            // Some old variation rows can survive after the parent good was
            // deleted. They are not valid database matches and must not break
            // the whole audit response with null-property warnings.
            if (! $good) {
                $excludedWithoutDatabaseMatch++;
                continue;
            }
            $databaseImages = ($variation
                ? ($imagesByVariation->get($variation->id) ?? collect())
                : ($imagesByGood->get($good->id) ?? collect()))
                ->values()
                ->all();
            $databasePaths = collect($databaseImages)->pluck('file_path')->all();
            $availableImages = $databaseImages;
            $imagePairs = [];
            foreach ($sourceUrls as $sourceUrl) {
                $sourceAudit = $sourceAudits->get($this->sourceUrlHash($sourceUrl));
                $sourceStatus = $sourceAudit?->status ?? 'not_audited';
                $comparisonType = 'different';
                $imageIndex = false;
                if ($sourceStatus === 'available' && $sourceAudit?->content_hash) {
                    $imageIndex = collect($availableImages)->search(fn (ShopGoodImage $image) =>
                        $image->source_content_hash === $sourceAudit->content_hash || $image->content_hash === $sourceAudit->content_hash);
                    if ($imageIndex !== false) {
                        $comparisonType = 'content';
                    }
                }
                if ($imageIndex === false && $sourceStatus === 'available') {
                    $imageIndex = collect($availableImages)->search(function (ShopGoodImage $image) use ($sourceAudit): bool {
                        $distance = $this->perceptualHashDistance($sourceAudit?->perceptual_hash, $image->perceptual_hash);
                        return $distance !== null && $distance <= 6;
                    });
                    if ($imageIndex !== false) {
                        $comparisonType = 'visual';
                    }
                }
                if ($imageIndex === false && $sourceStatus === 'available') {
                    $imageIndex = collect($availableImages)->search(function (ShopGoodImage $image) use ($sourceAudit): bool {
                        if (! $this->isImageAtAuditTargetAspect($image)) {
                            return false;
                        }
                        foreach ([$sourceAudit?->normalized_crop_hash, $sourceAudit?->normalized_white_hash] as $hash) {
                            $distance = $this->perceptualHashDistance($hash, $image->perceptual_hash);
                            if ($distance !== null && $distance <= 6) {
                                return true;
                            }
                        }

                        return false;
                    });
                    if ($imageIndex !== false) {
                        $comparisonType = 'normalized';
                    }
                }
                if ($imageIndex === false && ($sourceStatus === 'not_audited' || collect($availableImages)->contains(fn (ShopGoodImage $image) => $image->content_hash === null))) {
                    $imageIndex = collect($availableImages)->search(fn (ShopGoodImage $image) => $this->sourceImageMatchesDatabasePath($sourceUrl, $image->file_path));
                    if ($imageIndex !== false) {
                        $comparisonType = 'filename';
                    }
                }
                $databaseImage = $imageIndex === false ? null : $availableImages[$imageIndex];
                if ($databaseImage && $sourceAudit?->content_hash
                    && in_array($comparisonType, ['content', 'visual', 'normalized'], true)
                    && $databaseImage->source_content_hash !== $sourceAudit->content_hash) {
                    $sourceHashBackfill[$databaseImage->id] = [
                        'source_content_hash' => $sourceAudit->content_hash,
                        // MySQL validates non-nullable columns before checking
                        // the duplicate id. The path is not updated by upsert.
                        'file_path' => $databaseImage->file_path,
                    ];
                }
                if ($imageIndex !== false) unset($availableImages[$imageIndex]);
                $imagePairs[] = [
                    'source_url' => $sourceUrl,
                    'source_filename' => $this->sourceImageFileName($sourceUrl),
                    'source_fields' => $sourceFieldsByUrl->get($sourceUrl, []),
                    'source_status' => $sourceStatus,
                    'source_error' => $sourceAudit?->error_message,
                    'comparison_type' => $comparisonType,
                    'database_path' => $databaseImage?->file_path,
                    'database_filename' => $databaseImage ? $this->sourceImageFileName($databaseImage->file_path) : null,
                    'is_match' => $databaseImage !== null,
                ];
            }
            $sourceStatusCounts = collect($imagePairs)->countBy('source_status');
            $sourceTotalCount = $sourceUrls->count();
            $availableSourceCount = (int) ($sourceStatusCounts['available'] ?? 0);
            $brokenSourceCount = (int) ($sourceStatusCounts['broken'] ?? 0);
            $unsupportedSourceCount = (int) ($sourceStatusCounts['unsupported'] ?? 0);
            $hasUnavailableSource = ($brokenSourceCount + $unsupportedSourceCount) > 0;
            $hasAvailableSource = $contentAuditStatus['status'] === 'completed' ? $availableSourceCount > 0 : $sourceTotalCount > 0;
            $expectedCount = $contentAuditStatus['status'] === 'completed' ? $availableSourceCount : $sourceTotalCount;
            $actualCount = count($databasePaths);
            $isMatch = ! $hasUnavailableSource && $expectedCount === $actualCount && collect($imagePairs)->every('is_match');
            $matchSummary = collect($imagePairs)->countBy('comparison_type')->all();
            $variationLabel = $variation
                ? $variation->attributeValues
                    ->map(fn ($value) => trim(($value->attribute?->name ? $value->attribute->name.': ' : '').(string) $value->value))
                    ->filter()
                    ->implode(', ')
                : null;
            $comparison = [
                'item_id' => $item->id,
                'external_sku' => $this->sourceSkuForItem($item, $snapshot->supplier_code) ?: $item->external_sku,
                'match_type' => $match['type'],
                'variation_id' => $variation?->id,
                'variation_label' => $variationLabel,
                'good_id' => $good->id,
                'group_key' => 'good-'.$good->id,
                'source_total_count' => $sourceTotalCount,
                'expected_count' => $expectedCount,
                'available_source_count' => $availableSourceCount,
                'broken_source_count' => $brokenSourceCount,
                'unsupported_source_count' => $unsupportedSourceCount,
                'has_available_source' => $hasAvailableSource,
                'has_unavailable_source' => $hasUnavailableSource,
                'database_count' => $actualCount,
                'is_match' => $isMatch,
                'status' => $isMatch ? 'match' : 'different',
                'source_urls' => $sourceUrls->all(),
                'database_paths' => $databasePaths,
                'image_pairs' => $imagePairs,
                'match_summary' => $matchSummary,
                'database_only_paths' => collect($availableImages)->pluck('file_path')->values()->all(),
                'good_name' => $good->name,
                'good_slug' => $good->slug,
            ];
            $imageComparisons[] = $comparison;
            if (! $comparison['is_match']) {
                $coverageMismatches[] = $comparison;
            }
        }
        if ($sourceHashBackfill !== []) {
            DB::table('shop_good_images')->upsert(
                collect($sourceHashBackfill)->map(fn (array $row, int $id) => ['id' => $id, ...$row])->values()->all(),
                ['id'],
                ['source_content_hash'],
            );
        }
        // The audit must stay metadata-only. Reading and hashing local files here
        // blocks the API while hundreds of images are on disk. Images themselves
        // are loaded only after the user opens the comparison modal.
        $hashGroups = [];
        $scannedPaths = 0;

        $stats = [
            'matched' => count(array_filter($imageComparisons, fn (array $row) => $row['is_match'])),
            'different' => count($coverageMismatches),
            'actionable_different' => collect($coverageMismatches)->where('has_available_source', true)->count(),
            'filename_matches' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('comparison_type', 'filename')->count(),
            'content_matches' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('comparison_type', 'content')->count(),
            'visual_matches' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('comparison_type', 'visual')->count(),
            'normalized_matches' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('comparison_type', 'normalized')->count(),
            'broken_source_urls' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('source_status', 'broken')->count(),
            'unchecked_source_urls' => collect($imageComparisons)->flatMap(fn (array $row) => $row['image_pairs'])->where('source_status', 'not_audited')->count(),
        ];
        if ($statuses !== []) {
            $imageComparisons = array_values(array_filter($imageComparisons, function (array $row) use ($statuses): bool {
                return collect($statuses)->contains(function (string $status) use ($row): bool {
                    if (in_array($status, ['match', 'different'], true)) {
                        return $row['status'] === $status;
                    }
                    if ($status === 'broken') {
                        return collect($row['image_pairs'])->contains(fn (array $pair) => $pair['source_status'] === 'broken');
                    }
                    if ($status === 'visual') {
                        return collect($row['image_pairs'])->contains(fn (array $pair) => in_array($pair['comparison_type'], ['visual', 'normalized'], true));
                    }
                    return collect($row['image_pairs'])->contains(fn (array $pair) => $pair['comparison_type'] === $status);
                });
            }));
        }
        $groupedComparisons = collect($imageComparisons)->groupBy('group_key')->map(function (Collection $rows) {
            $first = $rows->first();
            return [
                'good_id' => $first['good_id'],
                'good_name' => $first['good_name'],
                'good_slug' => $first['good_slug'],
                'rows' => $rows->values()->all(),
            ];
        })->values();
        $total = $groupedComparisons->count();
        $perPage = max(1, $perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pageGroups = $groupedComparisons->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'stats' => [
                'source_items' => $items->count(),
                'source_items_with_images' => $sourceItemsWithImages,
                'source_items_without_database_match' => $excludedWithoutDatabaseMatch,
                'source_items_compared' => count($imageComparisons),
                'source_fields' => $imageSourceFields,
                'source_images' => array_sum(array_map('count', $sourceUrlGroups)),
                'source_duplicate_url_groups' => count(array_filter($sourceUrlGroups, static fn (array $skus) => count($skus) > 1)),
                'database_variation_images' => $images->count(),
                'database_duplicate_path_groups' => $pathGroups->count(),
                'database_duplicate_content_groups' => count(array_filter($hashGroups, static fn (array $paths) => count($paths) > 1)),
                'image_count_mismatches' => count($coverageMismatches),
                'hashed_files' => $scannedPaths,
                'hash_scan_limited' => false,
                ...$stats,
            ],
            'content_audit' => $contentAuditStatus,
            'source_duplicate_urls' => collect($sourceUrlGroups)
                ->filter(fn (array $skus) => count($skus) > 1)
                ->take(50)
                ->map(fn (array $skus, string $url) => ['url' => $url, 'skus' => $skus])
                ->values(),
            'database_duplicate_paths' => $pathGroups->take(50)->map(fn (Collection $group, string $path) => [
                'file_path' => $path,
                'image_ids' => $group->pluck('id')->values(),
                'variation_ids' => $group->pluck('variation_id')->unique()->values(),
            ])->values(),
            'database_duplicate_content' => collect($hashGroups)
                ->filter(fn (array $paths) => count($paths) > 1)
                ->take(50)
                ->map(fn (array $paths, string $hash) => ['hash' => $hash, 'file_paths' => $paths])
                ->values(),
            // A small diagnostic sample is enough for the summary. The table
            // below is the authoritative, paginated list of every mismatch.
            'coverage_mismatches_sample' => array_slice($coverageMismatches, 0, 20),
            'image_comparisons' => $pageGroups,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** @return array<int, int> */
    public function imageSelection(SupplierCatalogSnapshot $snapshot, ?string $search = null): array
    {
        return collect($this->imageAudit($snapshot, 1, 100000, $search, ['different'])['image_comparisons'])
            ->flatMap(fn (array $group) => $group['rows'])
            ->filter(fn (array $row) => ($row['has_available_source'] ?? false) && ! ($row['source_only'] ?? false))
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>, stats: array<string, int>} */
    public function propertyAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?string $search = null, array $statuses = []): array
    {
        $this->assertReadySnapshot($snapshot);
        $items = $this->bindingParticipatingItems($this->snapshotItemsQuery($snapshot, $search)->get(), $snapshot->supplier_code);
        $skuMatches = $this->resolveSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code)
            ->mapWithKeys(fn (array $match, string $sku) => [$this->normalizeSku($sku) => $match]);
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'product')
            ->whereNotNull('property_id')
            ->where('is_check_enabled', true)
            ->with('property:id,name')
            ->get();
        $mappingsByProperty = $mappings
            ->filter(fn (SupplierCatalogFieldMapping $mapping) => (int) $mapping->property_id > 0)
            ->groupBy(fn (SupplierCatalogFieldMapping $mapping) => (int) $mapping->property_id);
        $matchedGoodIds = $skuMatches
            ->map(fn (array $match) => $match['type'] === 'good' ? $match['model']->id : $match['model']->good?->id)
            ->filter()
            ->unique()
            ->values();
        $goodIds = $matchedGoodIds;
        $propertyQuery = DB::table('shop_good_properties as gp')
            ->join('shop_properties as p', 'p.id', '=', 'gp.property_id')
            ->whereIn('gp.good_id', $goodIds);
        if (Schema::hasColumn('shop_good_properties', 'value')) {
            $propertyQuery->select(['gp.good_id', 'gp.property_id', 'gp.value', 'p.name as property_name']);
        } else {
            $propertyQuery
                ->leftJoin('shop_property_values as pv', 'pv.id', '=', 'gp.shop_property_value_id')
                ->select(['gp.good_id', 'gp.property_id', 'pv.value', 'p.name as property_name']);
        }
        $properties = $goodIds->isEmpty() ? collect() : $propertyQuery
            ->get()
            ->groupBy(fn ($row) => $row->good_id.':'.$row->property_id);
        $rows = [];

        foreach ($items as $item) {
            $match = $skuMatches->get($this->normalizeSku($item->external_sku));
            if (! $match) {
                continue;
            }

            $good = $match['type'] === 'good' ? $match['model'] : $match['model']->good;
            if (! $good) {
                continue;
            }

            $goodId = $good->id;
            $differences = [];
            foreach ($mappingsByProperty as $propertyId => $propertyMappings) {
                /** @var Collection<int, SupplierCatalogFieldMapping> $propertyMappings */
                $sourceValues = $propertyMappings
                    ->map(fn (SupplierCatalogFieldMapping $mapping) => $this->propertySourceValueFromPayload($item->raw_payload ?? [], $mapping))
                    ->filter(fn (?string $value) => $value !== null)
                    ->unique(fn (string $value) => $this->normalizeComparisonValue($value))
                    ->values()
                    ->all();
                $databaseValues = $properties->get($goodId.':'.$propertyId, collect())
                    ->pluck('value')
                    ->map(fn ($value) => $this->nullableString($value))
                    ->filter(fn (?string $value) => $value !== null)
                    ->unique(fn (string $value) => $this->normalizeComparisonValue($value))
                    ->values()
                    ->all();
                $sourceNormalized = collect($sourceValues)->map(fn (string $value) => $this->normalizeComparisonValue($value))->sort()->values()->all();
                $databaseNormalized = collect($databaseValues)->map(fn (string $value) => $this->normalizeComparisonValue($value))->sort()->values()->all();
                $hasSource = $sourceValues !== [];
                $hasDatabase = $databaseValues !== [];
                $status = match (true) {
                    ! $hasSource && ! $hasDatabase => 'empty_both',
                    $hasSource && ! $hasDatabase => 'missing_in_database',
                    ! $hasSource && $hasDatabase => 'missing_in_file',
                    $sourceNormalized === $databaseNormalized => 'match',
                    default => 'different',
                };
                $firstMapping = $propertyMappings->first();
                $differences[] = [
                    'status' => $status,
                    'property' => $firstMapping?->property?->name,
                    'property_id' => (int) $propertyId,
                    'source_fields' => $propertyMappings->pluck('source_field')->values()->all(),
                    'source_field' => $propertyMappings->pluck('source_field')->implode(', '),
                    'source_value' => $sourceValues === [] ? null : implode(' / ', $sourceValues),
                    'source_values' => $sourceValues,
                    'database_values' => $databaseValues,
                ];
            }

            $hasSourceProperties = collect($differences)->contains(fn (array $diff) => $diff['source_values'] !== []);
            $hasDatabaseProperties = collect($differences)->contains(fn (array $diff) => $diff['database_values'] !== []);
            $hasActionableProperties = collect($differences)->contains(fn (array $diff) => in_array($diff['status'], ['different', 'missing_in_database'], true));
            $hasMissingInFileProperties = collect($differences)->contains(fn (array $diff) => $diff['status'] === 'missing_in_file');
            $rows[] = [
                'item_id' => $item->id,
                'external_sku' => $this->sourceSkuForItem($item, $snapshot->supplier_code) ?: $item->external_sku,
                'source_name' => $item->name,
                'source_name_missing' => $this->nullableString($item->name) === null,
                'database_good_id' => $goodId,
                'database_name' => $good->name,
                'database_slug' => $good->slug,
                'has_source_properties' => $hasSourceProperties,
                'has_database_properties' => $hasDatabaseProperties,
                'has_actionable_properties' => $hasActionableProperties,
                'differences' => $differences,
                'status' => $hasActionableProperties ? 'attention' : ($hasMissingInFileProperties ? 'missing_in_file' : 'match'),
            ];
        }

        $stats = [
            'matched' => count($rows),
            'attention' => count(array_filter($rows, fn (array $row) => $row['has_actionable_properties'])),
            'match' => count(array_filter($rows, fn (array $row) => collect($row['differences'])->isNotEmpty() && collect($row['differences'])->every(fn (array $diff) => in_array($diff['status'], ['match', 'empty_both'], true)))),
            'different' => count(array_filter($rows, fn (array $row) => collect($row['differences'])->contains(fn (array $diff) => $diff['status'] === 'different'))),
            'missing_in_database' => count(array_filter($rows, fn (array $row) => collect($row['differences'])->contains(fn (array $diff) => $diff['status'] === 'missing_in_database'))),
            'missing_in_file' => count(array_filter($rows, fn (array $row) => collect($row['differences'])->contains(fn (array $diff) => $diff['status'] === 'missing_in_file'))),
            'empty_both' => count(array_filter($rows, fn (array $row) => collect($row['differences'])->isNotEmpty() && collect($row['differences'])->every(fn (array $diff) => $diff['status'] === 'empty_both'))),
            'source_has_value' => count(array_filter($rows, fn (array $row) => $row['has_source_properties'])),
            'source_empty' => count(array_filter($rows, fn (array $row) => ! $row['has_source_properties'])),
            'database_has_value' => count(array_filter($rows, fn (array $row) => $row['has_database_properties'])),
            'database_empty' => count(array_filter($rows, fn (array $row) => ! $row['has_database_properties'])),
        ];
        if ($statuses !== []) {
            $rows = array_values(array_filter($rows, function (array $row) use ($statuses): bool {
                return collect($statuses)->contains(function (string $status) use ($row): bool {
                    return match ($status) {
                        'attention' => $row['has_actionable_properties'],
                        'source_has_value' => $row['has_source_properties'],
                        'source_empty' => ! $row['has_source_properties'],
                        'database_has_value' => $row['has_database_properties'],
                        'database_empty' => ! $row['has_database_properties'],
                        default => $row['status'] === $status || collect($row['differences'])->contains(fn (array $diff) => $diff['status'] === $status),
                    };
                });
            }));
        }
        $total = count($rows);
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'stats' => $stats,
        ];
    }
    /** @return array<int, int> */
    public function propertySelection(SupplierCatalogSnapshot $snapshot, ?string $search = null): array
    {
        return collect($this->propertyAudit($snapshot, 1, 100000, $search, ['attention'])['data'])
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function goodAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?array $onlyTargets = null, ?string $search = null, array $statuses = [], array $differenceTypes = [], bool $paginateByGood = false, ?int $goodId = null): array
    {
        $this->assertReadySnapshot($snapshot);
        $sourceItems = $this->bindingParticipatingItems($this->snapshotItemsQuery($snapshot, $search)->get(), $snapshot->supplier_code);
        $matches = $this->resolveSkuMatches(
            $sourceItems->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code)),
            $snapshot->supplier_code,
            $onlyTargets !== null,
        );
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'good')
            ->where('is_check_enabled', true)
            ->when(
                $onlyTargets !== null,
                fn ($query) => $query->whereIn('conditions->target', $onlyTargets),
                fn ($query) => $query->whereIn('conditions->target', ['name', 'short_description', 'description', 'brand', 'weight', 'depth', 'width', 'height']),
            )
            ->get();
        $mappedTargets = $mappings->map(fn (SupplierCatalogFieldMapping $mapping) => (string) data_get($mapping->conditions, 'target'))->filter()->unique()->values()->all();
        $expectedTargets = $mappedTargets;
        $goodIds = $matches->map(fn (array $match) => $match['type'] === 'good' ? $match['model']->id : $match['model']->good_id)->unique()->values();
        $goods = ShopGood::query()->whereIn('id', $goodIds)->with('brands:id,name')->get()->keyBy('id');
        $rows = [];

        foreach ($sourceItems as $item) {
            $sourceSku = $this->sourceSkuForItem($item, $snapshot->supplier_code);
            $match = $matches->get($sourceSku);
            $good = $match ? $goods->get($match['type'] === 'good' ? $match['model']->id : $match['model']->good_id) : null;
            // Product metadata belongs to the parent good, while prices and
            // stocks belong to the exact SKU (variation when it exists).
            $comparisonModel = $onlyTargets !== null && $match
                ? ($match['type'] === 'variation' ? $match['model'] : $good)
                : $good;
            $differences = [];
            if (! $good) {
                foreach ($mappings as $mapping) {
                    $target = (string) data_get($mapping->conditions, 'target');
                    $sourceValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
                    if ($target === '' || $sourceValue === null) {
                        continue;
                    }
                    $comparisonSourceValue = $this->normalizeMappedGoodValue(
                        $this->applyMappingAdjustment($sourceValue, $mapping->conditions ?? []),
                        $target,
                    );
                    $differences[] = [
                        'status' => 'missing_in_database',
                        'field' => $target,
                        'source_field' => $mapping->source_field,
                        'source_value' => $comparisonSourceValue,
                        'database_values' => [],
                    ];
                }
                if ($differences === []) {
                    $differences[] = ['status' => 'source_sku_not_found', 'field' => null, 'source_value' => null, 'database_values' => []];
                }
            } else {
                foreach ($mappings as $mapping) {
                    $target = (string) data_get($mapping->conditions, 'target');
                    $sourceValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
                    if ($target === '' || $sourceValue === null) {
                        continue;
                    }
                    $comparisonSourceValue = $this->normalizeMappedGoodValue(
                        $this->applyMappingAdjustment($sourceValue, $mapping->conditions ?? []),
                        $target,
                    );
                    $databaseValues = $target === 'brand'
                        ? $good->brands->pluck('name')->values()->all()
                        : [trim((string) ($comparisonModel->{$target} ?? ''))];
                    $databaseValues = array_values(array_filter($databaseValues, static fn ($value) => $value !== ''));
                    $matchesValue = collect($databaseValues)->contains(fn (string $value) => $this->mappedGoodValuesMatch($target, $value, $comparisonSourceValue));
                    $differences[] = [
                        'status' => empty($databaseValues) ? 'missing_in_database' : ($matchesValue ? 'match' : 'different'),
                        'field' => $target,
                        'source_field' => $mapping->source_field,
                        'source_value' => $comparisonSourceValue,
                        'source_raw_value' => $sourceValue,
                        'database_values' => $databaseValues,
                    ];
                }
            }
            $rows[] = [
                'item_id' => $item->id,
                'external_sku' => $this->sourceSkuForItem($item, $snapshot->supplier_code) ?: $item->external_sku,
                'source_name' => $item->name,
                'database_match_type' => $match['type'] ?? null,
                'database_value_source' => $onlyTargets !== null && $match
                    ? ($match['type'] === 'variation' ? 'variation' : 'good')
                    : 'good',
                'database_good_id' => $good?->id,
                'database_name' => $good?->name,
                'database_slug' => $good?->slug,
                'database_variation_id' => $match && $match['type'] === 'variation' ? $match['model']->id : null,
                'source_is_variation' => $this->isSourceVariationItem($item),
                'differences' => $differences,
                'status' => $this->nullableString($item->name) === null
                    ? 'source_name_missing'
                    : (! $good ? 'not_found' : (collect($differences)->contains(fn (array $diff) => ! in_array($diff['status'], ['match'], true)) ? 'attention' : 'match')),
            ];
        }

        // Search must not turn hidden rows into deletion candidates. The absence
        // check always uses the complete parsed file, not the filtered result.
        $sourceSkus = $snapshot->items()
            ->get(['external_sku', 'raw_payload'])
            ->map(fn (SupplierCatalogItem $item) => $this->sourceSkuForItem($item, $snapshot->supplier_code))
            ->filter()
            ->map(fn ($sku) => (string) $sku)
            ->all();
        $supplierNames = $this->supplierNames($snapshot->supplier_code);
        $databaseOnlyGoods = ShopGood::query()
            ->whereIn('supplier', $supplierNames)
            ->with(['variations:id,good_id,sku', 'brands:id,name'])
            ->when(trim((string) $search) !== '', function ($query) use ($search) {
                $term = '%'.addcslashes(trim((string) $search), '%_\\').'%';
                $query->where(fn ($nested) => $nested->where('sku', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->get()
            ->filter(function (ShopGood $good) use ($sourceSkus): bool {
                if (in_array((string) $good->sku, $sourceSkus, true)) {
                    return false;
                }

                return $good->variations->every(fn (ShopGoodVariation $variation) => ! in_array((string) $variation->sku, $sourceSkus, true));
            });
        foreach ($databaseOnlyGoods as $good) {
            $databaseDifferences = collect($expectedTargets)->map(function (string $target) use ($good): array {
                $values = $target === 'brand'
                    ? $good->brands->pluck('name')->values()->all()
                    : [trim((string) ($good->{$target} ?? ''))];
                return [
                    'status' => 'missing_in_file',
                    'field' => $target,
                    'source_value' => null,
                    'database_values' => array_values(array_filter($values, static fn ($value) => $value !== '')),
                ];
            })->all();
            $rows[] = [
                'item_id' => null,
                'external_sku' => $good->sku,
                'source_name' => null,
                'database_match_type' => 'good',
                'database_good_id' => $good->id,
                'database_name' => $good->name,
                'database_slug' => $good->slug,
                'database_variation_id' => null,
                'source_is_variation' => false,
                'source_name_missing' => false,
                'differences' => $databaseDifferences,
                'status' => 'missing_in_file',
            ];
        }

        $allAuditRows = collect($rows);
        if ($goodId !== null && $goodId > 0) {
            $rows = array_values(array_filter($rows, fn (array $row) => (int) ($row['database_good_id'] ?? 0) === $goodId));
        }
        if ($statuses !== []) {
            $rows = array_values(array_filter($rows, fn (array $row) => in_array($row['status'], $statuses, true)));
        } elseif ($onlyTargets === null) {
            // The product-fields table compares only real file↔database pairs.
            // Orphans are reported as statistics, not mixed into comparisons.
            $rows = array_values(array_filter($rows, fn (array $row) => ! in_array($row['status'], [
                'source_name_missing', 'not_found', 'missing_in_file',
            ], true)));
        }
        $priceFields = ['price', 'sale_price', 'demping_price'];
        $stockFields = ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'];
        $sourceOrphans = 0;
        $databaseOrphans = 0;
        if ($onlyTargets !== null && $paginateByGood) {
            $sourceOrphans = count(array_filter($rows, fn (array $row) => $row['item_id'] && ! $row['database_good_id']));
            $databaseOrphans = count(array_filter($rows, fn (array $row) => ! $row['item_id'] && $row['database_good_id']));
            // Price and stock synchronization is only meaningful for a matched
            // source row and a concrete database target.
            $rows = array_values(array_filter($rows, fn (array $row) => $row['item_id'] && $row['database_good_id']));
        }
        $statsRows = collect($rows);
        if ($differenceTypes !== []) {
            $rows = array_values(array_filter($rows, function (array $row) use ($differenceTypes, $priceFields, $stockFields): bool {
                return collect($row['differences'])->contains(function (array $difference) use ($differenceTypes, $priceFields, $stockFields): bool {
                    if (in_array($difference['status'], ['match', 'not_mapped'], true)) {
                        return false;
                    }
                    return (in_array('prices', $differenceTypes, true) && in_array($difference['field'], $priceFields, true))
                        || (in_array('stocks', $differenceTypes, true) && in_array($difference['field'], $stockFields, true));
                });
            }));
        }
        usort($rows, fn (array $left, array $right) => strcmp((string) ($left['source_name'] ?? $left['database_name']), (string) ($right['source_name'] ?? $right['database_name'])));
        $allRows = collect($rows);
        $groupKey = fn (array $row): string => $row['database_good_id']
            ? 'good-'.$row['database_good_id']
            : 'source-'.$row['external_sku'];
        $countDifferenceGroups = function (array $fields) use ($statsRows, $groupKey): int {
            return $statsRows
                ->filter(fn (array $row) => collect($row['differences'])->contains(
                    fn (array $difference) => in_array($difference['field'], $fields, true)
                        && ! in_array($difference['status'], ['match', 'not_mapped'], true),
                ))
                ->groupBy($groupKey)
                ->count();
        };
        if ($paginateByGood) {
            $groups = $allRows->groupBy(fn (array $row) => $row['database_good_id'] ? 'good-'.$row['database_good_id'] : 'source-'.$row['external_sku']);
            $total = $groups->count();
            $pageItems = $groups->forPage($page, $perPage)->flatten(1)->values()->all();
        } else {
            $total = $allRows->count();
            $pageItems = $allRows->slice(($page - 1) * $perPage, $perPage)->values()->all();
        }
        $paginator = new LengthAwarePaginator($pageItems, $total, $perPage, $page);

        return [
            'data' => $paginator->items(),
            'stats' => [
                'price_differences' => $countDifferenceGroups($priceFields),
                'stock_differences' => $countDifferenceGroups($stockFields),
                'goods_with_differences' => $statsRows
                    ->filter(fn (array $row) => collect($row['differences'])->contains(
                        fn (array $difference) => in_array($difference['field'], array_merge($priceFields, $stockFields), true)
                            && ! in_array($difference['status'], ['match', 'not_mapped'], true),
                    ))
                    ->groupBy($groupKey)
                    ->count(),
                'synchronized' => $statsRows
                    ->filter(fn (array $row) => collect($row['differences'])->every(
                        fn (array $difference) => in_array($difference['status'], ['match', 'not_mapped'], true),
                    ))
                    ->groupBy($groupKey)
                    ->count(),
                'source_orphans' => $sourceOrphans,
                'database_orphans' => $databaseOrphans,
                'attention' => $statsRows->where('status', 'attention')->count(),
                'not_found' => $allAuditRows->where('status', 'not_found')->count(),
                'missing_in_file' => $allAuditRows->where('status', 'missing_in_file')->count(),
                'source_name_missing' => collect($sourceItems)
                    ->filter(fn (SupplierCatalogItem $item) => $this->nullableString($item->name) === null)
                    ->count(),
            ],
            'mapped_targets' => $mappedTargets,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<int, int> */
    public function priceStockSelection(SupplierCatalogSnapshot $snapshot, ?string $search = null, array $filters = [], ?int $goodId = null): array
    {
        $audit = $this->goodAudit(
            $snapshot,
            1,
            100000,
            ['price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
            $search,
            [],
            $filters,
            true,
            $goodId,
        );

        return collect($audit['data'])->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, int> */
    public function goodSelection(SupplierCatalogSnapshot $snapshot, string $status, ?string $search = null): array
    {
        $audit = $this->goodAudit($snapshot, 1, 100000, null, $search, [$status]);
        $key = $status === 'missing_in_file' ? 'database_good_id' : 'item_id';

        return collect($audit['data'])
            ->when($status === 'not_found', fn (Collection $rows) => $rows->filter(fn (array $row) => ! ($row['source_name_missing'] ?? false)))
            ->pluck($key)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $conditions */
    private function applyMappingAdjustment(string $value, array $conditions): string
    {
        if (($conditions['target'] ?? null) === 'name' || ($conditions['name_transform'] ?? 'none') === 'strip_trailing_parentheses') {
            $value = $this->sourceGroupName($value, $value);
        }
        $operation = $conditions['adjustment'] ?? 'none';
        $amount = (float) ($conditions['adjustment_value'] ?? 0);
        if ($operation === 'none' || $amount == 0.0 || ! is_numeric(str_replace(',', '.', $value))) {
            return $value;
        }

        $number = (float) str_replace(',', '.', $value);
        $number = match ($operation) {
            'add_percent' => $number * (1 + $amount / 100),
            'subtract_percent' => $number * (1 - $amount / 100),
            'add_fixed' => $number + $amount,
            'subtract_fixed' => $number - $amount,
            default => $number,
        };

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function isPhysicalGoodField(string $target): bool
    {
        return in_array($target, ['weight', 'depth', 'width', 'height'], true);
    }

    private function normalizeMappedGoodValue(string $value, string $target): string
    {
        if (in_array($target, ['price', 'sale_price', 'demping_price'], true)) {
            $decimal = $this->nullableDecimal($value);

            return $decimal === null ? $value : rtrim(rtrim(number_format($decimal, 2, '.', ''), '0'), '.');
        }

        return $this->isPhysicalGoodField($target)
            ? $this->normalizePhysicalGoodValue($value, $target)
            : $value;
    }

    private function mappedGoodValuesMatch(string $target, mixed $databaseValue, mixed $sourceValue): bool
    {
        if (in_array($target, ['price', 'sale_price', 'demping_price'], true)) {
            $databaseDecimal = $this->nullableDecimal($databaseValue);
            $sourceDecimal = $this->nullableDecimal($sourceValue);
            if ($databaseDecimal !== null && $sourceDecimal !== null) {
                return (int) floor($databaseDecimal) === (int) floor($sourceDecimal);
            }
        }

        return $this->normalizeComparisonValue((string) $databaseValue) === $this->normalizeComparisonValue((string) $sourceValue);
    }
    /**
     * Store weight in kilograms and dimensions in centimetres, matching the
     * delivery fields used by the shop. Source values may contain a unit.
     */
    private function normalizePhysicalGoodValue(string $value, string $target): string
    {
        $normalized = mb_strtolower(trim(str_replace(',', '.', $value)));
        if (! preg_match('/[-+]?\d+(?:\.\d+)?/', $normalized, $matches)) {
            return $value;
        }

        $number = (float) $matches[0];
        if ($target === 'weight') {
            if (preg_match('/(?:\bkg\b|кг)/u', $normalized)) {
                // Stored weight is measured in kilograms.
            } elseif (preg_match('/(?:\bg\b|гр\.?|г\b)/u', $normalized)) {
                $number /= 1000;
            }
        } elseif (preg_match('/(?:\bmm\b|мм)/u', $normalized)) {
            $number /= 10;
        } elseif (preg_match('/(?:\bm\b|метр|м\b)/u', $normalized) && ! preg_match('/(?:\bcm\b|см)/u', $normalized)) {
            $number *= 100;
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    /** @return Collection<int, SupplierCatalogFieldMapping> */
    private function priceFieldMappings(string $supplierCode): Collection
    {
        $sourceFields = $this->minParsePriceSourceFields($supplierCode);

        return SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $supplierCode)
            ->where('scope', 'good')
            ->where('is_check_enabled', true)
            ->whereIn('conditions->target', ['price', 'sale_price', 'demping_price'])
            ->when($sourceFields !== [], fn ($query) => $query->whereIn('source_field', $sourceFields))
            ->get();
    }

    /** @return array{threshold: ?float, mode: string, source_fields: array<int, string>} */
    private function minParsePriceSettings(string $supplierCode): array
    {
        $settings = SupplierCatalogProfile::query()
            ->where('code', $supplierCode)
            ->value('settings') ?? [];
        $threshold = $this->nullableDecimal($settings['min_parse_price'] ?? null);

        return [
            'threshold' => $threshold !== null && $threshold > 0 ? $threshold : null,
            'mode' => ($settings['min_parse_price_mode'] ?? 'any') === 'all' ? 'all' : 'any',
            'source_fields' => $this->normalizeStringList($settings['min_parse_price_source_fields'] ?? []),
        ];
    }

    /** @return array<int, string> */
    private function minParsePriceSourceFields(string $supplierCode): array
    {
        $settings = SupplierCatalogProfile::query()
            ->where('code', $supplierCode)
            ->value('settings') ?? [];

        return $this->normalizeStringList($settings['min_parse_price_source_fields'] ?? []);
    }

    private function mappedPriceDecimal(SupplierCatalogItem $item, SupplierCatalogFieldMapping $mapping): ?float
    {
        $sourceValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
        if ($sourceValue === null) {
            return null;
        }
        $value = $this->applyMappingAdjustment($sourceValue, $mapping->conditions ?? []);
        if (trim($value) === '-') {
            return 0.0;
        }
        $normalized = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", ' '], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function sourcePriceLabelForItem(SupplierCatalogItem $item, Collection $priceMappings): ?string
    {
        $values = $priceMappings
            ->map(fn (SupplierCatalogFieldMapping $mapping) => $this->nullableString($this->applyMappingAdjustment((string) ($item->raw_payload[$mapping->source_field] ?? ''), $mapping->conditions ?? [])))
            ->filter()
            ->unique()
            ->values();

        return $values->isEmpty() ? null : $values->implode(' / ');
    }

    private function sourceItemPassesMinPrice(SupplierCatalogItem $item, string $supplierCode, Collection $priceMappings): bool
    {
        $settings = $this->minParsePriceSettings($supplierCode);
        if ($settings['threshold'] === null || $priceMappings->isEmpty()) {
            return true;
        }

        $checks = $priceMappings
            ->map(fn (SupplierCatalogFieldMapping $mapping) => $this->mappedPriceDecimal($item, $mapping))
            ->map(fn (?float $value) => $value !== null && $value >= $settings['threshold']);

        return $settings['mode'] === 'all'
            ? $checks->every(fn (bool $passed) => $passed)
            : $checks->contains(true);
    }

    /** @param Collection<int, SupplierCatalogItem> $items @return Collection<int, SupplierCatalogItem> */
    private function bindingParticipatingItems(Collection $items, string $supplierCode): Collection
    {
        $priceMappings = $this->priceFieldMappings($supplierCode);

        return $items
            ->filter(fn (SupplierCatalogItem $item) => $this->sourceItemPassesMinPrice($item, $supplierCode, $priceMappings))
            ->values();
    }
    /** @param Collection<int, SupplierCatalogFieldMapping> $mappings @return array<string, mixed> */
    private function mappedGoodAttributes(SupplierCatalogItem $item, Collection $mappings): array
    {
        $attributes = [];
        $allowedTargets = [
            'name', 'short_description', 'description', 'brand', 'weight', 'depth', 'width', 'height', 'price', 'sale_price', 'demping_price',
            'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity',
        ];
        foreach ($mappings as $mapping) {
            $target = (string) data_get($mapping->conditions, 'target');
            $sourceValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
            if (! in_array($target, $allowedTargets, true) || $sourceValue === null) {
                continue;
            }
            $value = $this->applyMappingAdjustment($sourceValue, $mapping->conditions ?? []);
            $attributes[$target] = $this->isPhysicalGoodField($target)
                ? $this->normalizePhysicalGoodValue($value, $target)
                : $value;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $payload @param Collection<int, SupplierCatalogFieldMapping> $propertyMappings */
    private function createMappedGood(string $name, string $sku, string $supplierName, array $attributes, array $payload, Collection $propertyMappings): ShopGood
    {
        $brand = $this->nullableString($attributes['brand'] ?? null);
        unset($attributes['brand']);
        $good = ShopGood::create([
            ...$attributes,
            'name' => $name,
            'sku' => $sku,
            'supplier' => $supplierName,
            'is_active' => false,
            'is_show' => false,
            'slug' => $this->uniqueGoodSlug($name),
        ]);
        if ($brand !== null) {
            $brandModel = ShopBrand::firstOrCreate(['name' => $brand], ['is_active' => true]);
            $good->brands()->syncWithoutDetaching([$brandModel->id]);
        }
        $this->syncMappedProperties($good, $payload, $propertyMappings);

        return $good;
    }

    /** @param array<string, mixed> $payload */
    private function propertySourceValueFromPayload(array $payload, SupplierCatalogFieldMapping $mapping): ?string
    {
        $rawValue = $this->nullableString($payload[$mapping->source_field] ?? null);
        if ($rawValue === null) {
            return null;
        }

        return $mapping->display_field
            ? ($this->nullableString($payload[$mapping->display_field] ?? null) ?? $rawValue)
            : $rawValue;
    }
    /** @param array<string, mixed> $payload @param Collection<int, SupplierCatalogFieldMapping> $mappings */
    private function syncMappedProperties(ShopGood $good, array $payload, Collection $mappings, bool $replace = false): void
    {
        $propertyIds = $mappings->pluck('property_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($replace && $propertyIds->isNotEmpty()) {
            ShopGoodProperty::query()
                ->where('good_id', $good->id)
                ->whereIn('property_id', $propertyIds)
                ->delete();
        }
        foreach ($mappings as $mapping) {
            $propertyId = (int) $mapping->property_id;
            $value = $this->propertySourceValueFromPayload($payload, $mapping);
            if ($propertyId === 0 || $value === null) {
                continue;
            }
            $propertyValue = PropertyValue::firstOrCreate([
                'property_id' => $propertyId,
                'value' => $value,
            ], [
                'is_active' => true,
                'sort_order' => 0,
            ]);
            $attributes = ['shop_property_value_id' => $propertyValue->id];
            if (Schema::hasColumn('shop_good_properties', 'value')) {
                $attributes['value'] = $value;
            }
            ShopGoodProperty::updateOrCreate(['good_id' => $good->id, 'property_id' => $propertyId], $attributes);
        }
    }

    private function uniqueGoodSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'supplier-good';
        $slug = $base;
        $index = 2;
        while (ShopGood::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function snapshotItemsQuery(SupplierCatalogSnapshot $snapshot, ?string $search)
    {
        return $snapshot->items()
            ->when(trim((string) $search) !== '', function ($query) use ($search) {
                $term = '%'.addcslashes(trim((string) $search), '%_\\').'%';
                $query->where(function ($nested) use ($term) {
                    $nested->where('external_sku', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('brand', 'like', $term)
                        ->orWhere('image_urls', 'like', $term);
                });
            })
            ->orderBy('id');
    }

    /** @return Collection<int, SupplierCatalogItem> */
    private function sourceVariationItems(SupplierCatalogSnapshot $snapshot): Collection
    {
        return $snapshot->items()
            ->get(['id', 'name', 'clean_name', 'source_color', 'source_size', 'is_source_variation', 'external_sku', 'source_axes', 'raw_payload'])
            ->filter(fn (SupplierCatalogItem $item) => $this->isSourceVariationItem($item))
            ->values();
    }

    /** @return Collection<int, SupplierCatalogItem> */
    private function sourceSingleItems(SupplierCatalogSnapshot $snapshot): Collection
    {
        return $snapshot->items()
            ->get(['id', 'name', 'clean_name', 'source_year', 'is_source_variation', 'external_sku', 'raw_payload'])
            ->filter(fn (SupplierCatalogItem $item) => ! $this->isSourceVariationItem($item))
            ->values();
    }


    /** @param array<int, array<string, mixed>> $targetAxes */
    private function applyVariationAxisRepair(ShopGoodVariation $variation, array $targetAxes, string $action): bool
    {
        $currentValueIds = $variation->attributeValues->pluck('id')->map(fn ($id) => (int) $id)->all();
        $currentAttributeIds = $variation->attributeValues->pluck('attribute_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sourceAttributeIds = collect($targetAxes)->pluck('attribute_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $targetValueIds = collect($targetAxes)->map(function (array $axis) {
            return (int) ShopVariationAttributeValue::firstOrCreate([
                'attribute_id' => (int) $axis['attribute_id'],
                'value' => (string) $axis['value'],
            ])->id;
        })->unique()->values()->all();
        $missingTargetValueIds = collect($targetAxes)
            ->filter(fn (array $axis) => ! in_array((int) $axis['attribute_id'], $currentAttributeIds, true))
            ->map(function (array $axis) {
                return (int) ShopVariationAttributeValue::firstOrCreate([
                    'attribute_id' => (int) $axis['attribute_id'],
                    'value' => (string) $axis['value'],
                ])->id;
            })
            ->unique()
            ->values()
            ->all();

        $nextValueIds = match ($action) {
            'add_missing_axes' => array_values(array_unique([...$currentValueIds, ...$missingTargetValueIds])),
            'replace_axis_values' => array_values(array_unique([
                ...$variation->attributeValues
                    ->reject(fn (ShopVariationAttributeValue $value) => in_array((int) $value->attribute_id, $sourceAttributeIds, true))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                ...$targetValueIds,
            ])),
            'remove_extra_axes' => $variation->attributeValues
                ->filter(fn (ShopVariationAttributeValue $value) => in_array((int) $value->attribute_id, $sourceAttributeIds, true))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            default => $targetValueIds,
        };

        sort($currentValueIds);
        sort($nextValueIds);
        if ($currentValueIds === $nextValueIds) {
            return false;
        }

        $variation->attributeValues()->sync($nextValueIds);

        return true;
    }

    /** @param array<int, array<string, mixed>> $comparisons @return array<int, string> */
    private function axisIssueTypes(array $comparisons): array
    {
        $types = [];
        foreach ($comparisons as $comparison) {
            $status = $comparison['status'] ?? null;
            if ($status === 'missing_in_database') {
                $types[] = 'missing';
            } elseif ($status === 'different') {
                $types[] = 'different';
            } elseif ($status === 'only_in_database') {
                $types[] = 'extra';
            }
        }

        return array_values(array_unique($types));
    }

    /** @param Collection<int, SupplierCatalogFieldMapping> $mappings @return array<int, array<string, mixed>> */
    private function variationComparisons(ShopGoodVariation $variation, ?SupplierCatalogItem $source, Collection $mappings, string $supplierCode): array
    {
        if ($source === null) {
            return [['status' => 'delete_candidate_variation', 'attribute' => 'Вариация', 'source_value' => 'Нет в файле', 'database_values' => []]];
        }

        $sourceAxes = collect($this->sourceAxesForItem($source, $mappings, $supplierCode))
            ->filter(static fn (array $axis) => ! empty($axis['is_check_enabled']))
            ->values();
        if ($sourceAxes->isEmpty()) {
            return [['status' => 'source_data_incomplete', 'attribute' => 'Цвет и размер', 'source_value' => 'В NAME нет пары «цвет, размер»', 'database_values' => []]];
        }

        $databaseAxes = $variation->attributeValues->groupBy('attribute_id')
            ->map(fn (Collection $values) => $values->pluck('value')->values()->all());
        $comparisons = [];
        $sourceAttributeIds = [];
        foreach ($sourceAxes as $axis) {
            $attributeId = (int) $axis['attribute_id'];
            $sourceAttributeIds[] = $attributeId;
            $databaseValues = $databaseAxes->get($attributeId, []);
            $isMatch = collect($databaseValues)->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue((string) $axis['value']));
            $comparisons[] = ['status' => empty($databaseValues) ? 'missing_in_database' : ($isMatch ? 'match' : 'different'), 'attribute' => $axis['attribute_name'], 'source_field' => $axis['source_field'], 'source_value' => $axis['value'], 'database_values' => $databaseValues];
        }
        foreach ($databaseAxes as $attributeId => $values) {
            if (! in_array((int) $attributeId, $sourceAttributeIds, true)) {
                $comparisons[] = ['status' => 'only_in_database', 'attribute' => $variation->attributeValues->firstWhere('attribute_id', $attributeId)?->attribute?->name, 'source_value' => null, 'database_values' => $values];
            }
        }

        return $comparisons;
    }

    /** @param Collection<int, SupplierCatalogFieldMapping> $mappings */
    private function hasSameSourceAxes(ShopGoodVariation $variation, SupplierCatalogItem $source, Collection $mappings, string $supplierCode): bool
    {
        $sourceAxes = collect($this->sourceAxesForItem($source, $mappings, $supplierCode))
            ->filter(static fn (array $axis) => ! empty($axis['is_check_enabled']))
            ->groupBy(fn (array $axis) => (int) $axis['attribute_id']);
        if ($sourceAxes->isEmpty()) {
            return false;
        }

        $databaseAxes = $variation->attributeValues
            ->groupBy('attribute_id')
            ->map(fn (Collection $values) => $values->pluck('value')->filter()->values());

        foreach ($sourceAxes as $attributeId => $axes) {
            $databaseValues = $databaseAxes->get((int) $attributeId, collect());
            if ($databaseValues->isEmpty()) {
                return false;
            }
            foreach ($axes as $axis) {
                $matches = $databaseValues->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue((string) $axis['value']));
                if (! $matches) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function singleProductVariationComparisons(ShopGoodVariation $variation, SupplierCatalogItem $source, string $supplierCode): array
    {
        $attributes = $this->standardVariationAttributeIds();
        $yearAttributeId = $attributes['Год'] ?? null;
        if ($supplierCode !== 'bikeproducts' || $yearAttributeId === null) {
            return $this->variationComparisons($variation, $source, collect(), $supplierCode);
        }

        if (! $this->isSourceVariationItem($source)) {
            $comparisons = [[
                'status' => 'match',
                'attribute' => 'Артикул',
                'source_field' => self::SOURCE_COLUMNS['sku'],
                'source_value' => $this->sourceSkuForItem($source, $supplierCode),
                'database_values' => [$variation->sku],
                'message' => 'Строка файла без осей вариации найдена по артикулу вариации.',
            ]];

            $databaseAxesByName = $variation->attributeValues
                ->groupBy(fn (ShopVariationAttributeValue $value) => $value->attribute?->name ?? '')
                ->map(fn (Collection $values) => $values->pluck('value')->filter()->values()->all())
                ->filter(fn (array $values, string $attribute) => in_array($attribute, ['Цвет', 'Размер'], true) && $values !== []);

            foreach ($databaseAxesByName as $attribute => $values) {
                $comparisons[] = [
                    'status' => 'match',
                    'attribute' => $attribute,
                    'source_field' => null,
                    'source_value' => implode(', ', $values),
                    'source_value_note' => 'из базы',
                    'source_value_is_database_fallback' => true,
                    'database_values' => $values,
                    'message' => 'В файле нет значения оси; для отображения используется значение из базы.',
                ];
            }

            return $comparisons;
        }
        $sourceYear = $this->nullableString($source->source_year)
            ?? $this->nullableString($source->raw_payload['MODELNYY_GOD'] ?? null)
            ?? (string) now()->year;
        $databaseAxes = $variation->attributeValues->groupBy('attribute_id')
            ->map(fn (Collection $values) => $values->pluck('value')->values()->all());
        $databaseYear = $databaseAxes->get($yearAttributeId, []);
        $yearMatches = collect($databaseYear)->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue($sourceYear));
        $comparisons = [[
            'status' => $databaseYear === [] ? 'missing_in_database' : ($yearMatches ? 'match' : 'different'),
            'attribute' => 'Год',
            'source_field' => 'MODELNYY_GOD',
            'source_value' => $sourceYear,
            'database_values' => $databaseYear,
        ]];

        foreach ($databaseAxes as $attributeId => $values) {
            if ((int) $attributeId === (int) $yearAttributeId) {
                continue;
            }
            $attributeName = $variation->attributeValues->firstWhere('attribute_id', $attributeId)?->attribute?->name ?? 'Неизвестная ось';
            $comparisons[] = [
                'status' => 'only_in_database',
                'attribute' => $attributeName,
                'source_field' => null,
                'source_value' => null,
                'database_values' => $values,
                'message' => 'У одиночной вариации базы есть ось, которой нет у товара в файле.',
            ];
        }

        return $comparisons;
    }

    /** @param Collection<int, SupplierCatalogFieldMapping> $mappings @return array<int, array<string, mixed>> */
    private function sourceOnlyVariationComparisons(SupplierCatalogItem $source, Collection $mappings, string $supplierCode, string $status): array
    {
        $axes = $this->sourceAxesForItem($source, $mappings, $supplierCode);
        if ($axes === []) {
            return [[
                'status' => $status,
                'attribute' => 'Вариации',
                'source_value' => 'В файле нет значений вариации',
                'database_values' => [],
            ]];
        }

        return array_map(static fn (array $axis) => [
            'status' => $status,
            'attribute' => $axis['attribute_name'] ?? $axis['source_field'],
            'source_field' => $axis['source_field'] ?? null,
            'source_value' => $axis['value'] ?? null,
            'database_values' => [],
        ], $axes);
    }

    /** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
    private function bikeproductsNameAxes(array $payload, string $supplierCode): array
    {
        $normalized = $this->normalizeSourceVariation($payload, $supplierCode);
        if (! $normalized['is_variation']) {
            return [];
        }

        $attributes = $this->standardVariationAttributeIds();
        return $this->namedVariationAxes($normalized['color'], $normalized['size'], $attributes);
    }

    /** @return array{clean_name: ?string, color: ?string, size: ?string, year: ?string, sku: ?string, is_variation: bool} */
    private function normalizeSourceVariation(array $payload, string $supplierCode, ?string $fallbackSku = null): array
    {
        $name = $this->nullableString($payload[self::SOURCE_COLUMNS['name']] ?? null);
        $normalized = [
            'clean_name' => $this->sourceGroupName($name, $fallbackSku),
            'color' => null,
            'size' => null,
            'year' => null,
            'sku' => $this->nullableString($payload[self::SOURCE_COLUMNS['sku']] ?? null) ?? $this->nullableString($fallbackSku),
            'is_variation' => false,
        ];
        if ($supplierCode !== 'bikeproducts') {
            return $normalized;
        }

        // Год нужен для товаров, которые в файле не имеют цвета/размера,
        // но в нашей базе оформлены единственной технической вариацией.
        $normalized['year'] = $this->nullableString($payload['MODELNYY_GOD'] ?? null) ?? (string) now()->year;

        $contents = $this->trailingParenthesesContents($name);
        if ($contents === null) {
            return $normalized;
        }

        // Последний вложенный блок — SKU. Год (третья часть) намеренно не используем.
        if (preg_match('/\(([^()]*)\)\s*$/u', $contents, $skuMatch)) {
            $normalized['sku'] = $this->nullableString($skuMatch[1]) ?? $normalized['sku'];
        }
        $contents = trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $contents) ?? $contents);
        // Позиции нельзя сжимать: в "(Black, , 2026 ...)" пустой второй
        // элемент означает отсутствие размера, а не то, что год стал размером.
        $parts = array_map('trim', explode(',', $contents));
        if (count($parts) < 2) {
            return $normalized;
        }

        $normalized['color'] = $this->nullableString($parts[0]);
        $normalized['size'] = $this->nullableString($parts[1]);
        if ($normalized['color'] === null || $normalized['size'] === null) {
            return $normalized;
        }
        $normalized['is_variation'] = true;

        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    private function sourceSkuForPayload(array $payload, string $supplierCode, ?string $fallbackSku = null): string
    {
        $normalized = $this->normalizeSourceVariation($payload, $supplierCode, $fallbackSku);

        return trim((string) ($normalized['sku'] ?? $fallbackSku ?? ''));
    }

    private function sourceSkuForItem(SupplierCatalogItem $item, string $supplierCode): string
    {
        return $this->sourceSkuForPayload($item->raw_payload ?? [], $supplierCode, $item->external_sku);
    }

    /** @param array<string, int> $attributes @return array<int, array<string, mixed>> */
    private function namedVariationAxes(?string $color, ?string $size, array $attributes): array
    {
        $values = ['Цвет' => $color, 'Размер' => $size];
        $axes = [];
        foreach ($values as $name => $value) {
            if ($value === null || ! isset($attributes[$name])) {
                continue;
            }
            $axes[] = [
                'attribute_id' => $attributes[$name],
                'attribute_name' => $name,
                'source_field' => 'NAME',
                'source_code' => $value,
                'value' => $value,
                'is_check_enabled' => true,
            ];
        }

        return $axes;
    }

    /** @param Collection<int, SupplierCatalogFieldMapping> $mappings @return array<int, array<string, mixed>> */
    private function sourceAxesForItem(SupplierCatalogItem $item, Collection $mappings, string $supplierCode): array
    {
        if ($supplierCode === 'bikeproducts') {
            $parsed = $this->normalizeSourceVariation($item->raw_payload ?? [], $supplierCode, $item->external_sku);
            if (! $parsed['is_variation']) {
                return [];
            }
            $color = $this->nullableString($item->source_color);
            $size = $this->nullableString($item->source_size);
            if ($color === $parsed['color'] && $size === $parsed['size']) {
                return $this->namedVariationAxes($color, $size, $this->standardVariationAttributeIds());
            }

            // Снимок мог быть создан прежним алгоритмом, который сдвигал
            // значения после пустой позиции. Используем корректный разбор NAME.
            return $this->namedVariationAxes($parsed['color'], $parsed['size'], $this->standardVariationAttributeIds());
        }

        return $this->resolveSourceAxes($item->raw_payload ?? [], $mappings, $supplierCode);
    }

    /** @return array<int, array{attribute_id: int, attribute_name: string, value: string}> */
    private function creationAxesForItem(SupplierCatalogItem $item, string $supplierCode): array
    {
        $axes = $this->sourceAxesForItem($item, $this->getMappings($supplierCode), $supplierCode);
        if ($axes !== [] || $supplierCode !== 'bikeproducts') {
            return $axes;
        }

        $yearAttributeId = $this->standardVariationAttributeIds()['Год'] ?? null;
        if (! $yearAttributeId) {
            return [];
        }

        $year = $this->nullableString($item->source_year)
            ?? $this->nullableString($item->raw_payload['MODELNYY_GOD'] ?? null)
            ?? (string) now()->year;

        return [[
            'attribute_id' => $yearAttributeId,
            'attribute_name' => 'Год',
            'value' => $year,
        ]];
    }

    private function isSourceVariationItem(SupplierCatalogItem $item): bool
    {
        if (! empty($item->raw_payload['NAME'])) {
            return $this->normalizeSourceVariation($item->raw_payload, 'bikeproducts', $item->external_sku)['is_variation'];
        }

        return (bool) $item->is_source_variation;
    }

    /** @return array<string, int> */
    private function standardVariationAttributeIds(): array
    {
        if ($this->variationAttributeIds === []) {
            $this->variationAttributeIds = ShopVariationAttribute::query()
                ->whereIn('name', ['Цвет', 'Размер', 'Год'])
                ->pluck('id', 'name')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        return $this->variationAttributeIds;
    }

    private function trailingParenthesesContents(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || ! str_ends_with($name, ')')) {
            return null;
        }

        $depth = 0;
        for ($index = mb_strlen($name) - 1; $index >= 0; $index--) {
            $character = mb_substr($name, $index, 1);
            if ($character === ')') {
                $depth++;
            } elseif ($character === '(' && --$depth === 0) {
                return trim(mb_substr($name, $index + 1, -1));
            }
        }

        return null;
    }

    private function normalizeSourceGroupKey(?string $name): string
    {
        return $this->normalizeComparisonValue($this->sourceGroupName($name, $name));
    }
    private function sourceGroupName(?string $name, ?string $fallback): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return (string) $fallback;
        }

        // NAME содержит базовое название и финальный блок вариации, например
        // "... (Black, S/M, 2026 (7022121920))". Для группировки используем
        // только базовое название, а не нестабильный технический CML2_LINK.
        if (! str_ends_with($name, ')')) {
            return $name;
        }

        $depth = 0;
        for ($index = mb_strlen($name) - 1; $index >= 0; $index--) {
            $character = mb_substr($name, $index, 1);
            if ($character === ')') {
                $depth++;
            } elseif ($character === '(' && --$depth === 0) {
                $baseName = trim(mb_substr($name, 0, $index));
                return $baseName !== '' ? $baseName : $name;
            }
        }

        return $name;
    }

    /** @param Collection<int, string> $skus @return Collection<string, array{type: string, model: ShopGood|ShopGoodVariation, source_sku?: string, sku_matched_by_rule?: bool}> */
    private function resolveSkuMatches(Collection $skus, string $supplierCode, bool $preferVariations = false): Collection
    {
        $sourceSkus = $skus->filter()->unique()->values();
        if ($sourceSkus->isEmpty()) {
            return collect();
        }

        $supplierNames = $this->supplierNames($supplierCode);
        if ($supplierNames === []) {
            return collect();
        }

        $lookupSkus = $sourceSkus
            ->flatMap(fn ($sku) => $this->skuLookupKeys($sku, $supplierCode))
            ->unique()
            ->values();

        $goods = ShopGood::query()
            ->whereIn('sku', $lookupSkus)
            ->whereIn('supplier', $supplierNames)
            ->get(['id', 'sku', 'name', 'slug', 'supplier']);
        $variations = $this->findVariationsBySku($lookupSkus, $supplierCode);
        $databaseMatches = $variations->mapWithKeys(fn (ShopGoodVariation $variation) => [
            $this->normalizeSku($variation->sku) => ['type' => 'variation', 'model' => $variation],
        ]);

        foreach ($goods as $good) {
            $key = $this->normalizeSku($good->sku);
            if ($preferVariations && $databaseMatches->has($key)) {
                Log::debug('[FIX:supplier-catalog-price-sku] Prefer variation SKU match over parent good', ['sku' => $good->sku, 'good_id' => $good->id]);
                continue;
            }
            $databaseMatches->put($key, ['type' => 'good', 'model' => $good]);
        }

        $matches = collect();
        foreach ($sourceSkus as $sourceSku) {
            foreach ($this->skuLookupKeys($sourceSku, $supplierCode) as $lookupSku) {
                $match = $databaseMatches->get($lookupSku);
                if (! $match) {
                    continue;
                }
                $match['source_sku'] = (string) $sourceSku;
                $match['sku_matched_by_rule'] = $this->skuMatchedByReplacementRule($sourceSku, $match['model']->sku ?? null, $supplierCode);
                $matches->put((string) $sourceSku, $match);
                $matches->put($this->normalizeSku($sourceSku), $match);
                break;
            }
        }

        return $matches;
    }
    /**
     * Resolves image owners by SKU. If a supplier has both a parent product and
     * its variation with the same SKU, images belong to the variation first.
     * The parent remains a fallback only when no valid variation is found.
     *
     * @param Collection<int, string> $skus
     * @return Collection<string, array{type: string, model: ShopGood|ShopGoodVariation, source_sku?: string, sku_matched_by_rule?: bool}>
     */
    private function resolveImageSkuMatches(Collection $skus, string $supplierCode): Collection
    {
        $sourceSkus = $skus->filter()->unique()->values();
        if ($sourceSkus->isEmpty()) {
            return collect();
        }

        $supplierNames = $this->supplierNames($supplierCode);
        if ($supplierNames === []) {
            return collect();
        }

        $lookupSkus = $sourceSkus
            ->flatMap(fn ($sku) => $this->skuLookupKeys($sku, $supplierCode))
            ->unique()
            ->values();

        $goods = ShopGood::query()
            ->whereIn('sku', $lookupSkus)
            ->whereIn('supplier', $supplierNames)
            ->get(['id', 'sku', 'name', 'slug', 'supplier']);
        $matchesByLookupSku = $goods->mapWithKeys(fn (ShopGood $good) => [$this->normalizeSku($good->sku) => ['type' => 'good', 'model' => $good]]);

        foreach ($this->findVariationsBySku($lookupSkus, $supplierCode) as $variation) {
            if ($variation->good !== null) {
                $matchesByLookupSku->put($this->normalizeSku($variation->sku), ['type' => 'variation', 'model' => $variation]);
            }
        }

        $matches = collect();
        foreach ($sourceSkus as $sourceSku) {
            foreach ($this->skuLookupKeys($sourceSku, $supplierCode) as $lookupSku) {
                $match = $matchesByLookupSku->get($lookupSku);
                if (! $match) {
                    continue;
                }
                $match['source_sku'] = (string) $sourceSku;
                $match['sku_matched_by_rule'] = $this->skuMatchedByReplacementRule($sourceSku, $match['model']->sku ?? null, $supplierCode);
                $matches->put((string) $sourceSku, $match);
                $matches->put($this->normalizeSku($sourceSku), $match);
                break;
            }
        }

        return $matches;
    }
    /** @param Collection<int, string> $skus @return Collection<string, ShopGoodVariation> */
    private function findVariationsBySku(Collection $skus, string $supplierCode): Collection
    {
        if ($skus->isEmpty()) {
            return collect();
        }

        $query = ShopGoodVariation::query()
            ->whereIn('sku', $skus->filter()->unique()->values());
        $supplierNames = $this->supplierNames($supplierCode);
        if ($supplierNames !== []) {
            $query->where(function ($query) use ($supplierNames) {
                $query->whereIn('supplier', $supplierNames)
                    ->orWhere(function ($query) use ($supplierNames) {
                        $query->where(fn ($supplier) => $supplier->whereNull('supplier')->orWhere('supplier', ''))
                            ->whereHas('good', fn ($good) => $good->whereIn('supplier', $supplierNames));
                    });
            });
        } else {
            // Неизвестный код не должен случайно анализировать вариации другого поставщика.
            $query->whereRaw('1 = 0');
        }

        return $query
            ->with(['good:id,name,slug', 'attributeValues.attribute'])
            ->get()
            ->keyBy('sku');
    }

    private function assertReadySnapshot(SupplierCatalogSnapshot $snapshot): void
    {
        if ($snapshot->status !== 'ready') {
            throw new \InvalidArgumentException('Снимок поставщика не готов к анализу.');
        }
    }

    private function normalizeSupplierCode(string $supplierCode): string
    {
        $supplierCode = trim(mb_strtolower($supplierCode));
        if ($supplierCode === '') {
            throw new \InvalidArgumentException('Не выбран поставщик.');
        }

        return $supplierCode;
    }

    /** @return array<int, array{from: string, to: string}> */
    private function skuReplacementRules(string $supplierCode): array
    {
        $settings = SupplierCatalogProfile::query()->where('code', $supplierCode)->value('settings') ?? [];
        $rules = is_array($settings) ? ($settings['sku_replace_rules'] ?? []) : [];

        return collect(is_array($rules) ? $rules : [])
            ->filter(fn ($rule) => is_array($rule) && ($rule['enabled'] ?? true) !== false)
            ->map(fn (array $rule) => [
                'from' => $this->normalizeSku($rule['from'] ?? ''),
                'to' => $this->normalizeSku($rule['to'] ?? ''),
            ])
            ->filter(fn (array $rule) => $rule['from'] !== '')
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function skuLookupKeys(mixed $sku, string $supplierCode): array
    {
        $normalized = $this->normalizeSku($sku);
        if ($normalized === '') {
            return [];
        }

        $keys = [$normalized];
        foreach ($this->skuReplacementRules($supplierCode) as $rule) {
            $candidate = $this->normalizeSku(str_replace($rule['from'], $rule['to'], $normalized));
            if ($candidate !== '') {
                $keys[] = $candidate;
            }
        }

        return array_values(array_unique($keys));
    }

    private function skuMatchedByReplacementRule(mixed $sourceSku, mixed $databaseSku, string $supplierCode): bool
    {
        $source = $this->normalizeSku($sourceSku);
        $database = $this->normalizeSku($databaseSku);

        return $source !== ''
            && $database !== ''
            && $source !== $database
            && in_array($database, $this->skuLookupKeys($source, $supplierCode), true);
    }
    private function normalizeSku(mixed $sku): string
    {
        $sku = trim((string) $sku);
        $sku = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $sku);
        $sku = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $sku) ?? $sku;

        return mb_strtoupper(trim($sku));
    }

    /** @return array<int, string> */
    private function supplierNames(string $supplierCode): array
    {
        return SupplierCatalogProfile::query()
            ->active()
            ->where('code', $supplierCode)
            ->value('supplier_names') ?? [];
    }

    private function normalizeCellValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_array($value)) {
            $value = implode('', array_map(static fn ($part) => method_exists($part, 'getText') ? $part->getText() : (string) $part, $value));
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }
        if (trim($value) === '-') {
            return 0.0;
        }
        if (! is_numeric(str_replace(',', '.', $value))) {
            return null;
        }

        return (float) str_replace(',', '.', $value);
    }

    /** @return array<int, string> */
    private function normalizeStringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($item) => $this->nullableString($item))
            ->filter(fn (?string $item) => $item !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function nullableInteger(mixed $value): ?int
    {
        $value = $this->nullableDecimal($value);

        return $value === null ? null : (int) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        return in_array(mb_strtolower($value), ['y', 'yes', '1', 'true', 'да'], true);
    }

    /** @param array<string, string> $payload @return array<int, string> */
    private function extractImageUrls(array $payload, ?string $baseUrl = null): array
    {
        $urls = [];
        foreach ([self::SOURCE_COLUMNS['preview_image'], self::SOURCE_COLUMNS['detail_image']] as $field) {
            if (! empty($payload[$field])) {
                $urls[] = $payload[$field];
            }
        }

        if (! empty($payload[self::SOURCE_COLUMNS['more_images']])) {
            $urls = [...$urls, ...explode(',', $payload[self::SOURCE_COLUMNS['more_images']])];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($url) => $this->absoluteSourceImageUrl((string) $url, $baseUrl),
            $urls,
        ))));
    }

    /** @return array<int, string> */
    private function imageSourceFields(string $supplierCode): array
    {
        return SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $supplierCode)
            ->where('scope', 'image')
            ->where('is_check_enabled', true)
            ->pluck('source_field')
            ->filter(fn ($field) => $this->nullableString($field) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $sourceFields
     * @return array<int, array{url: string, source_fields: array<int, string>}>
     */
    private function imageSourcesForItem(SupplierCatalogItem $item, array $sourceFields, ?string $baseUrl): array
    {
        $byUrl = [];
        $payload = $item->raw_payload ?? [];
        foreach ($sourceFields as $sourceField) {
            $value = $this->nullableString($payload[$sourceField] ?? null);
            if ($value === null) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/u', $value) ?: [] as $rawUrl) {
                $url = $this->absoluteSourceImageUrl($rawUrl, $baseUrl);
                if ($url === null) {
                    continue;
                }
                $byUrl[$url] ??= ['url' => $url, 'source_fields' => []];
                $byUrl[$url]['source_fields'][] = $sourceField;
                $byUrl[$url]['source_fields'] = array_values(array_unique($byUrl[$url]['source_fields']));
            }
        }

        return array_values($byUrl);
    }

    private function supplierImageBaseUrl(string $supplierCode): ?string
    {
        $profile = SupplierCatalogProfile::query()->where('code', $supplierCode)->first(['settings']);
        $configuredUrl = $this->nullableString($profile?->settings['image_base_url'] ?? null);

        // У Байкпродакс Excel содержит относительные /upload/... пути. Этот
        // fallback сохраняет корректные URL и для уже созданных снимков.
        return $configuredUrl ?? ($supplierCode === 'bikeproducts' ? 'https://www.bikeproducts.ru' : null);
    }

    private function absoluteSourceImageUrl(string $url, ?string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $baseUrl ? rtrim($baseUrl, '/').'/'.ltrim($url, '/') : $url;
    }

    private function sourceImageFileName(string $url): string
    {
        return mb_strtolower(rawurldecode((string) basename((string) parse_url($url, PHP_URL_PATH))));
    }

    private function sourceUrlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function inspectSupplierImageUrl(SupplierCatalogImageAudit $record): void
    {
        try {
            $response = Http::timeout(35)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'SkateAndSnow supplier image audit/1.0'])
                ->get($record->source_url);
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $declaredSize = (int) ($response->header('Content-Length') ?? 0);
            if ($declaredSize > 20 * 1024 * 1024) {
                $record->update([
                    'status' => 'unsupported',
                    'error_message' => 'Размер файла превышает 20 МБ.',
                    'checked_at' => now(),
                ]);
                return;
            }

            $body = $response->body();
            if ($body === '') {
                throw new \RuntimeException('Пустой ответ сервера.');
            }
            if (strlen($body) > 20 * 1024 * 1024) {
                $record->update([
                    'status' => 'unsupported',
                    'error_message' => 'Размер файла превышает 20 МБ.',
                    'checked_at' => now(),
                ]);
                return;
            }

            $image = @getimagesizefromstring($body);
            $mimeType = $image['mime'] ?? $response->header('Content-Type');
            if (! is_array($image) && ! str_starts_with((string) $mimeType, 'image/svg')) {
                $record->update([
                    'status' => 'unsupported',
                    'mime_type' => $mimeType ?: null,
                    'error_message' => 'Ответ не является поддерживаемым изображением.',
                    'checked_at' => now(),
                ]);
                return;
            }

            $record->update([
                'status' => 'available',
                'content_hash' => hash('sha256', $body),
                'perceptual_hash' => $this->perceptualImageHash($body),
                ...$this->normalizedPerceptualImageHashes($body),
                'mime_type' => $mimeType ?: null,
                'width' => is_array($image) ? (int) ($image[0] ?? 0) : null,
                'height' => is_array($image) ? (int) ($image[1] ?? 0) : null,
                'byte_size' => strlen($body),
                'error_message' => null,
                'checked_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $record->update([
                'status' => 'broken',
                'error_message' => Str::limit($exception->getMessage(), 1000),
                'checked_at' => now(),
            ]);
        }
    }

    private function perceptualImageHash(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        try {
            return $this->perceptualImageHashFromResource($source);
        } finally {
            imagedestroy($source);
        }
    }

    private function perceptualImageHashFromResource(\GdImage $source): ?string
    {
        $scaled = imagecreatetruecolor(9, 8);
        if ($scaled === false) {
            return null;
        }
        try {
            imagecopyresampled($scaled, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                $previous = null;
                for ($x = 0; $x < 9; $x++) {
                    $rgb = imagecolorat($scaled, $x, $y);
                    $gray = ((($rgb >> 16) & 0xff) * 299) + ((($rgb >> 8) & 0xff) * 587) + (($rgb & 0xff) * 114);
                    if ($previous !== null) {
                        $bits .= $previous > $gray ? '1' : '0';
                    }
                    $previous = $gray;
                }
            }
            $hash = '';
            for ($index = 0; $index < strlen($bits); $index += 4) {
                $hash .= dechex(bindec(substr($bits, $index, 4)));
            }

            return $hash;
        } finally {
            imagedestroy($scaled);
        }
    }

    /** @return array{normalized_crop_hash: ?string, normalized_white_hash: ?string} */
    private function normalizedPerceptualImageHashes(string $binary): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['normalized_crop_hash' => null, 'normalized_white_hash' => null];
        }
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return ['normalized_crop_hash' => null, 'normalized_white_hash' => null];
        }

        try {
            $target = $this->imageAuditTargetSize();
            $crop = $this->imageAuditCrop($source, $target['width'], $target['height']);
            $white = $this->imageAuditFitWhite($source, $target['width'], $target['height']);

            return [
                'normalized_crop_hash' => $crop ? $this->perceptualImageHashFromResource($crop) : null,
                'normalized_white_hash' => $white ? $this->perceptualImageHashFromResource($white) : null,
            ];
        } finally {
            if (isset($crop) && $crop instanceof \GdImage) imagedestroy($crop);
            if (isset($white) && $white instanceof \GdImage) imagedestroy($white);
            imagedestroy($source);
        }
    }

    /** @return array{width: int, height: int} */
    private function imageAuditTargetSize(): array
    {
        if ($this->imageAuditTargetSize !== null) {
            return $this->imageAuditTargetSize;
        }
        $settings = DB::table('settings')->whereIn('key', ['shop_good_width', 'shop_good_height'])->pluck('value', 'key');

        return $this->imageAuditTargetSize = [
            'width' => max(1, (int) ($settings['shop_good_width'] ?? 500)),
            'height' => max(1, (int) ($settings['shop_good_height'] ?? 500)),
        ];
    }

    private function isImageAtAuditTargetAspect(ShopGoodImage $image): bool
    {
        if (! $image->image_width || ! $image->image_height) {
            return true;
        }
        $target = $this->imageAuditTargetSize();

        return abs(($image->image_width / $image->image_height) - ($target['width'] / $target['height'])) <= 0.02;
    }

    /** @param array<int, ShopGoodImage> $availableImages */
    private function matchingDatabaseImageIndex(array $availableImages, ?SupplierCatalogImageAudit $sourceAudit, string $sourceUrl): int|false
    {
        if ($sourceAudit?->content_hash) {
            $index = collect($availableImages)->search(fn (ShopGoodImage $image) =>
                $image->source_content_hash === $sourceAudit->content_hash || $image->content_hash === $sourceAudit->content_hash);
            if ($index !== false) return $index;
        }
        if ($sourceAudit) {
            $index = collect($availableImages)->search(function (ShopGoodImage $image) use ($sourceAudit): bool {
                $distance = $this->perceptualHashDistance($sourceAudit->perceptual_hash, $image->perceptual_hash);
                if ($distance !== null && $distance <= 6) return true;
                if (! $this->isImageAtAuditTargetAspect($image)) return false;
                foreach ([$sourceAudit->normalized_crop_hash, $sourceAudit->normalized_white_hash] as $hash) {
                    $normalizedDistance = $this->perceptualHashDistance($hash, $image->perceptual_hash);
                    if ($normalizedDistance !== null && $normalizedDistance <= 6) return true;
                }

                return false;
            });
            if ($index !== false) return $index;
        }

        return collect($availableImages)->search(fn (ShopGoodImage $image) => $this->sourceImageMatchesDatabasePath($sourceUrl, $image->file_path));
    }

    private function imageAuditCrop(\GdImage $source, int $targetWidth, int $targetHeight): \GdImage|false
    {
        $scale = max($targetWidth / imagesx($source), $targetHeight / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) return false;
        imagecopyresampled($canvas, $source, (int) round(($targetWidth - $width) / 2), (int) round(($targetHeight - $height) / 2), 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    private function imageAuditFitWhite(\GdImage $source, int $targetWidth, int $targetHeight): \GdImage|false
    {
        $scale = min($targetWidth / imagesx($source), $targetHeight / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) return false;
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $source, (int) round(($targetWidth - $width) / 2), (int) round(($targetHeight - $height) / 2), 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    private function perceptualHashDistance(?string $left, ?string $right): ?int
    {
        if (! $left || ! $right || strlen($left) !== 16 || strlen($right) !== 16) {
            return null;
        }

        $distance = 0;
        for ($index = 0; $index < 16; $index++) {
            $value = hexdec($left[$index]) ^ hexdec($right[$index]);
            while ($value > 0) {
                $distance += $value & 1;
                $value >>= 1;
            }
        }

        return $distance;
    }

    /** @return array{total: int, hashed: int, unavailable: int, pending: int} */
    private function databaseImageHashCoverage(SupplierCatalogSnapshot $snapshot): array
    {
        $query = $this->databaseImagesForSnapshot($snapshot);
        if ($query === null) {
            return ['total' => 0, 'hashed' => 0, 'unavailable' => 0, 'pending' => 0];
        }

        $total = (int) (clone $query)->count();
        $hashed = (int) (clone $query)->whereNotNull('content_hash')->count();
        $unavailable = (int) (clone $query)->whereNull('content_hash')->whereNotNull('content_checked_at')->count();

        return [
            'total' => $total,
            'hashed' => $hashed,
            'unavailable' => $unavailable,
            'pending' => max(0, $total - $hashed - $unavailable),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ShopGoodImage>|null */
    private function databaseImagesForSnapshot(SupplierCatalogSnapshot $snapshot)
    {
        $version = $snapshot->updated_at?->format('Uu') ?? '0';
        $owners = Cache::store('file')->remember(
            'supplier-image-audit-owners:'.$snapshot->id.':'.$version,
            now()->addHour(),
            function () use ($snapshot): array {
                $items = $snapshot->items()->get(['external_sku']);
                $matches = $this->resolveImageSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
                return [
                    'variation_ids' => $matches->where('type', 'variation')->pluck('model.id')->filter()->unique()->values()->all(),
                    'good_ids' => $matches->where('type', 'good')->pluck('model.id')->filter()->unique()->values()->all(),
                ];
            },
        );
        $variationIds = collect($owners['variation_ids'] ?? []);
        $goodIds = collect($owners['good_ids'] ?? []);
        if ($variationIds->isEmpty() && $goodIds->isEmpty()) {
            return null;
        }

        return ShopGoodImage::query()->where(function ($query) use ($variationIds, $goodIds) {
            if ($variationIds->isNotEmpty()) {
                $query->whereIn('variation_id', $variationIds);
            }
            if ($goodIds->isNotEmpty()) {
                $query->orWhere(fn ($nested) => $nested->whereIn('good_id', $goodIds)->whereNull('variation_id'));
            }
        });
    }

    /** @return array{pending: int} */
    private function auditDatabaseImagesForSnapshot(SupplierCatalogSnapshot $snapshot): array
    {
        $query = $this->databaseImagesForSnapshot($snapshot);
        if ($query === null) {
            return ['pending' => 0];
        }

        // This runs in the same background queue as supplier URLs. Keep each
        // pass short, then enqueue the next one so the UI remains responsive.
        $images = $query->whereNull('content_hash')->whereNull('content_checked_at')
            ->orderBy('id')->limit(25)->get();
        foreach ($images as $image) {
            $binary = $this->databaseImageBinary((string) $image->file_path);
            if ($binary === null) {
                $image->update(['content_checked_at' => now()]);
                continue;
            }
            $dimensions = @getimagesizefromstring($binary);
            $image->update([
                'content_hash' => hash('sha256', $binary),
                'perceptual_hash' => $this->perceptualImageHash($binary),
                'image_width' => is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : null,
                'image_height' => is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : null,
                'content_checked_at' => now(),
            ]);
        }

        return ['pending' => $this->databaseImageHashCoverage($snapshot)['pending']];
    }

    private function databaseImageBinary(string $filePath): ?string
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return null;
        }
        if (! str_starts_with($filePath, 'http')) {
            try {
                $path = frontend_public_path(ltrim($filePath, '/'));
                if (is_file($path) && filesize($path) <= 20 * 1024 * 1024) {
                    $binary = @file_get_contents($path);
                    if ($binary !== false && $binary !== '') {
                        return $binary;
                    }
                }
            } catch (\Throwable) {
                // The public URL below is a safe fallback when API and shop
                // frontend are deployed on separate hosts.
            }
            $filePath = 'https://skateandsnow.ru/'.ltrim($filePath, '/');
        }
        try {
            $response = Http::timeout(20)->connectTimeout(8)->get($filePath);
            $binary = $response->successful() ? $response->body() : '';
            return $binary !== '' && strlen($binary) <= 20 * 1024 * 1024 ? $binary : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function updateImageAuditSummary(SupplierCatalogSnapshot $snapshot, string $status): void
    {
        $summary = $snapshot->summary ?? [];
        $summary['image_content_audit'] = [
            'status' => $status,
            'completed_at' => $status === 'completed' ? now()->toIso8601String() : null,
        ];
        $snapshot->update(['summary' => $summary]);
    }

    private function sourceImageMatchesDatabasePath(string $sourceUrl, string $databasePath): bool
    {
        $sourceName = $this->sourceImageFileName($sourceUrl);
        $databaseName = $this->sourceImageFileName($databasePath);
        if ($sourceName !== '' && $sourceName === $databaseName) {
            return true;
        }

        $extension = strtolower(pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        $storedName = hash('sha256', $sourceUrl).'.'.$extension;
        return mb_strtolower($storedName) === $databaseName;
    }

    /** @param array<int, string> $sourceFields */
    private function attachSourceImages(ShopGood $good, ShopGoodVariation $variation, SupplierCatalogItem $item, ?string $baseUrl, array $sourceFields): void
    {
        $urls = collect($this->imageSourcesForItem($item, $sourceFields, $baseUrl))
            ->pluck('url')
            ->values();
        if ($urls->isEmpty()) {
            return;
        }

        $query = ShopGoodImage::query()->where('variation_id', $variation->id);
        $existingPaths = $query->pluck('file_path')->all();
        $nextSortOrder = (int) $query->max('sort_order') + 1;
        foreach ($urls as $url) {
            if (in_array($url, $existingPaths, true)) {
                continue;
            }
            ShopGoodImage::create([
                'good_id' => null,
                'variation_id' => $variation->id,
                'file_path' => $url,
                'alt_text' => $item->clean_name ?: $item->name,
                'is_main' => $existingPaths === [] && $nextSortOrder === 1,
                'sort_order' => $nextSortOrder++,
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $stats */
    private function addFieldStat(array &$stats, string $field, string $value): void
    {
        $stats[$field] ??= ['nonempty_count' => 0, 'samples' => [], 'values' => [], 'distinct_capped' => false];
        $stats[$field]['nonempty_count']++;
        if (count($stats[$field]['samples']) < 3) {
            $stats[$field]['samples'][] = Str::limit(preg_replace('/\s+/', ' ', $value), 120);
        }
        if (count($stats[$field]['values']) < 1000) {
            $stats[$field]['values'][$value] = true;
        } else {
            $stats[$field]['distinct_capped'] = true;
        }
    }

    /** @param array<string, array<string, mixed>> $stats */
    private function finishFieldStats(array $stats): array
    {
        $result = [];
        foreach ($stats as $field => $stat) {
            $result[$field] = [
                'nonempty_count' => $stat['nonempty_count'],
                'distinct_count' => count($stat['values']),
                'distinct_capped' => $stat['distinct_capped'],
                'samples' => $stat['samples'],
            ];
        }
        ksort($result);

        return $result;
    }

    private function normalizeComparisonValue(string $value): string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        return strtr($value, ['чёрный' => 'черный', 'black' => 'черный', 'white' => 'белый', 'red' => 'красный']);
    }

    private function hasPositiveStockValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return false;
        }

        if (! is_numeric(str_replace(',', '.', $normalized))) {
            return true;
        }

        return (float) str_replace(',', '.', $normalized) > 0;
    }

    /** @param array<string, int> $stats */
    private function sortStats(array $stats): array
    {
        arsort($stats);

        return collect($stats)->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count])->values()->all();
    }
}
