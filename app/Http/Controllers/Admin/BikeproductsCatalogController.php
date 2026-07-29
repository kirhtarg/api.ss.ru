<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\ShopVariationAttribute;
use App\Models\SupplierCatalogActionRun;
use App\Models\SupplierCatalogFieldMapping;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierCatalogSnapshot;
use App\Jobs\ProcessSupplierCatalogExcelJob;
use App\Jobs\SyncSupplierCatalogImagesJob;
use App\Services\BikeproductsCatalogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BikeproductsCatalogController extends Controller
{
    public function __construct(private readonly BikeproductsCatalogService $catalog)
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
        ]);

        $profile->update([
            'settings' => array_merge($profile->settings ?? [], $data['settings']),
        ]);

        return response()->json(['success' => true, 'data' => $profile->fresh(['id', 'name', 'code', 'supplier_names', 'settings'])]);
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
            'file' => ['required', 'file', 'mimes:xlsx', 'max:122880'],
            'supplier_code' => ['required', 'string', 'max:80'],
        ]);

        try {
            $supplierCode = $this->selectedSupplierCode($request->input('supplier_code'));
            $snapshot = $this->catalog->queueExcelImport($request->file('file'), $supplierCode);
            // Тяжёлый разбор выполняется отдельным queue worker, а не процессом PHP-FPM.
            ProcessSupplierCatalogExcelJob::dispatch($snapshot->id);

            return response()->json([
                'success' => true,
                'message' => 'Excel поставлен в очередь на разбор.',
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

        return response()->json(['success' => true, 'data' => $this->catalog->overview($snapshot)]);
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
            'conditions.target' => ['nullable', 'in:name,description,short_description,brand,price,sale_price,demping_price,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity'],
            'conditions.adjustment' => ['nullable', 'in:none,add_percent,subtract_percent,add_fixed,subtract_fixed'],
            'conditions.adjustment_value' => ['nullable', 'numeric'],
            'conditions.name_transform' => ['nullable', 'in:none,strip_trailing_parentheses'],
            'is_check_enabled' => ['required', 'boolean'],
            'is_update_enabled' => ['required', 'boolean'],
        ]);

        $mapping->update($data);

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
            'conditions.target' => ['nullable', 'in:name,description,short_description,brand,price,sale_price,demping_price,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity'],
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
            'variation_count' => ['nullable', 'in:all,single,multiple'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*' => ['string', 'in:match,attention,attention_single_variation,delete_candidate_good,delete_candidate_variation,source_good_missing,source_variation_missing,source_variation_sku_mismatch,source_sku_other_supplier'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->variationAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, $data['search'] ?? null, $data['filters'] ?? [], $data['variation_count'] ?? 'all'),
        ]);
    }

    public function applyVariationAction(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'action' => ['required', 'in:delete,zero_stocks,repair_axes'],
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

    public function variationSelection(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'variation_count' => ['nullable', 'in:all,single,multiple'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*' => ['string', 'in:match,attention,attention_single_variation,delete_candidate_good,delete_candidate_variation,source_good_missing,source_variation_missing,source_variation_sku_mismatch,source_sku_other_supplier'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->variationSelection($snapshot, $data['search'] ?? null, $data['filters'] ?? [], $data['variation_count'] ?? 'all'),
        ]);
    }

    public function rollbackAction(SupplierCatalogSnapshot $snapshot, SupplierCatalogActionRun $actionRun): JsonResponse
    {
        abort_unless($actionRun->snapshot_id === $snapshot->id, 404);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->rollbackVariationAction($actionRun),
        ]);
    }

    public function imageAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:10'], 'search' => ['nullable', 'string', 'max:120']]);
        return response()->json(['success' => true, 'data' => $this->catalog->imageAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 10, $data['search'] ?? null)]);
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
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->propertyAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, $data['search'] ?? null),
        ]);
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
            'statuses' => ['nullable', 'array', 'max:4'],
            'statuses.*' => ['string', 'in:match,attention,not_found,missing_in_file'],
        ]);

        return response()->json(['success' => true, 'data' => $this->catalog->goodAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50, null, $data['search'] ?? null, $data['statuses'] ?? [])]);
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

        return response()->json(['success' => true, 'data' => $this->runCatalogAction(
            $request,
            $snapshot,
            'goods',
            $data['action'],
            $data['ids'],
            fn () => $this->catalog->applyGoodAction($snapshot, $data['action'], $data['ids']),
        )]);
    }

    public function applyMappedUpdate(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }
        $data = $request->validate([
            'scope' => ['required', 'in:goods,prices,properties,images'],
            'item_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'item_ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->runCatalogAction(
            $request,
            $snapshot,
            $data['scope'],
            'update',
            $data['item_ids'],
            fn () => $this->catalog->applyMappedUpdate($snapshot, $data['scope'], $data['item_ids']),
        );
        if ($data['scope'] === 'images' && $result['affected'] > 0) {
            SyncSupplierCatalogImagesJob::dispatch($snapshot->id, $data['item_ids']);
            $result['message'] .= '. Скачивание файлов запущено в очереди';
        }

        return response()->json(['success' => true, 'data' => $result]);
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
        ]);

        return response()->json(['success' => true, 'data' => $this->catalog->goodAudit(
            $snapshot,
            $data['page'] ?? 1,
            $data['per_page'] ?? 50,
            ['price', 'sale_price', 'demping_price', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
            $data['search'] ?? null,
        )]);
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
