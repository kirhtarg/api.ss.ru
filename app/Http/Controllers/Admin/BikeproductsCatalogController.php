<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\ShopVariationAttribute;
use App\Models\SupplierCatalogActionRun;
use App\Models\SupplierCatalogFieldMapping;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierCatalogSnapshot;
use App\Models\SupplierFeedPriceStockRun;
use App\Models\SupplierFeedPriceStockChange;
use App\Jobs\ProcessSupplierCatalogExcelJob;
use App\Jobs\AuditSupplierCatalogImagesJob;
use App\Jobs\SyncSupplierCatalogImagesJob;
use App\Jobs\WarmSupplierCatalogVariationAuditJob;
use App\Services\BikeproductsCatalogService;
use App\Services\SupplierFeedPriceStockService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;

class BikeproductsCatalogController extends Controller
{
    public function __construct(
        private readonly BikeproductsCatalogService $catalog,
        private readonly SupplierFeedPriceStockService $feedPriceStock,
    )
    {
    }

    public function profiles(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->availableProfiles()]);
    }

    public function updateProfile(Request $request, SupplierCatalogProfile $profile): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.yml_url' => ['nullable', 'url', 'max:1000'],
            'settings.feed_type' => ['nullable', 'in:auto,xml,yml'],
            'settings.image_base_url' => ['nullable', 'url', 'max:1000'],
            'settings.feed_sync_enabled' => ['nullable', 'boolean'],
            'settings.feed_sync_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'settings.feed_sku_source' => ['nullable', 'in:offer_id,vendor_code,param'],
            'settings.feed_sku_param' => ['nullable', 'string', 'max:190'],
            'settings.feed_price_target' => ['nullable', 'in:price,sale_price,demping_price'],
            'settings.feed_stock_target' => ['nullable', 'in:stock_quantity,remote_stock_quantity,fast_remote_stock_quantity'],
            'settings.feed_available_quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'settings.feed_missing_in_feed_action' => ['nullable', 'in:nothing,clear'],
            'settings.min_parse_price' => ['nullable', 'numeric', 'min:0'],
            'settings.min_parse_price_mode' => ['nullable', 'in:any,all'],
            'settings.min_parse_price_source_fields' => ['nullable', 'array', 'max:20'],
            'settings.min_parse_price_source_fields.*' => ['string', 'max:190'],
            'settings.sku_replace_rules' => ['nullable', 'array', 'max:50'],
            'settings.sku_replace_rules.*.from' => ['nullable', 'string', 'max:120'],
            'settings.sku_replace_rules.*.to' => ['nullable', 'string', 'max:120'],
            'settings.sku_replace_rules.*.enabled' => ['nullable', 'boolean'],
        ]);

        $profile->update([
            'settings' => array_merge($profile->settings ?? [], $data['settings']),
        ]);
        $this->touchSupplierSnapshots($profile->code);

        // fresh() accepts relationship names, not a column list. Passing
        // columns here made Eloquent try to load a relation named "id".
        return response()->json(['success' => true, 'data' => $profile->fresh()]);
    }

    public function queueFeedPriceStockSync(Request $request, SupplierCatalogProfile $profile): JsonResponse
    {
        $data = $request->validate(['mode' => ['nullable', 'in:preview,sync']]);
        $mode = $data['mode'] ?? 'sync';
        $run = $this->feedPriceStock->queue($profile, 'manual', $mode);

        return response()->json([
            'success' => true,
            'message' => $mode === 'preview'
                ? 'Проверка фида поставлена в очередь. База не будет изменена.'
                : 'Проверка и обновление цены и остатка по фиду поставлены в очередь.',
            'data' => $run,
        ], 202);
    }

    public function feedPriceStockRuns(SupplierCatalogProfile $profile): JsonResponse
    {
        return response()->json(['success' => true, 'data' => SupplierFeedPriceStockRun::query()
            ->where('profile_id', $profile->id)
            ->latest('id')
            ->limit(10)
            ->get(),
        ]);
    }

    public function feedPriceStockChanges(Request $request, SupplierCatalogProfile $profile, SupplierFeedPriceStockRun $run): JsonResponse
    {
        abort_unless($run->profile_id === $profile->id, 404);
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $filter = (string) $request->query('filter', 'all');
        $query = SupplierFeedPriceStockChange::query()->where('run_id', $run->id);
        $search = trim((string) $request->query('search', ''));
        $rows = $query->orderBy('id')->get();
        $reportSkus = $rows
            ->pluck('sku')
            ->filter()
            ->unique()
            ->values();
        $sourceItemsBySku = collect();
        $hasSourceSnapshot = false;
        if ($reportSkus->isNotEmpty()) {
            $snapshotId = SupplierCatalogSnapshot::query()
                ->where('supplier_code', $profile->code)
                ->where('status', 'ready')
                ->latest('id')
                ->value('id');
            if ($snapshotId) {
                $hasSourceSnapshot = true;
                $sourceItemsBySku = SupplierCatalogItem::query()
                    ->where('snapshot_id', $snapshotId)
                    ->whereIn('external_sku', $reportSkus)
                    ->get(['id', 'external_sku', 'name'])
                    ->groupBy('external_sku');
            }
        }

        $rows = $rows
            ->groupBy(fn (SupplierFeedPriceStockChange $change) => implode(':', [
                $change->entity_type,
                $change->good_id ?: 0,
                $change->variation_id ?: 0,
                $change->sku,
            ]))
            ->map(function ($changes) use ($profile, $sourceItemsBySku, $hasSourceSnapshot): array {
                /** @var SupplierFeedPriceStockChange $first */
                $first = $changes->first();
                $price = $changes->firstWhere('field', $this->feedPriceField($profile));
                $stock = $changes->firstWhere('field', $this->feedStockField($profile));

                $sourceItems = $sourceItemsBySku->get($first->sku, collect());
                $sourceName = $sourceItems
                    ->pluck('name')
                    ->map(fn ($name) => trim((string) $name))
                    ->first(fn (string $name) => $name !== '');
                $hasSourceItem = $sourceItems->isNotEmpty();
                $feedName = trim((string) $first->good_name);
                $goodName = $feedName !== '' ? $feedName : $sourceName;
                $canFillSourceName = $first->entity_type === 'none'
                    && $hasSourceItem
                    && $sourceName === null
                    && $feedName !== '';

                return [
                    'id' => $first->id,
                    'sku' => $first->sku,
                    'entity_type' => $first->entity_type,
                    'good_id' => $first->good_id,
                    'variation_id' => $first->variation_id,
                    'good_name' => $goodName,
                    'feed_name' => $feedName !== '' ? $feedName : null,
                    'source_file_exists' => $hasSourceSnapshot ? $hasSourceItem : null,
                    'source_name_state' => ! $hasSourceSnapshot
                        ? 'unavailable'
                        : (! $hasSourceItem ? 'missing_row' : ($sourceName === null ? 'empty' : 'filled')),
                    'can_fill_source_name' => $canFillSourceName,
                    'status' => $changes->contains('status', 'not_found')
                        ? 'not_found'
                        : ($changes->contains('status', 'missing_in_feed')
                            ? 'missing_in_feed'
                            : ($changes->contains('status', 'different') ? 'different' : 'match')),
                    'price' => $price ? [
                        'status' => $price->status,
                        'before_value' => $price->before_value,
                        'after_value' => $price->after_value,
                    ] : null,
                    'stock' => $stock ? [
                        'status' => $stock->status,
                        'before_value' => $stock->before_value,
                        'after_value' => $stock->after_value,
                    ] : null,
                ];
            })
            ->values();
        $rows = (match ($filter) {
            'not_found' => $rows->filter(fn (array $row) => $row['status'] === 'not_found'),
            'missing_in_feed' => $rows->filter(fn (array $row) => $row['status'] === 'missing_in_feed'),
            'price_different' => $rows->filter(fn (array $row) => data_get($row, 'price.status') === 'different'),
            'price_match' => $rows->filter(fn (array $row) => data_get($row, 'price.status') === 'match'),
            'stock_different' => $rows->filter(fn (array $row) => data_get($row, 'stock.status') === 'different'),
            'stock_match' => $rows->filter(fn (array $row) => data_get($row, 'stock.status') === 'match'),
            default => $rows,
        })->values();
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn (array $row) => str_contains(
                mb_strtolower(implode(' ', [$row['sku'], $row['good_name'] ?? ''])),
                $needle,
            ))->values();
        }
        $total = $rows->count();
        $paginator = new LengthAwarePaginator(
            $rows->slice(($request->integer('page', 1) - 1) * $perPage, $perPage)->values(),
            $total,
            $perPage,
            $request->integer('page', 1),
        );

        return response()->json(['success' => true, 'data' => [
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]]);
    }

    public function fillFeedPriceStockSourceName(Request $request, SupplierCatalogProfile $profile, SupplierFeedPriceStockRun $run): JsonResponse
    {
        abort_unless($run->profile_id === $profile->id, 404);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:1000'],
        ]);
        $sku = trim($data['sku']);
        $name = trim($data['name']);
        if ($sku === '' || $name === '') {
            throw ValidationException::withMessages([
                'name' => ['Для добавления требуется непустой артикул и название.'],
            ]);
        }

        $snapshot = SupplierCatalogSnapshot::query()
            ->where('supplier_code', $profile->code)
            ->where('status', 'ready')
            ->latest('id')
            ->first();
        if (! $snapshot) {
            return response()->json(['success' => false, 'message' => 'Нет готового файла поставщика для изменения.'], 422);
        }

        $items = SupplierCatalogItem::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_sku', $sku)
            ->get();
        if ($items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'В текущем файле нет строки с этим артикулом.'], 422);
        }

        $updated = 0;
        foreach ($items as $item) {
            if (trim((string) $item->name) !== '') {
                continue;
            }
            $item->update(['name' => $name, 'clean_name' => $name]);
            $updated++;
        }
        if ($updated === 0) {
            return response()->json(['success' => false, 'message' => 'Название в строке файла уже заполнено.'], 422);
        }

        $this->touchSupplierSnapshots($profile->code);

        return response()->json([
            'success' => true,
            'message' => "Название добавлено в {$updated} строк(и) текущего файла.",
            'data' => ['updated' => $updated],
        ]);
    }

    public function deleteFeedPriceStockRun(SupplierCatalogProfile $profile, SupplierFeedPriceStockRun $run): JsonResponse
    {
        abort_unless($run->profile_id === $profile->id, 404);
        if (in_array($run->status, ['queued', 'running'], true)) {
            return response()->json(['success' => false, 'message' => 'Нельзя удалить выполняющийся запуск.'], 422);
        }
        $run->delete();

        return response()->json(['success' => true, 'message' => 'Отчёт проверки фида удалён.']);
    }

    public function clearMissingInFeedStocks(SupplierCatalogProfile $profile, SupplierFeedPriceStockRun $run): JsonResponse
    {
        abort_unless($run->profile_id === $profile->id, 404);
        $result = $this->feedPriceStock->clearMissingInFeedStocks($profile, $run);

        return response()->json([
            'success' => true,
            'message' => "Очищено остатков: {$result['cleared']} ({$this->feedStockField($profile)}).",
            'data' => $result,
        ]);
    }

    private function feedPriceField(SupplierCatalogProfile $profile): string
    {
        return (string) data_get($profile->settings, 'feed_price_target', 'price');
    }

    private function feedStockField(SupplierCatalogProfile $profile): string
    {
        return (string) data_get($profile->settings, 'feed_stock_target', 'stock_quantity');
    }

    public function snapshots(Request $request): JsonResponse
    {
        $supplierCode = $this->selectedSupplierCode($request->query('supplier_code'));

        return response()->json([
            'success' => true,
            'data' => SupplierCatalogSnapshot::query()
                ->where('supplier_code', $supplierCode)
                ->latest('id')
                ->get(),
        ]);
    }

    public function destroySnapshot(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        $this->catalog->clearSnapshot($snapshot);

        return response()->json(['success' => true]);
    }

    public function uploadExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:122880'],
            'supplier_code' => ['required', 'string', 'max:80'],
        ]);

        $extension = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Поддерживаются файлы .xlsx и .csv.'],
            ]);
        }

        try {
            $supplierCode = $this->selectedSupplierCode($request->input('supplier_code'));
            $snapshot = $this->catalog->queueExcelImport($request->file('file'), $supplierCode);
            // Тяжёлый разбор выполняется отдельным queue worker, а не процессом PHP-FPM.
            ProcessSupplierCatalogExcelJob::dispatch($snapshot->id);

            return response()->json([
                'success' => true,
                'message' => 'Файл поставлен в очередь на разбор.',
                'data' => $snapshot,
            ], 202);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось разобрать Excel поставщика: '.$exception->getMessage(),
            ], 422);
        }
    }

    public function status(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $snapshot]);
    }

    public function overview(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        return response()->json(['success' => true, 'data' => $this->cachedAudit(
            $snapshot,
            'overview-v2',
            [],
            fn () => $this->catalog->overview($snapshot),
        )]);
    }

    public function fields(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $this->catalog->ensureDefaultMappings($snapshot->supplier_code);
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->get()
            ->keyBy(fn (SupplierCatalogFieldMapping $mapping) => $mapping->source_field.'|'.$mapping->scope);
        $fields = collect($snapshot->summary['fields'] ?? [])->map(function (array $stats, string $sourceField) use ($mappings) {
            $variationMapping = $mappings->get($sourceField.'|variation');
            $propertyMapping = $mappings->get($sourceField.'|product');
            $goodMapping = $mappings->get($sourceField.'|good');
            $imageMapping = $mappings->get($sourceField.'|image');

            return [
                'source_field' => $sourceField,
                ...$stats,
                'variation_mapping' => $this->mappingPayload($variationMapping),
                'property_mapping' => $this->mappingPayload($propertyMapping),
                'good_mapping' => $this->mappingPayload($goodMapping),
                'image_mapping' => $this->mappingPayload($imageMapping),
            ];
        })->sortByDesc('nonempty_count')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'fields' => $fields,
                'variation_attributes' => ShopVariationAttribute::query()->ordered()->get(['id', 'name']),
                'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'property_type']),
            ],
        ]);
    }

    public function updateFieldMapping(Request $request, SupplierCatalogFieldMapping $mapping): JsonResponse
    {
        $data = $request->validate([
            'variation_attribute_id' => ['nullable', 'integer', 'exists:shop_variation_attributes,id'],
            'property_id' => ['nullable', 'integer', 'exists:shop_properties,id'],
            'display_field' => ['nullable', 'string', 'max:120'],
            'conditions' => ['nullable', 'array'],
            'conditions.target' => ['nullable', 'in:name,description,short_description,brand,weight,depth,width,height,price,sale_price,demping_price,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity'],
            'conditions.adjustment' => ['nullable', 'in:none,add_percent,subtract_percent,add_fixed,subtract_fixed'],
            'conditions.adjustment_value' => ['nullable', 'numeric'],
            'conditions.name_transform' => ['nullable', 'in:none,strip_trailing_parentheses'],
            'is_check_enabled' => ['required', 'boolean'],
            'is_update_enabled' => ['required', 'boolean'],
        ]);

        $mapping->update($data);
        if ($mapping->scope === 'image') {
            $this->catalog->invalidateImageContentAudits($mapping->supplier_code);
        }
        $this->touchSupplierSnapshots($mapping->supplier_code);

        return response()->json(['success' => true, 'data' => $mapping->fresh(['variationAttribute:id,name', 'property:id,name'])]);
    }

    public function upsertFieldMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot_id' => ['required', 'integer', 'exists:supplier_catalog_snapshots,id'],
            'source_field' => ['required', 'string', 'max:120'],
            'scope' => ['required', 'in:variation,product,good,image'],
            'variation_attribute_id' => ['nullable', 'integer', 'exists:shop_variation_attributes,id'],
            'property_id' => ['nullable', 'integer', 'exists:shop_properties,id'],
            'display_field' => ['nullable', 'string', 'max:120'],
            'conditions' => ['nullable', 'array'],
            'conditions.target' => ['nullable', 'in:name,description,short_description,brand,weight,depth,width,height,price,sale_price,demping_price,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity'],
            'conditions.adjustment' => ['nullable', 'in:none,add_percent,subtract_percent,add_fixed,subtract_fixed'],
            'conditions.adjustment_value' => ['nullable', 'numeric'],
            'conditions.name_transform' => ['nullable', 'in:none,strip_trailing_parentheses'],
            'is_check_enabled' => ['required', 'boolean'],
            'is_update_enabled' => ['required', 'boolean'],
        ]);

        $snapshot = SupplierCatalogSnapshot::findOrFail($data['snapshot_id']);
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        abort_unless(array_key_exists($data['source_field'], $snapshot->summary['fields'] ?? []), 422, 'Поле отсутствует в текущем снимке.');

        $mapping = SupplierCatalogFieldMapping::updateOrCreate(
            [
                'supplier_code' => $snapshot->supplier_code,
                'source_field' => $data['source_field'],
                'scope' => $data['scope'],
            ],
            [
                'variation_attribute_id' => $data['variation_attribute_id'],
                'property_id' => $data['property_id'],
                'display_field' => $data['display_field'],
                'conditions' => $data['conditions'] ?? null,
                'is_check_enabled' => $data['is_check_enabled'],
                'is_update_enabled' => $data['is_update_enabled'],
            ]
        );
        if ($mapping->scope === 'image') {
            $this->catalog->invalidateImageContentAudits($snapshot->supplier_code);
        }
        $this->touchSupplierSnapshots($snapshot->supplier_code);

        return response()->json(['success' => true, 'data' => $mapping->fresh(['variationAttribute:id,name', 'property:id,name'])]);
    }

    public function variationAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'statuses' => ['nullable', 'array', 'max:5'],
            'statuses.*' => ['string', 'in:attention,match,different,missing_in_database,missing_in_file,empty_both,source_has_value,source_empty,database_has_value,database_empty'],
            'variation_count' => ['nullable', 'in:all,single,multiple'],
            'main_stock' => ['nullable', 'in:all,zero,in_stock'],
            'remote_stock' => ['nullable', 'in:all,empty,not_empty'],
            'axis_issues' => ['nullable', 'array', 'max:3'],
            'axis_issues.*' => ['string', 'in:different,missing,extra'],
            'good_id' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*' => ['string', 'in:match,attention,attention_single_variation,delete_candidate_good,delete_candidate_variation,source_good_missing,source_variation_missing,source_single_product_update,source_single_product_sku_mismatch,source_variation_sku_mismatch,source_sku_other_supplier,source_price_excluded,source_name_missing,database_duplicate_sku'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->cachedAudit(
                $snapshot,
                'variations-v32',
                $data,
                fn () => $this->catalog->variationAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, $data['search'] ?? null, $data['filters'] ?? [], $data['variation_count'] ?? 'all', $data['good_id'] ?? null, $data['main_stock'] ?? 'all', $data['remote_stock'] ?? 'all', $data['axis_issues'] ?? []),
            ),
        ]);
    }

    public function variationAuditStats(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $stats = $this->catalog->cachedVariationAuditStats($snapshot);
        if ($stats !== null) {
            return response()->json(['success' => true, 'data' => [
                'status' => 'ready',
                'stats' => $stats,
            ]]);
        }

        $version = $snapshot->updated_at?->format('Uu') ?? '0';
        $lockKey = 'supplier-catalog:variation-audit-warming:v5:'.$snapshot->id.':'.$version;
        if (Cache::store('file')->add($lockKey, true, now()->addMinutes(30))) {
            WarmSupplierCatalogVariationAuditJob::dispatch($snapshot->id, $version);
        }

        return response()->json(['success' => true, 'data' => [
            'status' => 'processing',
            'stats' => [],
        ]]);
    }

    public function applyVariationAction(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'action' => ['required', 'in:delete,zero_remote_stocks,zero_main_stock,zero_stocks,repair_axes,add_missing_axes,replace_axis_values,remove_extra_axes'],
            'variation_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'variation_ids.*' => ['integer', 'distinct'],
        ]);
        return response()->json(['success' => true, 'data' => $this->runCatalogAction(
            $request,
            $snapshot,
            'variations',
            $data['action'],
            $data['variation_ids'],
            fn () => $this->catalog->applyVariationAction($snapshot, $data['action'], $data['variation_ids']),
        )]);
    }

    public function updateDuplicateSkuVariation(Request $request, SupplierCatalogSnapshot $snapshot, int $variationId): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        foreach (['price', 'sale_price', 'demping_price', 'stock_quantity'] as $field) {
            if ($request->has($field) && $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'demping_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'remote_stock_quantity' => ['nullable', 'string', 'max:255'],
            'fast_remote_stock_quantity' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->updateDuplicateSkuVariation($snapshot, $variationId, $data),
            'message' => 'Вариация обновлена.',
        ]);
    }

    public function deleteDuplicateSkuVariation(SupplierCatalogSnapshot $snapshot, int $variationId): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => $this->catalog->deleteDuplicateSkuVariation($snapshot, $variationId),
            'message' => 'Вариация удалена.',
        ]);
    }

    public function variationSelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'variation_count' => ['nullable', 'in:all,single,multiple'],
            'main_stock' => ['nullable', 'in:all,zero,in_stock'],
            'remote_stock' => ['nullable', 'in:all,empty,not_empty'],
            'axis_issues' => ['nullable', 'array', 'max:3'],
            'axis_issues.*' => ['string', 'in:different,missing,extra'],
            'good_id' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*' => ['string', 'in:match,attention,attention_single_variation,delete_candidate_good,delete_candidate_variation,source_good_missing,source_variation_missing,source_single_product_update,source_single_product_sku_mismatch,source_variation_sku_mismatch,source_sku_other_supplier,source_price_excluded,source_name_missing,database_duplicate_sku'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->variationSelection($snapshot, $data['search'] ?? null, $data['filters'] ?? [], $data['variation_count'] ?? 'all', $data['good_id'] ?? null, $data['main_stock'] ?? 'all', $data['remote_stock'] ?? 'all', $data['axis_issues'] ?? []),
        ]);
    }

    public function rollbackAction(SupplierCatalogSnapshot $snapshot, SupplierCatalogActionRun $actionRun): JsonResponse
    {
        abort_unless($actionRun->snapshot_id === $snapshot->id, 404);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->rollbackCatalogAction($actionRun),
        ]);
    }

    public function imageAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'statuses' => ['nullable', 'array', 'max:1'],
            'statuses.*' => ['string', 'in:match,source_missing_in_database,database_missing_in_source,content,visual,source_broken,database_broken,broken_database_filename'],
        ]);
        return response()->json(['success' => true, 'data' => $this->cachedAudit(
            $snapshot,
            'images',
            $data,
            fn () => $this->catalog->imageAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 10, $data['search'] ?? null, $data['statuses'] ?? []),
        )]);
    }

    public function startImageContentAudit(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $result = $this->catalog->startImageContentAudit($snapshot);
        if ($result['started'] && $result['status'] === 'processing') {
            // Three short independent chains keep the progress responsive but
            // stay below a supplier-hostile number of simultaneous downloads.
            for ($worker = 0; $worker < 3; $worker++) {
                AuditSupplierCatalogImagesJob::dispatch($snapshot->id);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => $result['started'] ? 'Аудит изображений поставлен в очередь.' : 'Аудит уже был запущен для этого файла.',
        ], $result['started'] ? 202 : 200);
    }

    public function imageContentAuditStatus(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        return response()->json(['success' => true, 'data' => $this->catalog->imageContentAuditStatus($snapshot)]);
    }

    public function imageSelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'in:append,replace,reconcile,prune,delete_broken'],
        ]);
        return response()->json(['success' => true, 'data' => [
            'item_ids' => $this->catalog->imageSelection($snapshot, $data['mode'] ?? 'reconcile', $data['search'] ?? null),
        ]]);
    }

    public function propertyAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'statuses' => ['nullable', 'array', 'max:5'],
            'statuses.*' => ['string', 'in:attention,match,different,missing_in_database,missing_in_file,empty_both,source_has_value,source_empty,database_has_value,database_empty'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->cachedAudit(
                $snapshot,
                'properties',
                $data,
                fn () => $this->catalog->propertyAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, $data['search'] ?? null, $data['statuses'] ?? []),
            ),
        ]);
    }

    public function propertySelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['success' => true, 'data' => [
            'item_ids' => $this->catalog->propertySelection($snapshot, $data['search'] ?? null),
        ]]);
    }

    public function goodAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'refresh' => ['nullable', 'string', 'max:40'],
            'statuses' => ['nullable', 'array', 'max:4'],
            'statuses.*' => ['string', 'in:match,attention,not_found,missing_in_file,source_name_missing'],
            'difference_types' => ['nullable', 'array', 'max:4'],
            'difference_types.*' => ['string', 'in:name,short_description,description,brand'],
        ]);

        return response()->json(['success' => true, 'data' => $this->cachedAudit(
            $snapshot,
            'goods',
            $data,
            fn () => $this->catalog->goodAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, null, $data['search'] ?? null, $data['statuses'] ?? [], $data['difference_types'] ?? []),
        )]);
    }

    public function applyGoodAction(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'action' => ['required', 'in:create,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->runCatalogAction(
            $request,
            $snapshot,
            'goods',
            $data['action'],
            $data['ids'],
            fn () => $this->catalog->applyGoodAction($snapshot, $data['action'], $data['ids']),
        );
        if ($data['action'] === 'create' && $result['affected'] > 0) {
            $jobs = $this->dispatchImageSyncJobs($snapshot, $data['ids'], 'append', $result['action_run_id'] ?? null);
            $result['message'] .= ". Скачивание изображений запущено в очереди: {$jobs} задач";
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function goodSelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'status' => ['required', 'in:attention,not_found,missing_in_file'],
            'search' => ['nullable', 'string', 'max:120'],
            'difference_types' => ['nullable', 'array', 'max:4'],
            'difference_types.*' => ['string', 'in:name,short_description,description,brand'],
        ]);

        return response()->json(['success' => true, 'data' => [
            'ids' => $this->catalog->goodSelection($snapshot, $data['status'], $data['search'] ?? null, $data['difference_types'] ?? []),
        ]]);
    }

    public function applyMappedUpdate(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'scope' => ['required', 'in:goods,prices,stocks,zero_stocks,properties,images'],
            'item_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'item_ids.*' => ['integer', 'distinct'],
            'image_mode' => ['nullable', 'in:append,replace,reconcile,prune,delete_broken'],
            'targets' => ['nullable', 'array', 'max:4'],
            'targets.*' => ['string', 'in:name,short_description,description,brand'],
        ]);

        $result = $this->runCatalogAction(
            $request,
            $snapshot,
            $data['scope'],
            'update',
            $data['item_ids'],
            fn () => $this->catalog->applyMappedUpdate($snapshot, $data['scope'], $data['item_ids'], $data['image_mode'] ?? 'append', $data['targets'] ?? []),
        );
        $imageMode = $data['image_mode'] ?? 'append';
        if (
            $data['scope'] === 'images'
            && $result['affected'] > 0
            && in_array($imageMode, ['append', 'replace', 'reconcile'], true)
        ) {
            $jobs = $this->dispatchImageSyncJobs($snapshot, $data['item_ids'], $imageMode, $result['action_run_id'] ?? null);
            $result['message'] .= ". Скачивание файлов запущено в очереди: {$jobs} задач";
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * A mass image operation may contain hundreds of source rows. One job for
     * all of them first clears bindings and then spends too long downloading
     * files serially. Small independent jobs let the standard workers restore
     * bindings concurrently and isolate a failed supplier URL to its batch.
     *
     * @param array<int, int> $itemIds
     */
    private function dispatchImageSyncJobs(SupplierCatalogSnapshot $snapshot, array $itemIds, string $mode = 'append', ?int $actionRunId = null): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $itemIds),
            static fn (int $id) => $id > 0,
        )));

        $jobs = (int) ceil(count($ids) / 10);
        $this->initializeImageSyncRun($actionRunId, $jobs, count($ids));
        foreach (array_chunk($ids, 10) as $chunk) {
            SyncSupplierCatalogImagesJob::dispatch($snapshot->id, $chunk, $actionRunId, $mode);
        }

        return $jobs;
    }

    private function initializeImageSyncRun(?int $actionRunId, int $jobs, int $items): void
    {
        if (! $actionRunId || $jobs < 1) {
            return;
        }

        $run = SupplierCatalogActionRun::find($actionRunId);
        if (! $run) {
            return;
        }
        $result = $run->result ?? [];
        $result['image_sync'] = [
            'status' => 'queued',
            'total_jobs' => $jobs,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'total_items' => $items,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $run->update(['result' => $result]);
    }

    public function imageSyncStatus(SupplierCatalogSnapshot $snapshot, SupplierCatalogActionRun $actionRun): JsonResponse
    {
        abort_unless(
            (int) $actionRun->snapshot_id === (int) $snapshot->id && $actionRun->scope === 'images',
            404,
            'Запуск синхронизации изображений не найден.',
        );

        return response()->json(['success' => true, 'data' => ($actionRun->result ?? [])['image_sync'] ?? [
            'status' => 'completed',
            'total_jobs' => 0,
            'completed_jobs' => 0,
        ]]);
    }

    public function priceStockAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'good_id' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array', 'max:4'],
            'filters.*' => ['string', 'in:prices,stocks,source_empty_stocks,database_empty_stocks'],
        ]);

        return response()->json(['success' => true, 'data' => $this->cachedAudit(
            $snapshot,
            'prices',
            $data,
            fn () => $this->catalog->goodAudit(
                $snapshot,
                $data['page'] ?? 1,
                $data['per_page'] ?? 50,
                ['price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                $data['search'] ?? null,
                [],
                $data['filters'] ?? [],
                true,
                $data['good_id'] ?? null,
            ),
        )]);
    }

    public function priceStockSelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'good_id' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array', 'max:4'],
            'filters.*' => ['string', 'in:prices,stocks,source_empty_stocks,database_empty_stocks'],
        ]);

        return response()->json(['success' => true, 'data' => [
            'item_ids' => $this->catalog->priceStockSelection($snapshot, $data['search'] ?? null, $data['filters'] ?? [], $data['good_id'] ?? null),
        ]]);
    }

    private function mappingPayload(?SupplierCatalogFieldMapping $mapping): ?array
    {
        return $mapping ? [
            'id' => $mapping->id,
            'scope' => $mapping->scope,
            'variation_attribute_id' => $mapping->variation_attribute_id,
            'property_id' => $mapping->property_id,
            'display_field' => $mapping->display_field,
            'conditions' => $mapping->conditions,
            'is_check_enabled' => $mapping->is_check_enabled,
            'is_update_enabled' => $mapping->is_update_enabled,
        ] : null;
    }

    /** @param array<int, int> $ids @param Closure(): array<string, mixed> $callback */
    private function runCatalogAction(Request $request, SupplierCatalogSnapshot $snapshot, string $scope, string $action, array $ids, Closure $callback): array
    {
        $run = SupplierCatalogActionRun::create([
            'snapshot_id' => $snapshot->id,
            'user_id' => $request->user()?->id,
            'supplier_code' => $snapshot->supplier_code,
            'scope' => $scope,
            'action' => $action,
            'status' => 'running',
            'selection' => ['ids' => array_values(array_unique(array_map('intval', $ids)))],
            'backup' => $this->catalog->actionBackup($snapshot, $scope, $action, $ids),
        ]);

        try {
            $result = $callback();
            $run->update([
                'status' => 'completed',
                'result' => $result,
                'executed_at' => now(),
            ]);
            $snapshot->touch();
            $this->invalidateSnapshotAuditCache($snapshot->id);
            $result['action_run_id'] = $run->id;

            return $result;
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'executed_at' => now(),
            ]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $parameters @param Closure(): array<string, mixed> $callback */
    private function cachedAudit(SupplierCatalogSnapshot $snapshot, string $section, array $parameters, Closure $callback): array
    {
        ksort($parameters);
        // MySQL installations restored from older backups may store timestamps
        // with second precision. A touch performed in the same second then
        // leaves this value unchanged and serves stale audit data for 15 min.
        // Keep a separate cache version which is bumped after every action.
        $version = Cache::store('file')->get(
            $this->snapshotAuditVersionKey($snapshot->id),
            $snapshot->updated_at?->format('Uu') ?? '0',
        );
        // Increment this version whenever audit semantics change. Otherwise a
        // deployed fix can keep returning a payload cached by the previous code.
        $key = 'supplier-catalog:audit:v46:'.$snapshot->id.':'.$version.':'.$section.':'.sha1(json_encode($parameters));

        // File cache deliberately avoids depending on Redis for heavyweight
        // audit payloads and keeps repeated navigation inexpensive.
        return Cache::store('file')->remember($key, now()->addMinutes(15), $callback);
    }

    private function touchSupplierSnapshots(string $supplierCode): void
    {
        $snapshotIds = SupplierCatalogSnapshot::query()
            ->where('supplier_code', $supplierCode)
            ->where('status', 'ready')
            ->pluck('id');
        SupplierCatalogSnapshot::query()
            ->whereIn('id', $snapshotIds)
            ->update(['updated_at' => now()]);
        foreach ($snapshotIds as $snapshotId) {
            $this->invalidateSnapshotAuditCache((int) $snapshotId);
        }
    }

    private function snapshotAuditVersionKey(int $snapshotId): string
    {
        return 'supplier-catalog:audit-version:'.$snapshotId;
    }

    private function invalidateSnapshotAuditCache(int $snapshotId): void
    {
        Cache::store('file')->put(
            $this->snapshotAuditVersionKey($snapshotId),
            bin2hex(random_bytes(12)),
            now()->addDays(30),
        );
    }

    private function notReadyResponse(SupplierCatalogSnapshot $snapshot): ?JsonResponse
    {
        if ($snapshot->status === 'ready') {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Разбор Excel ещё не завершён.',
            'data' => [
                'status' => $snapshot->status,
                'stage' => $snapshot->stage,
                'progress' => $snapshot->progress,
                'processed_rows' => $snapshot->processed_rows,
                'total_rows' => $snapshot->total_rows,
                'error_message' => $snapshot->error_message,
            ],
        ], 409);
    }

    private function selectedSupplierCode(?string $supplierCode): string
    {
        $supplierCode = trim(mb_strtolower((string) $supplierCode));
        abort_unless($supplierCode !== '', 422, 'Выберите поставщика.');
        abort_unless(collect($this->availableProfiles())->contains('code', $supplierCode), 422, 'Поставщик недоступен для сервиса.');

        return $supplierCode;
    }

    private function availableProfiles(): array
    {
        return SupplierCatalogProfile::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'supplier_names', 'settings'])
            ->all();
    }
}
