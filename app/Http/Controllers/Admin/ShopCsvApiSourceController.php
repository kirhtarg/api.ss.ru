<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopCsvApiSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopCsvApiSourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = ShopCsvApiSource::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ShopCsvApiSource $source) => $this->summary($source));

        return response()->json([
            'success' => true,
            'data' => $sources,
        ]);
    }

    public function show(ShopCsvApiSource $source): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                ...$this->summary($source),
                'password' => $source->password,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $source = ShopCsvApiSource::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'API-источник CSV сохранён.',
            'data' => $this->summary($source),
        ], 201);
    }

    public function update(Request $request, ShopCsvApiSource $source): JsonResponse
    {
        $validated = $request->validate($this->rules($source));
        if (! isset($validated['password']) || $validated['password'] === '') {
            unset($validated['password']);
        }

        $source->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'API-источник CSV обновлён.',
            'data' => $this->summary($source->refresh()),
        ]);
    }

    public function destroy(ShopCsvApiSource $source): JsonResponse
    {
        $source->delete();

        return response()->json([
            'success' => true,
            'message' => 'API-источник CSV удалён.',
        ]);
    }

    private function rules(?ShopCsvApiSource $source = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('shop_csv_api_sources', 'name')->ignore($source?->id),
            ],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$source ? 'nullable' : 'required', 'string', 'max:1000'],
            'url' => ['required', 'url:https', 'max:2048'],
        ];
    }

    private function summary(ShopCsvApiSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'username' => $source->username,
            'url' => $source->url,
            'has_password' => filled($source->getRawOriginal('password')),
            'updated_at' => $source->updated_at,
        ];
    }
}
