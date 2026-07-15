<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use App\Models\ExportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportTemplatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_name' => 'nullable|string|max:100',
        ]);
        $entityName = $validated['entity_name'] ?? 'goods';

        $this->recoverTemplatesFromExportHistory($entityName);

        $templates = ExportTemplate::query()
            ->with('creator:id,name,first_name,last_name,email')
            ->where('entity_name', $entityName)
            ->latest('updated_at')
            ->get()
            ->map(fn (ExportTemplate $template) => $this->present($template, $request));

        return response()
            ->json(['data' => $templates])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTemplate($request);
        $template = ExportTemplate::create([
            'created_by' => $request->user()->id,
            'entity_name' => $validated['entity_name'],
            'name' => $validated['name'],
            'configuration' => $validated['configuration'],
        ]);

        $template->load('creator:id,name,first_name,last_name,email');

        return response()->json([
            'message' => 'Шаблон экспорта сохранен',
            'data' => $this->present($template, $request),
        ], 201);
    }

    public function update(Request $request, ExportTemplate $exportTemplate): JsonResponse
    {
        $this->authorizeManagement($request, $exportTemplate);
        $validated = $this->validateTemplate($request);

        $exportTemplate->update([
            'entity_name' => $validated['entity_name'],
            'name' => $validated['name'],
            'configuration' => $validated['configuration'],
        ]);
        $exportTemplate->load('creator:id,name,first_name,last_name,email');

        return response()->json([
            'message' => 'Шаблон экспорта обновлен',
            'data' => $this->present($exportTemplate, $request),
        ]);
    }

    public function destroy(Request $request, ExportTemplate $exportTemplate): JsonResponse
    {
        $this->authorizeManagement($request, $exportTemplate);
        $exportTemplate->delete();

        return response()->json(['message' => 'Шаблон экспорта удален']);
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'entity_name' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'configuration' => 'required|array',
        ]);
    }

    private function authorizeManagement(Request $request, ExportTemplate $template): void
    {
        $user = $request->user();
        abort_unless($template->created_by === $user->id, 403, 'Недостаточно прав для изменения чужого шаблона');
    }

    private function present(ExportTemplate $template, Request $request): array
    {
        $creator = $template->creator;
        $creatorName = trim(implode(' ', array_filter([
            $creator?->first_name,
            $creator?->last_name,
        ]))) ?: ($creator?->name ?: $creator?->email);

        return array_merge($template->configuration ?? [], [
            'id' => (string) $template->id,
            'name' => $template->name,
            'entityName' => $template->entity_name,
            'createdBy' => $template->created_by,
            'creatorName' => $creatorName,
            'canManage' => $template->created_by === $request->user()->id,
            'createdAt' => $template->created_at?->toISOString(),
            'updatedAt' => $template->updated_at?->toISOString(),
        ]);
    }

    private function recoverTemplatesFromExportHistory(string $entityName): void
    {
        $knownSources = ExportTemplate::query()
            ->where('entity_name', $entityName)
            ->get(['created_by', 'configuration'])
            ->flatMap(function (ExportTemplate $template) {
                $configuration = $template->configuration ?? [];
                $path = $template->configuration['templatePath'] ?? null;
                $fileName = $configuration['excelTemplateName'] ?? null;
                $keys = [];
                if ($path) {
                    $keys[$template->created_by.'|path|'.$path] = true;
                }
                if ($fileName) {
                    $keys[$template->created_by.'|file|'.$fileName] = true;
                }

                return $keys;
            });

        $exports = ExportFile::query()
            ->where('format', 'excel')
            ->whereNotNull('export_config')
            ->whereNotNull('export_config->template_path')
            ->latest('created_at')
            ->get(['id', 'created_by', 'export_config', 'created_at']);

        foreach ($exports as $export) {
            $config = $export->export_config ?? [];
            $templatePath = $config['template_path'] ?? null;
            if (! $templatePath || ! Storage::exists($templatePath)) {
                continue;
            }

            $templateFileName = $config['excel_template_name'] ?? basename($templatePath);
            $sourceKey = $export->created_by.'|path|'.$templatePath;
            $fileKey = $export->created_by.'|file|'.$templateFileName;
            if ($knownSources->has($sourceKey) || $knownSources->has($fileKey)) {
                continue;
            }

            $snapshot = is_array($config['export_template_snapshot'] ?? null)
                ? $config['export_template_snapshot']
                : $this->buildTemplateSnapshotFromExport($config);
            $snapshot['excelTemplateData'] = base64_encode(Storage::get($templatePath));
            $snapshot['excelTemplateName'] = $templateFileName;
            $snapshot['templatePath'] = $templatePath;
            $snapshot['recoveredFromExportId'] = $export->id;

            ExportTemplate::create([
                'created_by' => $export->created_by,
                'entity_name' => $entityName,
                'name' => $config['excel_template_display_name'] ?? $snapshot['excelTemplateName'],
                'configuration' => $snapshot,
                'created_at' => $export->created_at,
                'updated_at' => $export->created_at,
            ]);
            $knownSources->put($sourceKey, true);
            $knownSources->put($fileKey, true);
        }
    }

    private function buildTemplateSnapshotFromExport(array $config): array
    {
        $customFields = collect($config['custom_fields'] ?? [])->keyBy('id');
        $fieldLabels = $config['field_labels'] ?? [];
        $fields = $config['fields'] ?? [];
        $orderedItems = collect($fields)->map(function ($field) use ($customFields, $fieldLabels) {
            $isCustom = $customFields->has($field);

            return [
                'type' => $isCustom ? 'customField' : 'field',
                'value' => $field,
                'label' => $fieldLabels[$field] ?? $customFields->get($field)['label'] ?? $field,
            ];
        })->values()->all();

        return [
            'orderedItems' => $orderedItems,
            'fields' => collect($orderedItems)->where('type', 'field')->pluck('value')->values()->all(),
            'characteristics' => [],
            'variationAttributes' => [],
            'customFields' => array_values($config['custom_fields'] ?? []),
            'customFieldLabels' => [],
            'format' => $config['format'] ?? 'excel',
            'aggregateVariations' => (bool) ($config['aggregate_variations'] ?? false),
            'dimensionMultipliers' => $config['dimension_multipliers'] ?? [],
            'priceAdjustments' => $config['price_adjustments'] ?? [],
            'excelTemplateStartRow' => $config['excel_template_start_row'] ?? 2,
            'excelTemplateIncludeHeaders' => $config['excel_template_include_headers'] ?? true,
            'excelTemplateHeaderRow' => $config['excel_template_header_row'] ?? 0,
        ];
    }
}
