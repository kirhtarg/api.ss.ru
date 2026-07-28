<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\ShopVariationAttribute;
use App\Models\SupplierCatalogFieldMapping;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierCatalogSnapshot;
use App\Jobs\ProcessSupplierCatalogExcelJob;
use App\Services\BikeproductsCatalogService;
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
                ->limit(30)
                ->get(),
        ]);
    }

    public function uploadExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:122880'],
            'supplier_code' => ['required', 'string', 'max:80'],
        ]);

        try {
            $supplierCode = $this->selectedSupplierCode($request->input('supplier_code'));
            $snapshot = $this->catalog->queueExcelImport($request->file('file'), $supplierCode);
            // Возвращаем 202 до тяжёлого разбора даже на сервере с временно синхронной очередью.
            ProcessSupplierCatalogExcelJob::dispatchAfterResponse($snapshot->id);

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
        $mappings = SupplierCatalogFieldMapping::query()
            ->where('supplier_code', $snapshot->supplier_code)
            ->get()
            ->keyBy(fn (SupplierCatalogFieldMapping $mapping) => $mapping->source_field.'|'.$mapping->scope);
        $fields = collect($snapshot->summary['fields'] ?? [])->map(function (array $stats, string $sourceField) use ($mappings) {
            $variationMapping = $mappings->get($sourceField.'|variation');
            $propertyMapping = $mappings->get($sourceField.'|product');

            return [
                'source_field' => $sourceField,
                ...$stats,
                'variation_mapping' => $this->mappingPayload($variationMapping),
                'property_mapping' => $this->mappingPayload($propertyMapping),
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
            'scope' => ['required', 'in:variation,product'],
            'variation_attribute_id' => ['nullable', 'integer', 'exists:shop_variation_attributes,id'],
            'property_id' => ['nullable', 'integer', 'exists:shop_properties,id'],
            'display_field' => ['nullable', 'string', 'max:120'],
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
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->variationAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50),
        ]);
    }

    public function imageAudit(SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        return response()->json(['success' => true, 'data' => $this->catalog->imageAudit($snapshot)]);
    }

    public function propertyAudit(Request $request, SupplierCatalogSnapshot $snapshot): JsonResponse
    {
        if ($response = $this->notReadyResponse($snapshot)) {
            return $response;
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->catalog->propertyAudit($snapshot, $data['page'] ?? 1, $data['per_page'] ?? 50),
        ]);
    }

    private function mappingPayload(?SupplierCatalogFieldMapping $mapping): ?array
    {
        return $mapping ? [
            'id' => $mapping->id,
            'scope' => $mapping->scope,
            'variation_attribute_id' => $mapping->variation_attribute_id,
            'property_id' => $mapping->property_id,
            'display_field' => $mapping->display_field,
            'is_check_enabled' => $mapping->is_check_enabled,
            'is_update_enabled' => $mapping->is_update_enabled,
        ] : null;
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
