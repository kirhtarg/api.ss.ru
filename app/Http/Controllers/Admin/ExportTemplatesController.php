<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportTemplatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_name' => 'nullable|string|max:100',
        ]);

        $query = ExportTemplate::query()
            ->with('creator:id,name,first_name,last_name,email')
            ->where('entity_name', $validated['entity_name'] ?? 'goods');

        if (! $request->user()->hasRole('admin')) {
            $query->where('created_by', $request->user()->id);
        }

        $templates = $query
            ->latest('updated_at')
            ->get()
            ->map(fn (ExportTemplate $template) => $this->present($template, $request));

        return response()->json(['data' => $templates]);
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
}
