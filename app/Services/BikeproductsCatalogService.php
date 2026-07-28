<?php

namespace App\Services;

use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Models\ShopVariationAttribute;
use App\Models\SupplierCatalogFieldMapping;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierCatalogSnapshot;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BikeproductsCatalogService
{
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

        // Один актуальный временный разбор на профиль. Файл удаляется в фоне после обработки.
        $previousTemporaryPaths = SupplierCatalogSnapshot::query()
            ->where('supplier_code', $supplierCode)
            ->pluck('storage_path')
            ->filter()
            ->all();
        SupplierCatalogSnapshot::query()->where('supplier_code', $supplierCode)->delete();
        foreach ($previousTemporaryPaths as $previousTemporaryPath) {
            Storage::disk('local')->delete($previousTemporaryPath);
        }

        $temporaryPath = 'supplier-catalog-tmp/'.$supplierCode.'/'.Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('local')->put($temporaryPath, file_get_contents($file->getRealPath()));

        $snapshot = SupplierCatalogSnapshot::create([
            'supplier_code' => $supplierCode,
            'source_type' => 'xlsx',
            'source_name' => $file->getClientOriginalName(),
            'storage_path' => $temporaryPath,
            'checksum' => hash_file('sha256', $file->getRealPath()),
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
                throw new \RuntimeException('Временный файл разбора не найден. Загрузите Excel заново.');
            }
            $snapshot->update(['status' => 'processing', 'progress' => 1, 'stage' => 'Чтение листа Excel']);
            $itemsCount = 0;
            $result = $this->parseExcel($path, $snapshot->supplier_code, function (int $processedRows, int $totalRows) use ($snapshot): void {
                $snapshot->update([
                    'progress' => $totalRows > 0
                        ? min(92, max(2, (int) floor($processedRows * 92 / $totalRows)))
                        : 5,
                    'stage' => 'Разбор строк Excel',
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
                'summary' => $result['summary'],
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

    /** @return array{summary: array<string, mixed>} */
    private function parseExcel(string $path, string $supplierCode, ?callable $onProgress = null, ?callable $onItems = null): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('Для потокового разбора используйте файл .xlsx. Сохраните старый .xls как .xlsx и загрузите снова.');
        }

        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $mappings = $this->getMappings($supplierCode);
        $sheetName = null;
        $headerKeys = [];
        $rowsTotal = 0;
        $fieldStats = [];
        $groupKeys = [];
        $sourceImageUrls = [];
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
                            $header = trim((string) $header);
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

                $imageUrls = $this->extractImageUrls($payload);
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
                'brand' => $this->nullableString($payload[self::SOURCE_COLUMNS['brand']] ?? null),
                'section' => $this->nullableString($payload[self::SOURCE_COLUMNS['section']] ?? null),
                'sub_section' => $this->nullableString($payload[self::SOURCE_COLUMNS['sub_section']] ?? null),
                'price' => $this->nullableDecimal($payload[self::SOURCE_COLUMNS['price']] ?? null),
                'opt_price' => $this->nullableDecimal($payload[self::SOURCE_COLUMNS['opt_price']] ?? null),
                'quantity' => $this->nullableInteger($payload[self::SOURCE_COLUMNS['quantity']] ?? null),
                'in_stock' => $this->nullableBool($payload[self::SOURCE_COLUMNS['in_stock']] ?? null),
                'source_axes' => json_encode($this->resolveSourceAxes($payload, $mappings), JSON_UNESCAPED_UNICODE),
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
            throw new \RuntimeException('Колонка CML2_ARTICLE не найдена в выбранном листе.');
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
                'rows_total' => $rowsTotal,
                'items_with_sku' => $itemsWithSku,
                'rows_without_sku' => $rowsWithoutSku,
                'rows_without_name' => $rowsWithoutName,
                'groups_count' => count($groupKeys),
                'items_with_group' => $itemsWithGroup,
                'items_with_images' => $itemsWithImages,
                'source_duplicate_image_urls' => count(array_filter($sourceImageUrls, static fn (int $count) => $count > 1)),
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

    private function ensureDefaultMappings(string $supplierCode): void
    {
        $attributes = ShopVariationAttribute::query()->pluck('id', 'name');
        $defaults = [
            ['SKU_TSVET', 'TSVET_TOVARA', 'Цвет'],
            ['SKU_RAZMER_ODEZHDY', null, 'Размер'],
            ['SKU_RAZMER_OBUVI', null, 'Размер'],
            ['SKU_RAZMER_GOLOVY', null, 'Размер'],
            ['SKU_GOD', null, 'Год'],
        ];

        foreach ($defaults as [$sourceField, $displayField, $attributeName]) {
            SupplierCatalogFieldMapping::firstOrCreate(
                ['supplier_code' => $supplierCode, 'source_field' => $sourceField, 'scope' => 'variation'],
                [
                    'variation_attribute_id' => $attributes[$attributeName] ?? null,
                    'display_field' => $displayField,
                    'is_check_enabled' => true,
                    'is_update_enabled' => false,
                ]
            );
        }
    }

    /** @param array<string, string> $payload @param Collection<int, SupplierCatalogFieldMapping> $mappings */
    private function resolveSourceAxes(array $payload, Collection $mappings): array
    {
        $axes = [];
        foreach ($mappings as $mapping) {
            if (! $mapping->variation_attribute_id) {
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
        $items = $snapshot->items()->get(['external_sku', 'external_group_key', 'image_urls', 'source_axes']);
        $variations = $this->findVariationsBySku($items->pluck('external_sku'), $snapshot->supplier_code);
        $sourceAxes = [];
        $sourceUrls = [];

        foreach ($items as $item) {
            foreach ($item->source_axes ?? [] as $axis) {
                $name = $axis['attribute_name'] ?: $axis['source_field'];
                $sourceAxes[$name] = ($sourceAxes[$name] ?? 0) + 1;
            }
            foreach ($item->image_urls ?? [] as $url) {
                $sourceUrls[$url] = ($sourceUrls[$url] ?? 0) + 1;
            }
        }

        $databaseAxes = [];
        foreach ($variations as $variation) {
            foreach ($variation->attributeValues as $value) {
                $name = $value->attribute?->name ?? 'Неизвестная ось';
                $databaseAxes[$name] = ($databaseAxes[$name] ?? 0) + 1;
            }
        }

        return [
            'snapshot' => $snapshot,
            'stats' => [
                'source_items' => $items->count(),
                'source_groups' => $items->whereNotNull('external_group_key')->pluck('external_group_key')->unique()->count(),
                'source_items_without_group' => $items->whereNull('external_group_key')->count(),
                'source_items_with_images' => $items->filter(fn ($item) => ! empty($item->image_urls))->count(),
                'source_duplicate_image_urls' => count(array_filter($sourceUrls, static fn (int $count) => $count > 1)),
                'database_matching_variations' => $variations->count(),
                'database_unmatched_source_skus' => $items->count() - $variations->count(),
                'database_goods_with_variations' => $variations->pluck('good_id')->unique()->count(),
            ],
            'source_axes' => $this->sortStats($sourceAxes),
            'database_axes' => $this->sortStats($databaseAxes),
        ];
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function variationAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50): array
    {
        $this->assertReadySnapshot($snapshot);
        $paginator = $snapshot->items()->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $variations = $this->findVariationsBySku($paginator->getCollection()->pluck('external_sku'), $snapshot->supplier_code);
        $mappings = $this->getMappings($snapshot->supplier_code);
        $rows = [];

        foreach ($paginator->getCollection() as $item) {
            $variation = $variations->get($item->external_sku);
            $sourceAxes = collect($this->resolveSourceAxes($item->raw_payload ?? [], $mappings))
                ->filter(static fn (array $axis) => ! empty($axis['is_check_enabled']))
                ->values();
            $databaseAxes = $variation
                ? $variation->attributeValues->groupBy('attribute_id')->map(fn (Collection $values) => $values->pluck('value')->values()->all())
                : collect();
            $differences = [];

            if (! $variation) {
                $differences[] = ['status' => 'source_sku_not_found', 'attribute' => null, 'source_value' => null, 'database_values' => []];
            } else {
                $sourceAttributeIds = [];
                foreach ($sourceAxes as $axis) {
                    $attributeId = (int) $axis['attribute_id'];
                    $sourceAttributeIds[] = $attributeId;
                    $databaseValues = $databaseAxes->get($attributeId, []);
                    $matches = collect($databaseValues)->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue((string) $axis['value']));
                    $differences[] = [
                        'status' => empty($databaseValues) ? 'missing_in_database' : ($matches ? 'match' : 'different'),
                        'attribute' => $axis['attribute_name'],
                        'source_field' => $axis['source_field'],
                        'source_value' => $axis['value'],
                        'database_values' => $databaseValues,
                    ];
                }

                foreach ($databaseAxes as $attributeId => $values) {
                    if (! in_array((int) $attributeId, $sourceAttributeIds, true)) {
                        continue;
                    }
                }
            }

            $rows[] = [
                'item_id' => $item->id,
                'external_sku' => $item->external_sku,
                'external_group_key' => $item->external_group_key,
                'source_name' => $item->name,
                'database_variation_id' => $variation?->id,
                'database_good_id' => $variation?->good_id,
                'differences' => $differences,
                'status' => collect($differences)->contains(fn (array $diff) => $diff['status'] !== 'match') ? 'attention' : 'match',
            ];
        }

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function imageAudit(SupplierCatalogSnapshot $snapshot): array
    {
        $this->assertReadySnapshot($snapshot);
        $items = $snapshot->items()->get(['external_sku', 'image_urls']);
        $sourceUrlGroups = [];
        foreach ($items as $item) {
            foreach ($item->image_urls ?? [] as $url) {
                $sourceUrlGroups[$url][] = $item->external_sku;
            }
        }

        $variations = $this->findVariationsBySku($items->pluck('external_sku'), $snapshot->supplier_code);
        $variationIds = $variations->pluck('id');
        $images = $variationIds->isEmpty()
            ? collect()
            : ShopGoodImage::query()->whereIn('variation_id', $variationIds)->get(['id', 'variation_id', 'file_path', 'is_main']);
        $pathGroups = $images->groupBy('file_path')->filter(fn (Collection $group) => $group->count() > 1);
        $imageCountByVariation = $images->groupBy('variation_id')->map(fn (Collection $group) => $group->count());
        $coverageMismatches = [];
        foreach ($items as $item) {
            $variation = $variations->get($item->external_sku);
            if (! $variation) {
                continue;
            }
            $expectedCount = count($item->image_urls ?? []);
            $actualCount = (int) ($imageCountByVariation->get($variation->id) ?? 0);
            if ($expectedCount !== $actualCount) {
                $coverageMismatches[] = [
                    'external_sku' => $item->external_sku,
                    'variation_id' => $variation->id,
                    'good_id' => $variation->good_id,
                    'expected_count' => $expectedCount,
                    'database_count' => $actualCount,
                ];
            }
        }
        $hashGroups = [];
        $scannedPaths = 0;
        $hashLimit = 1000;

        foreach ($images->pluck('file_path')->filter()->unique()->take($hashLimit) as $path) {
            $physicalPath = public_path(ltrim($path, '/'));
            if (! is_file($physicalPath)) {
                continue;
            }
            $scannedPaths++;
            $hashGroups[hash_file('sha256', $physicalPath)][] = $path;
        }

        return [
            'stats' => [
                'source_images' => array_sum(array_map('count', $sourceUrlGroups)),
                'source_duplicate_url_groups' => count(array_filter($sourceUrlGroups, static fn (array $skus) => count($skus) > 1)),
                'database_variation_images' => $images->count(),
                'database_duplicate_path_groups' => $pathGroups->count(),
                'database_duplicate_content_groups' => count(array_filter($hashGroups, static fn (array $paths) => count($paths) > 1)),
                'image_count_mismatches' => count($coverageMismatches),
                'hashed_files' => $scannedPaths,
                'hash_scan_limited' => $images->pluck('file_path')->filter()->unique()->count() > $hashLimit,
            ],
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
            'coverage_mismatches' => array_slice($coverageMismatches, 0, 50),
        ];
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function propertyAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50): array
    {
        $this->assertReadySnapshot($snapshot);
        $paginator = $snapshot->items()->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $variations = $this->findVariationsBySku($paginator->getCollection()->pluck('external_sku'), $snapshot->supplier_code);
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'product')
            ->whereNotNull('property_id')
            ->where('is_check_enabled', true)
            ->with('property:id,name')
            ->get();
        $goodIds = $variations->pluck('good_id')->unique()->values();
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

        foreach ($paginator->getCollection() as $item) {
            $variation = $variations->get($item->external_sku);
            $differences = [];
            if (! $variation) {
                $differences[] = ['status' => 'source_sku_not_found', 'property' => null, 'source_value' => null, 'database_values' => []];
            } else {
                foreach ($mappings as $mapping) {
                    $rawValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
                    if ($rawValue === null) {
                        continue;
                    }
                    $sourceValue = $mapping->display_field
                        ? ($this->nullableString($item->raw_payload[$mapping->display_field] ?? null) ?? $rawValue)
                        : $rawValue;
                    $databaseValues = $properties->get($variation->good_id.':'.$mapping->property_id, collect())
                        ->pluck('value')
                        ->filter()
                        ->values()
                        ->all();
                    $matches = collect($databaseValues)->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue($sourceValue));
                    $differences[] = [
                        'status' => empty($databaseValues) ? 'missing_in_database' : ($matches ? 'match' : 'different'),
                        'property' => $mapping->property?->name,
                        'source_field' => $mapping->source_field,
                        'source_value' => $sourceValue,
                        'database_values' => $databaseValues,
                    ];
                }
            }

            $rows[] = [
                'item_id' => $item->id,
                'external_sku' => $item->external_sku,
                'source_name' => $item->name,
                'database_good_id' => $variation?->good_id,
                'differences' => $differences,
                'status' => collect($differences)->contains(fn (array $diff) => $diff['status'] !== 'match') ? 'attention' : 'match',
            ];
        }

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
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
            $query->whereIn('supplier', $supplierNames);
        } else {
            // Неизвестный код не должен случайно анализировать вариации другого поставщика.
            $query->whereRaw('1 = 0');
        }

        return $query
            ->with(['attributeValues.attribute'])
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
        if ($value === null || ! is_numeric(str_replace(',', '.', $value))) {
            return null;
        }

        return (float) str_replace(',', '.', $value);
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
    private function extractImageUrls(array $payload): array
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

        return array_values(array_unique(array_filter(array_map(static fn ($url) => trim((string) $url), $urls))));
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

    /** @param array<string, int> $stats */
    private function sortStats(array $stats): array
    {
        arsort($stats);

        return collect($stats)->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count])->values()->all();
    }
}
