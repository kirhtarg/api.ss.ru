<?php

namespace App\Services;

use App\Models\ShopGoodImage;
use App\Models\ShopGood;
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
    /** @var array<string, int> */
    private array $variationAttributeIds = [];

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

    public function clearSnapshot(SupplierCatalogSnapshot $snapshot): void
    {
        if ($snapshot->storage_path) {
            Storage::disk('local')->delete($snapshot->storage_path);
        }

        $snapshot->delete();
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
                $normalizedVariation = $this->normalizeSourceVariation($payload, $supplierCode, $sku);
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
    public function variationAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?string $search = null, array $filters = []): array
    {
        $this->assertReadySnapshot($snapshot);
        $this->ensureDefaultMappings($snapshot->supplier_code);
        $mappings = $this->getMappings($snapshot->supplier_code);
        $sourceItems = $this->sourceVariationItems($snapshot)->keyBy('external_sku');
        $sourceSingleItems = $this->sourceSingleItems($snapshot)->keyBy('external_sku');
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
        $databaseSkus = $databaseVariations->pluck('sku')->filter()->values();
        $sourceDirectGoods = ShopGood::query()
            ->whereIn('supplier', $supplierNames)
            ->whereIn('sku', $sourceItems->keys())
            ->get(['id', 'sku'])
            ->keyBy('sku');
        $rows = $databaseVariations->map(function (ShopGoodVariation $variation) use ($sourceItems, $sourceSingleItems, $variationCountByGood, $mappings, $snapshot) {
            $source = $sourceItems->get($variation->sku);
            $isSingleProductSource = false;
            if ($source === null && $variationCountByGood->get($variation->good_id) === 1) {
                $source = $sourceSingleItems->get($variation->good?->sku) ?? $sourceSingleItems->get($variation->sku);
                $isSingleProductSource = $source !== null;
            }
            $comparisons = $isSingleProductSource
                ? $this->singleProductVariationComparisons($variation, $source, $snapshot->supplier_code)
                : $this->variationComparisons($variation, $source, $mappings, $snapshot->supplier_code);
            $status = $source ? (collect($comparisons)->contains(fn (array $item) => ! in_array($item['status'], ['match', 'not_checked'], true)) ? 'attention' : 'match') : 'delete_candidate_variation';

            return [
                'item_id' => $source?->id ?? 'db-'.$variation->id,
                'external_sku' => $variation->sku,
                'source_group_name' => $source ? ($source->clean_name ?: $this->sourceGroupName($source->name, $source->external_sku)) : null,
                'source_name' => $source?->name,
                'source_kind' => $isSingleProductSource ? 'single_product' : ($source ? 'variation' : null),
                'database_match_type' => 'variation',
                'database_variation_id' => $variation->id,
                'database_good_id' => $variation->good_id,
                'database_name' => $variation->good?->name,
                'database_slug' => $variation->good?->slug,
                'comparisons' => $comparisons,
                'differences' => array_values(array_filter($comparisons, fn (array $item) => ! in_array($item['status'], ['match', 'not_checked'], true))),
                'status' => $status,
                'source_exists' => $source !== null,
            ];
        })->values();

        $goodsWithSource = $rows->where('source_exists', true)->pluck('database_good_id')->unique();
        $rows = $rows->map(function (array $row) use ($goodsWithSource) {
            if (! $row['source_exists'] && ! $goodsWithSource->contains($row['database_good_id'])) {
                $row['status'] = 'delete_candidate_good';
                $row['comparisons'] = [['status' => 'delete_candidate_good', 'attribute' => 'Вариации товара', 'source_value' => 'Нет в файле', 'database_values' => []]];
                $row['differences'] = $row['comparisons'];
            }
            return $row;
        });

        foreach ($sourceItems as $source) {
            if ($databaseSkus->contains($source->external_sku)) continue;
            $hasDatabaseProduct = $sourceDirectGoods->has($source->external_sku);
            $rows->push([
                'item_id' => $source->id,
                'external_sku' => $source->external_sku,
                'source_group_name' => $source->clean_name ?: $this->sourceGroupName($source->name, $source->external_sku),
                'source_name' => $source->name,
                'database_match_type' => null,
                'database_variation_id' => null,
                'database_good_id' => null,
                'database_name' => null,
                'database_slug' => null,
                'comparisons' => [['status' => $hasDatabaseProduct ? 'source_variation_missing' : 'source_good_missing', 'attribute' => 'SKU вариации', 'source_value' => $source->external_sku, 'database_values' => []]],
                'differences' => [['status' => $hasDatabaseProduct ? 'source_variation_missing' : 'source_good_missing', 'attribute' => 'SKU вариации', 'source_value' => $source->external_sku, 'database_values' => []]],
                'status' => $hasDatabaseProduct ? 'source_variation_missing' : 'source_good_missing',
                'source_exists' => true,
            ]);
        }

        $allRows = $rows;
        if ($search) {
            $needle = mb_strtolower(trim($search));
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) $row['external_sku'].' '.$row['source_name'].' '.$row['database_name']), $needle))->values();
        }
        $filters = array_values(array_intersect($filters, [
            'match',
            'attention',
            'delete_candidate_good',
            'delete_candidate_variation',
            'source_good_missing',
            'source_variation_missing',
        ]));
        if ($filters !== []) {
            $rows = $rows->whereIn('status', $filters)->values();
        }
        // Пагинируем товарами, а не отдельными SKU: все вариации одного товара
        // всегда остаются на одной странице для наглядной сверки.
        $groups = $rows->groupBy(fn (array $row) => $row['database_good_id']
            ? 'good-'.$row['database_good_id']
            : 'source-'.$row['external_sku']);
        $total = $groups->count();
        $pageRows = $groups->forPage($page, $perPage)->flatten(1)->values();

        return [
            'data' => $pageRows,
            'stats' => [
                'database_goods_with_variations' => $databaseVariations->pluck('good_id')->unique()->count(),
                'delete_candidate_goods' => $allRows->where('status', 'delete_candidate_good')->pluck('database_good_id')->unique()->count(),
                'delete_candidate_variations' => $allRows->where('status', 'delete_candidate_variation')->count(),
                'source_good_missing' => $allRows->where('status', 'source_good_missing')->count(),
                'source_variation_missing' => $allRows->where('status', 'source_variation_missing')->count(),
            ],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function imageAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 10, ?string $search = null): array
    {
        $this->assertReadySnapshot($snapshot);
        $paginator = $this->snapshotItemsQuery($snapshot, $search)->paginate($perPage, ['*'], 'page', $page);
        $items = $paginator->getCollection();
        $sourceUrlGroups = [];
        foreach ($items as $item) {
            foreach ($item->image_urls ?? [] as $url) {
                $sourceUrlGroups[$url][] = $item->external_sku;
            }
        }

        $matches = $this->resolveSkuMatches($items->pluck('external_sku'), $snapshot->supplier_code);
        $variations = $matches->where('type', 'variation')->pluck('model');
        $goods = $matches->map(fn (array $match) => $match['type'] === 'good' ? $match['model'] : $match['model']->good)->unique('id')->values();
        $variationIds = $variations->pluck('id');
        $goodIds = $goods->pluck('id');
        $images = ($variationIds->isEmpty() && $goodIds->isEmpty())
            ? collect()
            : ShopGoodImage::query()
                ->where(function ($query) use ($variationIds, $goodIds) {
                    if ($variationIds->isNotEmpty()) $query->whereIn('variation_id', $variationIds);
                    if ($goodIds->isNotEmpty()) $query->orWhereIn('good_id', $goodIds);
                })
                ->get(['id', 'good_id', 'variation_id', 'file_path', 'is_main']);
        $pathGroups = $images->groupBy('file_path')->filter(fn (Collection $group) => $group->count() > 1);
        $imageCountByVariation = $images->groupBy('variation_id')->map(fn (Collection $group) => $group->count());
        $imageCountByGood = $images->whereNull('variation_id')->groupBy('good_id')->map(fn (Collection $group) => $group->count());
        $coverageMismatches = [];
        $imageComparisons = [];
        foreach ($items as $item) {
            $match = $matches->get($item->external_sku);
            if (! $match) {
                continue;
            }
            $variation = $match['type'] === 'variation' ? $match['model'] : null;
            $good = $variation ? $variation->good : $match['model'];
            $expectedCount = count($item->image_urls ?? []);
            $actualCount = $variation
                ? (int) ($imageCountByVariation->get($variation->id) ?? 0)
                : (int) ($imageCountByGood->get($good->id) ?? 0);
            $comparison = [
                'external_sku' => $item->external_sku,
                'match_type' => $match['type'],
                'variation_id' => $variation?->id,
                'good_id' => $good->id,
                'expected_count' => $expectedCount,
                'database_count' => $actualCount,
                'is_match' => $expectedCount === $actualCount,
                'source_urls' => array_values($item->image_urls ?? []),
                'database_paths' => $images
                    ->filter(fn (ShopGoodImage $image) => $variation ? $image->variation_id === $variation->id : ($image->good_id === $good->id && $image->variation_id === null))
                    ->pluck('file_path')
                    ->values()
                    ->all(),
                'good_name' => $good->name,
                'good_slug' => $good->slug,
            ];
            $imageComparisons[] = $comparison;
            if (! $comparison['is_match']) {
                $coverageMismatches[] = $comparison;
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
            'coverage_mismatches' => $coverageMismatches,
            'image_comparisons' => $imageComparisons,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function propertyAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?string $search = null): array
    {
        $this->assertReadySnapshot($snapshot);
        $paginator = $this->snapshotItemsQuery($snapshot, $search)->paginate($perPage, ['*'], 'page', $page);
        $matches = $this->resolveSkuMatches($paginator->getCollection()->pluck('external_sku'), $snapshot->supplier_code);
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'product')
            ->whereNotNull('property_id')
            ->where('is_check_enabled', true)
            ->with('property:id,name')
            ->get();
        $goodIds = $matches->map(fn (array $match) => $match['type'] === 'good' ? $match['model']->id : $match['model']->good_id)->unique()->values();
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
            $match = $matches->get($item->external_sku);
            $goodId = $match ? ($match['type'] === 'good' ? $match['model']->id : $match['model']->good_id) : null;
            $differences = [];
            if (! $match) {
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
                    $databaseValues = $properties->get($goodId.':'.$mapping->property_id, collect())
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
                'database_good_id' => $goodId,
                'database_name' => $match ? ($match['type'] === 'good' ? $match['model']->name : $match['model']->good->name) : null,
                'database_slug' => $match ? ($match['type'] === 'good' ? $match['model']->slug : $match['model']->good->slug) : null,
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

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function goodAudit(SupplierCatalogSnapshot $snapshot, int $page = 1, int $perPage = 50, ?array $onlyTargets = null, ?string $search = null): array
    {
        $this->assertReadySnapshot($snapshot);
        $paginator = $this->snapshotItemsQuery($snapshot, $search)->paginate($perPage, ['*'], 'page', $page);
        $matches = $this->resolveSkuMatches($paginator->getCollection()->pluck('external_sku'), $snapshot->supplier_code);
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->where('scope', 'good')
            ->where('is_check_enabled', true)
            ->when($onlyTargets !== null, fn ($query) => $query->whereIn('conditions->target', $onlyTargets))
            ->get();
        $expectedTargets = $onlyTargets ?? ['name', 'short_description', 'description', 'brand'];
        $mappedTargets = $mappings->map(fn (SupplierCatalogFieldMapping $mapping) => (string) data_get($mapping->conditions, 'target'))->filter()->unique()->all();
        $goodIds = $matches->map(fn (array $match) => $match['type'] === 'good' ? $match['model']->id : $match['model']->good_id)->unique()->values();
        $goods = ShopGood::query()->whereIn('id', $goodIds)->with('brands:id,name')->get()->keyBy('id');
        $rows = [];

        foreach ($paginator->getCollection() as $item) {
            $match = $matches->get($item->external_sku);
            $good = $match ? $goods->get($match['type'] === 'good' ? $match['model']->id : $match['model']->good_id) : null;
            $differences = [];
            if (! $good) {
                $differences[] = ['status' => 'source_sku_not_found', 'field' => null, 'source_value' => null, 'database_values' => []];
            } else {
                foreach ($mappings as $mapping) {
                    $target = (string) data_get($mapping->conditions, 'target');
                    $sourceValue = $this->nullableString($item->raw_payload[$mapping->source_field] ?? null);
                    if ($target === '' || $sourceValue === null) {
                        continue;
                    }
                    $comparisonSourceValue = $this->applyMappingAdjustment($sourceValue, $mapping->conditions ?? []);
                    $databaseValues = $target === 'brand'
                        ? $good->brands->pluck('name')->values()->all()
                        : [trim((string) ($good->{$target} ?? ''))];
                    $databaseValues = array_values(array_filter($databaseValues, static fn ($value) => $value !== ''));
                    $matchesValue = collect($databaseValues)->contains(fn (string $value) => $this->normalizeComparisonValue($value) === $this->normalizeComparisonValue($comparisonSourceValue));
                    $differences[] = [
                        'status' => empty($databaseValues) ? 'missing_in_database' : ($matchesValue ? 'match' : 'different'),
                        'field' => $target,
                        'source_field' => $mapping->source_field,
                        'source_value' => $comparisonSourceValue,
                        'source_raw_value' => $sourceValue,
                        'database_values' => $databaseValues,
                    ];
                }

                foreach (array_diff($expectedTargets, $mappedTargets) as $target) {
                    $databaseValues = $target === 'brand'
                        ? $good->brands->pluck('name')->values()->all()
                        : [trim((string) ($good->{$target} ?? ''))];
                    $differences[] = [
                        'status' => 'not_mapped',
                        'field' => $target,
                        'source_field' => null,
                        'source_value' => null,
                        'database_values' => array_values(array_filter($databaseValues, static fn ($value) => $value !== '')),
                    ];
                }
            }
            $rows[] = [
                'item_id' => $item->id,
                'external_sku' => $item->external_sku,
                'source_name' => $item->name,
                'database_match_type' => $match['type'] ?? null,
                'database_good_id' => $good?->id,
                'database_name' => $good?->name,
                'database_slug' => $good?->slug,
                'differences' => $differences,
                'status' => ! $good ? 'not_found' : (collect($differences)->contains(fn (array $diff) => ! in_array($diff['status'], ['match'], true)) ? 'attention' : 'match'),
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
                $comparisons[] = ['status' => 'not_checked', 'attribute' => $variation->attributeValues->firstWhere('attribute_id', $attributeId)?->attribute?->name, 'source_value' => null, 'database_values' => $values];
            }
        }

        return $comparisons;
    }

    /** @return array<int, array<string, mixed>> */
    private function singleProductVariationComparisons(ShopGoodVariation $variation, SupplierCatalogItem $source, string $supplierCode): array
    {
        $attributes = $this->standardVariationAttributeIds();
        $yearAttributeId = $attributes['Год'] ?? null;
        if ($supplierCode !== 'bikeproducts' || $yearAttributeId === null) {
            return $this->variationComparisons($variation, $source, collect(), $supplierCode);
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

    /** @return array{clean_name: ?string, color: ?string, size: ?string, year: ?string, is_variation: bool} */
    private function normalizeSourceVariation(array $payload, string $supplierCode, ?string $fallbackSku = null): array
    {
        $name = $this->nullableString($payload[self::SOURCE_COLUMNS['name']] ?? null);
        $normalized = [
            'clean_name' => $this->sourceGroupName($name, $fallbackSku),
            'color' => null,
            'size' => null,
            'year' => null,
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
        $contents = trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $contents) ?? $contents);
        $parts = array_values(array_filter(array_map('trim', explode(',', $contents)), static fn (string $part) => $part !== ''));
        if (count($parts) < 2) {
            return $normalized;
        }

        $normalized['color'] = $parts[0];
        $normalized['size'] = $parts[1];
        $normalized['is_variation'] = true;

        return $normalized;
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
            $color = $this->nullableString($item->source_color);
            $size = $this->nullableString($item->source_size);
            if ($color !== null && $size !== null) {
                return $this->namedVariationAxes($color, $size, $this->standardVariationAttributeIds());
            }
        }

        return $this->resolveSourceAxes($item->raw_payload ?? [], $mappings, $supplierCode);
    }

    private function isSourceVariationItem(SupplierCatalogItem $item): bool
    {
        if ($item->is_source_variation) {
            return true;
        }

        // Снимки, созданные до добавления нормализованных колонок, остаются пригодными для анализа.
        $normalized = $this->normalizeSourceVariation($item->raw_payload ?? [], 'bikeproducts', $item->external_sku);
        return $normalized['is_variation'];
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

    /** @param Collection<int, string> $skus @return Collection<string, array{type: string, model: ShopGood|ShopGoodVariation}> */
    private function resolveSkuMatches(Collection $skus, string $supplierCode): Collection
    {
        $skus = $skus->filter()->unique()->values();
        if ($skus->isEmpty()) {
            return collect();
        }

        $supplierNames = $this->supplierNames($supplierCode);
        if ($supplierNames === []) {
            return collect();
        }

        $goods = ShopGood::query()
            ->whereIn('sku', $skus)
            ->whereIn('supplier', $supplierNames)
            ->get(['id', 'sku', 'name', 'slug', 'supplier']);
        $variations = $this->findVariationsBySku($skus, $supplierCode);
        $matches = $variations->map(fn (ShopGoodVariation $variation) => ['type' => 'variation', 'model' => $variation]);

        // Товар имеет приоритет: строка поставщика сначала описывает самостоятельный товар,
        // затем вариацию, если товара с таким SKU нет.
        foreach ($goods as $good) {
            $matches->put($good->sku, ['type' => 'good', 'model' => $good]);
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
