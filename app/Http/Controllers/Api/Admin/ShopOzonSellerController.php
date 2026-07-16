<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOzonSyncRunJob;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use App\Models\ShopOzonProductBinding;
use App\Models\ShopOzonSyncRun;
use App\Models\ShopTag;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShopOzonSellerController extends Controller
{
    public function settings()
    {
        $account = ShopOzonAccount::query()->first();
        return response()->json(['success' => true, 'data' => $account ? array_merge($account->toArray(), ['has_api_key' => filled($account->api_key)]) : null]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'client_id' => 'required|string|max:255', 'api_key' => 'nullable|string|max:2000',
            'replace_api_key' => 'nullable|boolean',
            'api_url' => 'required|url|max:500', 'warehouse_id' => 'nullable|string|max:255', 'image_base_url' => 'required|url|max:500',
            'selection_tag_id' => 'nullable|exists:shop_tags,id', 'vat' => 'required|string|max:20', 'is_active' => 'boolean',
        ]);
        $data['client_id'] = trim($data['client_id']);
        $account = ShopOzonAccount::query()->first() ?? new ShopOzonAccount;
        $replaceApiKey = (bool) ($data['replace_api_key'] ?? false) || ! $account->exists;
        unset($data['replace_api_key']);

        if ($replaceApiKey) {
            $data['api_key'] = $this->normalizeApiKeyInput((string) ($data['api_key'] ?? ''));
            if ($data['api_key'] === '') {
                return response()->json(['success' => false, 'message' => 'Для замены укажите новый API-ключ Ozon.'], 422);
            }

            $candidate = $account->exists ? $account->replicate() : new ShopOzonAccount;
            $candidate->fill($data);
            try {
                (new OzonSellerClient($candidate))->post('/v4/product/info/limit');
            } catch (\Throwable $e) {
                $apiKey = (string) $candidate->api_key;
                return response()->json([
                    'success' => false,
                    'message' => 'Новый API-ключ не сохранён: Ozon отклонил проверку. '.$e->getMessage(),
                    'code' => 'OZON_API_KEY_VALIDATION_FAILED',
                    'diagnostics' => [
                        'client_id' => (string) $candidate->client_id,
                        'api_key_length' => strlen($apiKey),
                        'api_key_fingerprint' => substr(hash('sha256', $apiKey), 0, 12),
                    ],
                ], 422);
            }

            $data['last_error'] = null;
            $data['last_connection_at'] = now();
        } else {
            unset($data['api_key']);
        }

        $account->fill($data)->save();
        return response()->json(['success' => true, 'message' => 'Настройки сохранены.', 'data' => array_merge($account->toArray(), ['has_api_key' => true])]);
    }

    public function testConnection()
    {
        $account = $this->account();
        try {
            $result = (new OzonSellerClient($account))->post('/v4/product/info/limit');
            $account->update(['last_connection_at' => now(), 'last_error' => null]);
            return response()->json(['success' => true, 'message' => 'Подключение к Ozon установлено.', 'data' => $result]);
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);
            $apiKey = trim((string) $account->api_key);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'diagnostics' => [
                    'client_id' => trim((string) $account->client_id),
                    'api_url' => rtrim((string) $account->api_url, '/'),
                    'api_key_length' => strlen($apiKey),
                    'api_key_fingerprint' => $apiKey !== '' ? substr(hash('sha256', $apiKey), 0, 12) : null,
                ],
            ], 422);
        }
    }

    public function localCategories()
    {
        $items = ShopCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function localTags()
    {
        return response()->json([
            'success' => true,
            'data' => ShopTag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function variationAttributes()
    {
        $items = \App\Models\ShopVariationAttribute::query()
            ->with(['values' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('value')])
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function localProperties()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Models\Shop\Property::query()->orderBy('name')->get(['id', 'name', 'property_type']),
        ]);
    }

    public function localPropertyValues(\App\Models\Shop\Property $property)
    {
        return response()->json([
            'success' => true,
            'data' => $property->values()->where('is_active', true)->orderBy('sort_order')->orderBy('value')->get(['id', 'value']),
        ]);
    }

    public function ozonCategories(Request $request)
    {
        try {
            $language = $request->string('language', 'DEFAULT')->toString();
            $key = "ozon:{$this->account()->id}:category-tree:{$language}";
            if ($request->boolean('refresh')) Cache::forget($key);
            $data = Cache::remember($key, now()->addHours(12), fn () => (new OzonSellerClient($this->account()))->post('/v1/description-category/tree', ['language' => $language]));
            return response()->json(['success' => true, 'data' => data_get($data, 'result', $data)]);
        } catch (\Throwable $e) {
            return $this->ozonRequestError($e);
        }
    }

    public function attributes(Request $request)
    {
        try {
            $input = $request->validate(['description_category_id' => 'required|integer', 'type_id' => 'required|integer', 'language' => 'nullable|string']);
            $key = "ozon:{$this->account()->id}:attributes:{$input['description_category_id']}:{$input['type_id']}";
            $data = Cache::remember($key, now()->addHours(12), fn () => (new OzonSellerClient($this->account()))->post('/v1/description-category/attribute', [
                'description_category_id' => (int) $input['description_category_id'], 'type_id' => (int) $input['type_id'], 'language' => $input['language'] ?? 'DEFAULT',
            ]));
            return response()->json(['success' => true, 'data' => data_get($data, 'result', $data)]);
        } catch (\Throwable $e) {
            return $this->ozonRequestError($e);
        }
    }

    public function attributeValues(Request $request)
    {
        try {
            $input = $request->validate(['description_category_id' => 'required|integer', 'type_id' => 'required|integer', 'attribute_id' => 'required|integer', 'last_value_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:5000']);
            $account = $this->account();
            $lastValueId = (int) ($input['last_value_id'] ?? 0);
            $limit = (int) ($input['limit'] ?? 1000);
            $key = "ozon:{$account->id}:attribute-values:{$input['description_category_id']}:{$input['type_id']}:{$input['attribute_id']}:{$lastValueId}:{$limit}";
            $data = Cache::remember($key, now()->addHours(12), fn () => (new OzonSellerClient($account))->post('/v1/description-category/attribute/values', [
                'description_category_id' => (int) $input['description_category_id'],
                'type_id' => (int) $input['type_id'],
                'attribute_id' => (int) $input['attribute_id'],
                'language' => 'DEFAULT',
                'last_value_id' => $lastValueId,
                'limit' => $limit,
            ]));

            return response()->json(['success' => true, 'data' => [
                'items' => data_get($data, 'result', []),
                'has_next' => (bool) data_get($data, 'has_next', false),
            ]]);
        } catch (\Throwable $e) {
            return $this->ozonRequestError($e);
        }
    }

    public function mappings()
    {
        $account = ShopOzonAccount::query()->first();
        if (! $account) return response()->json(['success' => true, 'data' => []]);
        $items = ShopOzonCategoryMapping::query()->where('account_id', $account->id)->with('category:id,name')->orderByDesc('id')->get();
        $categoryIds = $items->flatMap(fn ($item) => $item->categoryIds())->unique()->values();
        $categoryNames = ShopCategory::query()->whereIn('id', $categoryIds)->pluck('name', 'id');
        $categoryCounts = collect();
        if ($account->selection_tag_id && $categoryIds->isNotEmpty()) {
            $categoryCounts = DB::table('shop_good_categories as categories')
                ->join('shop_goods as goods', 'goods.id', '=', 'categories.good_id')
                ->join('shop_good_tags as tags', 'tags.good_id', '=', 'goods.id')
                ->where('goods.is_active', true)
                ->where('tags.tag_id', $account->selection_tag_id)
                ->whereIn('categories.category_id', $categoryIds)
                ->groupBy('categories.category_id')
                ->selectRaw('categories.category_id, COUNT(DISTINCT goods.id) as goods_count')
                ->pluck('goods_count', 'category_id');
        }
        $items->each(function ($item) use ($account, $categoryNames, $categoryCounts) {
            $ids = collect($item->categoryIds())->map(fn ($id) => (int) $id)->values();
            $item->setAttribute('categories', $ids->map(fn ($id) => [
                'id' => $id,
                'name' => $categoryNames[$id] ?? "ID {$id}",
                'tagged_goods_count' => (int) ($categoryCounts[$id] ?? 0),
            ])->all());
            $item->setAttribute('tagged_goods_count', $account->selection_tag_id ? ShopGood::query()
                ->where('is_active', true)
                ->whereHas('tags', fn ($query) => $query->where('shop_tags.id', $account->selection_tag_id))
                ->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $ids))
                ->count() : 0);
        });
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer', 'category_ids' => 'required|array|min:1', 'category_ids.*' => 'integer|distinct|exists:shop_categories,id',
            'description_category_id' => 'required|integer', 'type_id' => 'required|integer', 'ozon_category_name' => 'nullable|string|max:500',
            'variation_mode' => 'required|in:grouped,independent,single', 'group_attribute_id' => 'nullable|integer',
            'attribute_mappings' => 'nullable|array', 'variation_attribute_mappings' => 'nullable|array|max:20',
            'variation_attribute_mappings.*.local_attribute_id' => 'required|integer|exists:shop_variation_attributes,id',
            'variation_attribute_mappings.*.ozon_attribute_id' => 'required|integer',
            'variation_attribute_mappings.*.dictionary_map' => 'nullable|array', 'is_active' => 'boolean',
        ]);
        $account = $this->account();
        $conflict = ShopOzonCategoryMapping::query()->where('account_id', $account->id)
            ->when($data['id'] ?? null, fn ($query, $id) => $query->where('id', '!=', $id))
            ->get()
            ->first(fn ($item) => collect($item->categoryIds())->intersect($data['category_ids'])->isNotEmpty());
        if ($conflict) {
            return response()->json(['success' => false, 'message' => 'Одна из выбранных категорий уже используется в другом профиле Ozon.'], 422);
        }

        $payload = array_merge($data, [
            'account_id' => $account->id,
            'category_id' => (int) $data['category_ids'][0],
            'group_attribute_id' => $data['variation_mode'] === 'grouped' ? ($data['group_attribute_id'] ?? null) : null,
        ]);
        $mapping = isset($data['id']) ? ShopOzonCategoryMapping::where('account_id', $account->id)->findOrFail($data['id']) : new ShopOzonCategoryMapping;
        $mapping->fill($payload)->save();
        return response()->json(['success' => true, 'message' => 'Профиль Ozon сохранён.', 'data' => $mapping->load('category:id,name')]);
    }

    public function deleteMapping(ShopOzonCategoryMapping $mapping)
    {
        abort_unless($mapping->account_id === $this->account()->id, 404);
        $mapping->delete();
        return response()->json(['success' => true]);
    }

    public function preview(Request $request, OzonProductPayloadBuilder $builder, OzonProductResolver $resolver)
    {
        $input = $request->validate(['good_ids' => 'nullable|array|max:50', 'good_ids.*' => 'integer']);
        $ids = array_values(array_unique(array_map('intval', $input['good_ids'] ?? []))) ?: null;
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => false, 'message' => 'Сначала выберите тег отбора Ozon в настройках.'], 422);
        $result = [];
        $query = $resolver->query($account, $ids);
        $eligibleGoods = (clone $query)->count();
        $goods = $query->limit(50)->get();
        $mappings = $resolver->mappings($account);
        foreach ($goods as $good) {
            $mapping = $resolver->mappingFor($good, $mappings);
            foreach ($resolver->rowsForGood($good, $mapping) as $row) {
                $built = $builder->build($good, $row['variation'], $account, $mapping);
                $result[] = array_merge([
                    'good_id' => $good->id,
                    'variation_id' => $row['variation']?->id,
                    'sku' => $row['variation']?->sku ?: $good->sku,
                    'stock' => (int) ($row['variation']?->stock_quantity ?? $good->stock_quantity),
                    'category_profile' => $mapping?->ozon_category_name,
                ], $built, ['errors' => array_values(array_unique(array_merge($built['errors'], $row['row_errors'])))]);
            }
        }
        return response()->json(['success' => true, 'data' => $result, 'meta' => [
            'scope' => $ids ? 'selected' : 'all',
            'requested_goods' => $ids ? count($ids) : null,
            'eligible_goods' => $eligibleGoods,
            'shown_goods' => $goods->count(),
            'offers' => count($result),
            'offers_with_errors' => collect($result)->filter(fn ($row) => ! empty($row['errors']))->count(),
            'truncated' => $eligibleGoods > $goods->count(),
        ]]);
    }

    public function startSync(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:products,prices,stocks', 'good_ids' => 'nullable|array', 'good_ids.*' => 'integer|exists:shop_goods,id']);
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => false, 'message' => 'Выберите тег отбора товаров Ozon.'], 422);
        $run = ShopOzonSyncRun::create(['account_id' => $account->id, 'user_id' => $request->user()?->id, 'mode' => $data['mode'], 'good_ids' => $data['good_ids'] ?? null, 'status' => 'pending']);
        ProcessOzonSyncRunJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Синхронизация поставлена в очередь.', 'data' => $run], 202);
    }

    public function products(Request $request)
    {
        $input = $request->validate([
            'status' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        $query = ShopOzonProductBinding::query()
            ->where('account_id', $this->account()->id)
            ->with(['good:id,name,sku,is_active', 'variation:id,good_id,name,sku,is_active,price,sale_price,stock_quantity'])
            ->latest('id');
        if (filled($input['status'] ?? null)) $query->where('status', $input['status']);
        if (filled($input['search'] ?? null)) {
            $search = trim($input['search']);
            $query->where(function ($nested) use ($search) {
                $nested->where('offer_id', 'like', "%{$search}%")
                    ->orWhereHas('good', fn ($goodQuery) => $goodQuery->where('name', 'like', "%{$search}%"));
            });
        }
        return response()->json(['success' => true, 'data' => $query->paginate($input['per_page'] ?? 50)]);
    }

    public function refreshProducts(Request $request)
    {
        $input = $request->validate(['binding_ids' => 'nullable|array|max:1000', 'binding_ids.*' => 'integer']);
        $bindings = ShopOzonProductBinding::query()
            ->where('account_id', $this->account()->id)
            ->when($input['binding_ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->latest('id')
            ->limit(1000)
            ->get();
        $client = new OzonSellerClient($this->account());
        $updated = 0;
        foreach ($bindings->chunk(100) as $chunk) {
            $response = $client->post('/v3/product/info/list', ['offer_id' => $chunk->pluck('offer_id')->values()->all()]);
            $remoteItems = collect(data_get($response, 'items', data_get($response, 'result.items', [])));
            foreach ($chunk as $binding) {
                $remote = $remoteItems->first(fn ($item) => (string) data_get($item, 'offer_id') === $binding->offer_id);
                if (! $remote) continue;
                $binding->update([
                    'product_id' => data_get($remote, 'id', data_get($remote, 'product_id', $binding->product_id)),
                    'sku' => data_get($remote, 'sources.0.sku', data_get($remote, 'sku', $binding->sku)),
                    'status' => (string) (data_get($remote, 'statuses.status') ?: data_get($remote, 'status') ?: $binding->status),
                    'remote_payload' => $remote,
                    'remote_updated_at' => now(),
                ]);
                $updated++;
            }
        }
        return response()->json(['success' => true, 'message' => "Обновлено карточек Ozon: {$updated}.", 'data' => ['updated' => $updated]]);
    }

    public function zeroBindingStock(ShopOzonProductBinding $binding)
    {
        $account = $this->account();
        abort_unless($binding->account_id === $account->id, 404);
        if (! $account->warehouse_id) {
            return response()->json(['success' => false, 'message' => 'В настройках не указан Warehouse ID.'], 422);
        }
        $response = (new OzonSellerClient($account))->post('/v2/products/stocks', ['stocks' => [[
            'offer_id' => $binding->offer_id,
            'stock' => 0,
            'warehouse_id' => (string) $account->warehouse_id,
        ]]]);
        $errors = collect(data_get($response, 'result', data_get($response, 'items', [])))
            ->flatMap(fn ($item) => data_get($item, 'errors', []))->filter()->values()->all();
        $binding->update(['status' => $errors ? 'error' : 'stock_zeroed', 'errors' => $errors ?: null, 'last_synced_at' => now()]);
        return response()->json(['success' => empty($errors), 'message' => $errors ? 'Ozon отклонил обнуление остатка.' : 'Остаток оффера обнулён.', 'data' => $response], $errors ? 422 : 200);
    }

    public function runs()
    {
        $account = ShopOzonAccount::query()->first();
        if (! $account) return response()->json(['success' => true, 'data' => []]);
        return response()->json(['success' => true, 'data' => ShopOzonSyncRun::where('account_id', $account->id)->latest()->limit(30)->get()]);
    }

    public function run(ShopOzonSyncRun $run)
    {
        abort_unless($run->account_id === $this->account()->id, 404);
        return response()->json(['success' => true, 'data' => $run->load(['items' => fn ($q) => $q->latest()->limit(200)])]);
    }

    private function account(): ShopOzonAccount
    {
        return ShopOzonAccount::query()->firstOrFail();
    }

    private function ozonRequestError(\Throwable $e)
    {
        $account = ShopOzonAccount::query()->first();
        $account?->update(['last_error' => mb_substr($e->getMessage(), 0, 4000)]);
        $invalidKey = str_contains(mb_strtolower($e->getMessage()), 'invalid api-key');
        $apiKey = trim((string) $account?->api_key);

        return response()->json([
            'success' => false,
            'message' => $invalidKey
                ? 'Ozon отклонил сохранённый API-ключ. Повторно укажите ключ для текущего Client-Id, сохраните настройки и нажмите «Проверить подключение».'
                : $e->getMessage(),
            'error' => $e->getMessage(),
            'code' => $invalidKey ? 'OZON_INVALID_API_KEY' : 'OZON_REQUEST_FAILED',
            'diagnostics' => [
                'client_id' => trim((string) $account?->client_id),
                'api_key_length' => strlen($apiKey),
                'api_key_fingerprint' => $apiKey !== '' ? substr(hash('sha256', $apiKey), 0, 12) : null,
            ],
        ], 422);
    }

    private function normalizeApiKeyInput(string $value): string
    {
        $value = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $value) ?? trim($value);
        $value = preg_replace('/^(?:api-key|authorization)\s*:\s*/i', '', $value) ?? $value;
        if (str_starts_with(mb_strtolower($value), 'bearer ')) $value = mb_substr($value, 7);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = trim(substr($value, 1, -1));
        }
        return $value;
    }
}
