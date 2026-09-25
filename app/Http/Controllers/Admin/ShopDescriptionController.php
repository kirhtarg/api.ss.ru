<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopDescriptionDictionary;
use App\Models\ShopGood;
use App\Services\ShopDescriptionFormatterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopDescriptionController extends Controller
{
    public function show(Request $request, int $goodId, ShopDescriptionFormatterService $formatter): JsonResponse
    {
        $good = ShopGood::with('categories')->findOrFail($goodId);
        $result = $formatter->formatAndPersist($good, false);

        return response()->json(['success' => true, 'data' => [
            'source' => $good->description,
            'formatted_html' => $good->formatted_description_html,
            'status' => $good->description_format_status,
            'manual' => (bool) $good->description_format_manual,
            'hash' => $good->description_format_hash,
            'result' => $result,
        ]]);
    }

    public function regenerate(int $goodId, ShopDescriptionFormatterService $formatter): JsonResponse
    {
        $good = ShopGood::with('categories')->findOrFail($goodId);
        $result = $formatter->formatAndPersist($good, true);
        return response()->json(['success' => true, 'data' => [
            'source' => $good->description,
            'formatted_html' => $good->fresh()->formatted_description_html,
            'status' => $result['status'] ?? 'formatted',
            'manual' => false,
            'result' => $result,
        ]]);
    }

    public function update(Request $request, int $goodId): JsonResponse
    {
        $data = $request->validate([
            'formatted_html' => ['nullable', 'string'],
            'manual' => ['sometimes', 'boolean'],
        ]);
        $good = ShopGood::findOrFail($goodId);
        $good->forceFill([
            'formatted_description_html' => $data['formatted_html'] ?? null,
            'description_format_hash' => hash('sha256', trim((string) $good->description)),
            'description_format_version' => ShopDescriptionFormatterService::VERSION,
            'description_format_status' => 'formatted',
            'description_format_manual' => (bool) ($data['manual'] ?? true),
            'description_formatted_at' => now(),
        ])->saveQuietly();
        return response()->json(['success' => true, 'data' => $good->fresh()]);
    }

    public function dictionary(Request $request): JsonResponse
    {
        $query = ShopDescriptionDictionary::query()->with('category:id,name')->orderByDesc('priority')->orderBy('name');
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')->orWhereJsonContains('aliases', $search);
            });
        }
        return response()->json(['success' => true, 'data' => $query->paginate((int) $request->input('per_page', 100))]);
    }

    public function storeDictionary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:shop_categories,id'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return response()->json(['success' => true, 'data' => ShopDescriptionDictionary::create($data)], 201);
    }

    public function updateDictionary(Request $request, ShopDescriptionDictionary $dictionary): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:shop_categories,id'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $dictionary->update($data);
        return response()->json(['success' => true, 'data' => $dictionary->fresh()]);
    }

    public function destroyDictionary(ShopDescriptionDictionary $dictionary): JsonResponse
    {
        $dictionary->delete();
        return response()->json(['success' => true]);
    }
}
