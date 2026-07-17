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
use App\Models\ShopVariationAttribute;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonProductResolver;
use App\Services\Ozon\OzonSellerClient;
use App\Services\ProductNameParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

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

    public function warehouses()
    {
        try {
            $client = new OzonSellerClient($this->account());
            $warehouses = collect();
            $cursor = '';
            for ($page = 0; $page < 20; $page++) {
                $response = $client->post('/v2/warehouse/list', ['limit' => 100, 'cursor' => $cursor]);
                $rawItems = data_get($response, 'warehouses', data_get($response, 'result.warehouses', data_get($response, 'result', [])));
                $warehouses->push(...(is_array($rawItems) ? $rawItems : []));

                $hasNext = (bool) data_get($response, 'has_next', data_get($response, 'result.has_next', false));
                $nextCursor = (string) data_get($response, 'cursor', data_get($response, 'result.cursor', ''));
                if (! $hasNext || $nextCursor === '' || $nextCursor === $cursor) break;
                $cursor = $nextCursor;
            }

            $items = $warehouses->map(function ($item) {
                $id = data_get($item, 'warehouse_id', data_get($item, 'id'));
                if (! filled($id)) return null;
                return [
                    'id' => (string) $id,
                    'name' => (string) (data_get($item, 'name') ?: 'Склад '.$id),
                    'is_rfbs' => (bool) data_get($item, 'is_rfbs', false),
                    'status' => data_get($item, 'status'),
                ];
            })->filter()->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Throwable $e) {
            return $this->ozonRequestError($e);
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
        $items = \App\Models\ShopVariationAttribute::query()->orderBy('name')->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function localProperties()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Models\Shop\Property::query()->orderBy('name')->get(['id', 'name', 'property_type']),
        ]);
    }

    public function localPropertyValues(Request $request, \App\Models\Shop\Property $property)
    {
        $categoryIds = $this->validatedCategoryIds($request);
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => true, 'data' => []]);

        $query = DB::table('shop_good_properties as gp')
            ->join('shop_property_values as pv', 'pv.id', '=', 'gp.shop_property_value_id')
            ->join('shop_goods as goods', 'goods.id', '=', 'gp.good_id')
            ->join('shop_good_tags as tags', 'tags.good_id', '=', 'goods.id')
            ->where('gp.property_id', $property->id)
            ->where('pv.is_active', true)
            ->where('goods.is_active', true)
            ->where('tags.tag_id', $account->selection_tag_id)
            ->whereNotNull('gp.shop_property_value_id');
        $this->applyCategoryScope($query, $categoryIds, 'goods.id');

        $items = $query->groupBy('pv.id', 'pv.value')
            ->orderBy('pv.value')
            ->selectRaw('pv.id, pv.value, COUNT(DISTINCT goods.id) as count')
            ->get()
            ->map(fn ($item) => ['id' => (int) $item->id, 'value' => (string) $item->value, 'count' => (int) $item->count]);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function localVariationValues(Request $request, \App\Models\ShopVariationAttribute $attribute)
    {
        $categoryIds = $this->validatedCategoryIds($request);
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => true, 'data' => []]);

        $query = DB::table('shop_variation_attribute_values as av')
            ->join('shop_variation_attributes_values as links', 'links.attribute_value_id', '=', 'av.id')
            ->join('shop_good_variations as variations', 'variations.id', '=', 'links.variation_id')
            ->join('shop_goods as goods', 'goods.id', '=', 'variations.good_id')
            ->join('shop_good_tags as tags', 'tags.good_id', '=', 'goods.id')
            ->where('av.attribute_id', $attribute->id)
            ->where('av.is_active', true)
            ->where('variations.is_active', true)
            ->where('goods.is_active', true)
            ->where('tags.tag_id', $account->selection_tag_id);
        $this->applyCategoryScope($query, $categoryIds, 'goods.id');

        $items = $query->groupBy('av.id', 'av.value')
            ->orderBy('av.value')
            ->selectRaw('av.id, av.value, COUNT(DISTINCT variations.id) as count')
            ->get()
            ->map(fn ($item) => ['id' => (int) $item->id, 'value' => (string) $item->value, 'count' => (int) $item->count]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    private function validatedCategoryIds(Request $request): \Illuminate\Support\Collection
    {
        $data = $request->validate([
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|distinct|exists:shop_categories,id',
        ]);
        return collect($data['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
    }

    private function applyCategoryScope($query, \Illuminate\Support\Collection $categoryIds, string $goodColumn): void
    {
        if ($categoryIds->isEmpty()) return;
        $query->whereExists(function ($categoryQuery) use ($categoryIds, $goodColumn) {
            $categoryQuery->selectRaw('1')
                ->from('shop_good_categories as scoped_categories')
                ->whereColumn('scoped_categories.good_id', $goodColumn)
                ->whereIn('scoped_categories.category_id', $categoryIds);
        });
    }

    public function localSourceValues(Request $request, ProductNameParser $nameParser)
    {
        $data = $request->validate([
            'source' => 'required|in:brand,computed_type',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|distinct|exists:shop_categories,id',
        ]);
        $account = $this->account();

        if (! $account->selection_tag_id) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = ShopGood::query()
            ->select(['id', 'name'])
            ->with('brands:id,name')
            ->where('is_active', true)
            ->whereHas('tags', fn ($query) => $query->where('shop_tags.id', $account->selection_tag_id));

        $categoryIds = collect($data['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($categoryIds->isNotEmpty()) {
            $query->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $categoryIds));
        }

        $counts = [];
        $query->chunkById(500, function ($goods) use (&$counts, $data, $nameParser) {
            foreach ($goods as $good) {
                $value = $data['source'] === 'brand'
                    ? trim((string) $good->brands->first()?->name)
                    : trim($nameParser->typeForGood($good));
                if ($value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
        });
        uksort($counts, fn ($left, $right) => strnatcasecmp($left, $right));

        $items = collect($counts)->map(fn ($count, $value) => [
            'id' => (string) $value,
            'value' => (string) $value,
            'count' => (int) $count,
        ])->values();

        return response()->json(['success' => true, 'data' => $items]);
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

    public function resolveAttributeValues(Request $request)
    {
        try {
            $input = $request->validate([
                'description_category_id' => 'required|integer',
                'type_id' => 'required|integer',
                'attribute_id' => 'required|integer',
                'values' => 'required|array|max:200',
                'values.*' => 'required|string|max:500',
            ]);
            $account = $this->account();
            $client = new OzonSellerClient($account);
            $matches = [];
            $suggestions = [];

            foreach (collect($input['values'])->map(fn ($value) => trim((string) $value))->filter()->unique() as $value) {
                $cacheKey = 'ozon:'.$account->id.':attribute-value-search:'
                    .$input['description_category_id'].':'.$input['type_id'].':'.$input['attribute_id'].':'.hash('sha256', $this->normalizeDictionaryValue($value));
                $result = Cache::remember($cacheKey, now()->addHours(12), fn () => $client->post('/v1/description-category/attribute/values/search', [
                    'description_category_id' => (int) $input['description_category_id'],
                    'type_id' => (int) $input['type_id'],
                    'attribute_id' => (int) $input['attribute_id'],
                    'value' => $value,
                    'limit' => 20,
                ]));
                $items = collect(data_get($result, 'result', $result))->filter(fn ($item) => filled(data_get($item, 'id')) && filled(data_get($item, 'value')))->values();
                $exact = $items->first(fn ($item) => $this->normalizeDictionaryValue((string) data_get($item, 'value')) === $this->normalizeDictionaryValue($value));
                if ($exact) {
                    $matches[$value] = ['id' => (int) data_get($exact, 'id'), 'value' => (string) data_get($exact, 'value')];
                } elseif ($items->isNotEmpty()) {
                    $suggestions[$value] = $items->take(10)->map(fn ($item) => [
                        'id' => (int) data_get($item, 'id'),
                        'value' => (string) data_get($item, 'value'),
                    ])->all();
                }
            }

            return response()->json(['success' => true, 'data' => ['matches' => $matches, 'suggestions' => $suggestions]]);
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
            'variation_attribute_mappings.*.local_attribute_name' => 'nullable|string|max:255',
            'variation_attribute_mappings.*.ozon_attribute_id' => 'required|integer',
            'variation_attribute_mappings.*.ozon_attribute_name' => 'nullable|string|max:500',
            'variation_attribute_mappings.*.uses_dictionary' => 'nullable|boolean',
            'variation_attribute_mappings.*.dictionary_map' => 'nullable|array',
            'dimension_settings' => 'nullable|array',
            'dimension_settings.depth_multiplier' => 'nullable|numeric|min:0.000001|max:1000000',
            'dimension_settings.width_multiplier' => 'nullable|numeric|min:0.000001|max:1000000',
            'dimension_settings.height_multiplier' => 'nullable|numeric|min:0.000001|max:1000000',
            'dimension_settings.weight_multiplier' => 'nullable|numeric|min:0.000001|max:1000000',
            'price_adjustment' => 'nullable|array',
            'price_adjustment.operation' => 'nullable|in:add,subtract',
            'price_adjustment.type' => 'nullable|in:percent,absolute',
            'price_adjustment.value' => 'nullable|numeric|min:0|max:100000000',
            'is_active' => 'boolean',
        ]);
        $account = $this->account();
        $conflict = ShopOzonCategoryMapping::query()->where('account_id', $account->id)
            ->when($data['id'] ?? null, fn ($query, $id) => $query->where('id', '!=', $id))
            ->get()
            ->first(fn ($item) => collect($item->categoryIds())->intersect($data['category_ids'])->isNotEmpty());
        if ($conflict) {
            return response()->json(['success' => false, 'message' => 'Одна из выбранных категорий уже используется в другом профиле Ozon.'], 422);
        }

        $variationAttributeNames = ShopVariationAttribute::query()
            ->whereIn('id', collect($data['variation_attribute_mappings'] ?? [])->pluck('local_attribute_id'))
            ->pluck('name', 'id');
        $data['variation_attribute_mappings'] = collect($data['variation_attribute_mappings'] ?? [])->map(function (array $item) use ($variationAttributeNames) {
            $item['local_attribute_name'] = (string) ($variationAttributeNames[(int) $item['local_attribute_id']] ?? $item['local_attribute_name'] ?? '');
            $item['uses_dictionary'] = (bool) ($item['uses_dictionary'] ?? false);
            return $item;
        })->values()->all();

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
        $input = $request->validate([
            'good_ids' => 'nullable|array|max:50',
            'good_ids.*' => 'integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:50',
            'search' => 'nullable|string|max:255',
        ]);
        $ids = array_values(array_unique(array_map('intval', $input['good_ids'] ?? []))) ?: null;
        $page = $ids ? 1 : (int) ($input['page'] ?? 1);
        $perPage = $ids ? max(10, count($ids)) : (int) ($input['per_page'] ?? 10);
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => false, 'message' => 'Сначала выберите тег отбора Ozon в настройках.'], 422);
        $result = [];
        $mappings = $resolver->mappings($account);
        $query = $resolver->query($account, $ids)->orderByDesc('shop_goods.id');
        if (filled($input['search'] ?? null)) {
            $search = trim($input['search']);
            $numericId = ctype_digit($search) ? (int) $search : null;
            preg_match('/^g_(\d+)(?:_v_(\d+))?$/i', $search, $offerMatch);
            $generatedGoodId = isset($offerMatch[1]) ? (int) $offerMatch[1] : null;
            $generatedVariationId = isset($offerMatch[2]) ? (int) $offerMatch[2] : null;
            $query->where(function ($nested) use ($search, $numericId, $generatedGoodId, $generatedVariationId, $account) {
                $nested->where('shop_goods.name', 'like', "%{$search}%")
                    ->orWhere('shop_goods.sku', 'like', "%{$search}%")
                    ->when($numericId, fn ($item) => $item->orWhere('shop_goods.id', $numericId))
                    ->when($generatedGoodId, fn ($item) => $item->orWhere('shop_goods.id', $generatedGoodId))
                    ->orWhereHas('variations', function ($variations) use ($search, $numericId, $generatedVariationId) {
                        $variations->where('shop_good_variations.sku', 'like', "%{$search}%")
                            ->when($numericId, fn ($item) => $item->orWhere('shop_good_variations.id', $numericId))
                            ->when($generatedVariationId, fn ($item) => $item->orWhere('shop_good_variations.id', $generatedVariationId));
                    })
                    ->orWhereIn('shop_goods.id', ShopOzonProductBinding::query()
                        ->where('account_id', $account->id)
                        ->where(function ($bindings) use ($search) {
                            $bindings->where('offer_id', 'like', "%{$search}%")
                                ->orWhere('product_id', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        })
                        ->select('good_id'));
            });
        }
        $eligibleGoods = (clone $query)->count();
        $lastPage = max(1, (int) ceil($eligibleGoods / $perPage));
        $page = min($page, $lastPage);
        $goods = $query->forPage($page, $perPage)->get();
        foreach ($goods as $good) {
            $mapping = $resolver->mappingFor($good, $mappings);
            foreach ($resolver->rowsForGood($good, $mapping) as $row) {
                $built = $builder->build($good, $row['variation'], $account, $mapping);
                $result[] = array_merge([
                    'good_id' => $good->id,
                    'good_name' => $good->name,
                    'variation_id' => $row['variation']?->id,
                    'sku' => $row['variation']?->sku ?: $good->sku,
                    'stock' => $built['stock'],
                    'category_profile' => $mapping?->ozon_category_name,
                ], $built, ['errors' => array_values(array_unique(array_merge($built['errors'], $row['row_errors'])))]);
            }
        }
        return response()->json(['success' => true, 'data' => $result, 'meta' => [
            'scope' => $ids ? 'selected' : 'all',
            'requested_goods' => $ids ? count($ids) : null,
            'search' => $input['search'] ?? '',
            'eligible_goods' => $eligibleGoods,
            'shown_goods' => $goods->count(),
            'offers' => count($result),
            'offers_with_errors' => collect($result)->filter(fn ($row) => ! empty($row['errors']))->count(),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'from' => $eligibleGoods ? (($page - 1) * $perPage) + 1 : null,
            'to' => $eligibleGoods ? min($page * $perPage, $eligibleGoods) : null,
            'selection_diagnostics' => $ids ? $this->previewSelectionDiagnostics($ids, $account, $mappings, $resolver) : [],
        ]]);
    }

    public function startSync(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:products,prices,stocks', 'good_ids' => 'nullable|array', 'good_ids.*' => 'integer|exists:shop_goods,id']);
        $account = $this->account();
        if (! $account->selection_tag_id) return response()->json(['success' => false, 'message' => 'Выберите тег отбора товаров Ozon.'], 422);
        if (in_array($data['mode'], ['products', 'stocks'], true) && ! $account->warehouse_id) {
            return response()->json(['success' => false, 'message' => 'Укажите Warehouse ID во вкладке «Подключение и отбор». Без него Ozon не примет остатки.'], 422);
        }
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
            ->with([
                'good:id,name,sku,is_active',
                'variation:id,good_id,name,sku,is_active,price,sale_price,stock_quantity',
                'variation.attributeValues.attribute:id,name',
            ])
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
                    'sku' => $this->ozonSku($remote) ?? $binding->sku,
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

    private function ozonSku(mixed $remote): ?int
    {
        foreach (['sku', 'fbs_sku', 'fbo_sku', 'sources.0.sku'] as $path) {
            $value = data_get($remote, $path);
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }

        foreach ((array) data_get($remote, 'sources', []) as $source) {
            $value = data_get($source, 'sku');
            if (is_numeric($value) && (int) $value > 0) return (int) $value;
        }

        return null;
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

    private function normalizeDictionaryValue(string $value): string
    {
        $value = str_replace('ё', 'е', mb_strtolower(trim($value)));
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }

    private function previewSelectionDiagnostics(array $ids, ShopOzonAccount $account, Collection $mappings, OzonProductResolver $resolver): array
    {
        $goods = ShopGood::query()
            ->with(['tags:id,name', 'categories:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $selectionTag = ShopTag::query()->find($account->selection_tag_id, ['id', 'name']);

        return collect($ids)->map(function (int $id) use ($goods, $selectionTag, $mappings, $resolver) {
            $good = $goods->get($id);
            if (! $good) {
                return ['good_id' => $id, 'found' => false, 'eligible' => false, 'reasons' => ['Товар с таким ID не найден.']];
            }

            $hasTag = $good->tags->contains(fn ($tag) => (int) $tag->id === (int) $selectionTag?->id);
            $mapping = $resolver->mappingFor($good, $mappings);
            $reasons = [];
            if (! $good->is_active) $reasons[] = 'Товар выключен.';
            if (! $hasTag) $reasons[] = 'Нет сохранённого тега отбора «'.($selectionTag?->name ?? 'ID '.$selectionTag?->id).'».';
            if (! $mapping) $reasons[] = 'Ни одна категория товара не входит в активный профиль Ozon.';

            return [
                'good_id' => $good->id,
                'name' => $good->name,
                'found' => true,
                'is_active' => (bool) $good->is_active,
                'eligible' => $reasons === [],
                'expected_tag' => $selectionTag ? ['id' => $selectionTag->id, 'name' => $selectionTag->name] : null,
                'tags' => $good->tags->map->only(['id', 'name'])->values()->all(),
                'categories' => $good->categories->map->only(['id', 'name'])->values()->all(),
                'matching_profile' => $mapping ? ['id' => $mapping->id, 'name' => $mapping->ozon_category_name] : null,
                'reasons' => $reasons,
            ];
        })->values()->all();
    }
}
