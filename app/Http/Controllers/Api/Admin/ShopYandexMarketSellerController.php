<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessYandexMarketSellerSyncJob;
use App\Models\Setting;
use App\Models\ShopCategory;
use App\Models\ShopTag;
use App\Models\ShopVariationAttribute;
use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketAttributeTemplate;
use App\Models\ShopYandexMarketCategoryMapping;
use App\Models\ShopYandexMarketProductBinding;
use App\Models\ShopYandexMarketSyncItem;
use App\Models\ShopYandexMarketSyncRun;
use App\Services\YandexMarket\YandexMarketClient;
use App\Services\YandexMarket\YandexMarketPayloadBuilder;
use App\Services\YandexMarket\YandexMarketProductResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopYandexMarketSellerController extends Controller
{
    public function settings()
    {
        $account = ShopYandexMarketAccount::query()->first();
        return response()->json(['success' => true, 'data' => $account ? $this->accountData($account) : null]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255', 'api_key' => 'nullable|string|max:5000',
            'api_url' => 'nullable|url|max:255', 'business_id' => 'nullable|integer|min:1',
            'campaign_id' => 'nullable|integer|min:1', 'selection_tag_id' => 'nullable|integer|exists:shop_tags,id',
            'image_base_url' => 'nullable|url|max:255', 'is_active' => 'nullable|boolean',
        ]);
        $account = ShopYandexMarketAccount::query()->first() ?: new ShopYandexMarketAccount();
        if (blank($data['api_key'] ?? null)) unset($data['api_key']);
        if (! $account->exists && ! isset($data['api_key'])) {
            return response()->json(['success' => false, 'message' => 'Для нового подключения укажите API-ключ Яндекс Маркета.'], 422);
        }
        $account->fill(array_merge([
            'name' => 'Основной кабинет', 'api_url' => 'https://api.partner.market.yandex.ru',
            'image_base_url' => 'https://skateandsnow.ru', 'is_active' => true,
        ], $data))->save();
        $this->syncLegacySettings($account);
        return response()->json(['success' => true, 'message' => 'Настройки сохранены.', 'data' => $this->accountData($account)]);
    }

    public function testConnection()
    {
        $account = $this->account();
        try {
            $client = new YandexMarketClient($account);
            $tokenInfo = $client->tokenInfo();
            $campaigns = $client->campaigns();
            if (! $campaigns) throw new \RuntimeException('Ключ действителен, но для него не найдено доступных магазинов.');
            $normalized = collect($campaigns)->map(fn ($item) => $this->campaignData((array) $item))->values()->all();
            $selected = collect($normalized)->firstWhere('id', (int) $account->campaign_id) ?: $normalized[0];
            $account->update([
                'business_id' => $selected['business_id'] ?: $account->business_id,
                'campaign_id' => $selected['id'], 'campaigns' => $normalized,
                'capabilities' => $this->capabilities((array) ($tokenInfo['authScopes'] ?? []), $selected),
                'last_connection_at' => now(), 'last_error' => null,
            ]);
            $this->syncLegacySettings($account->refresh());
            return response()->json(['success' => true, 'message' => 'Подключение к Яндекс Маркету работает.', 'data' => $this->accountData($account)]);
        } catch (Throwable $e) {
            $originalMessage = $e->getMessage();
            $allCampaignsDisabled = str_contains($originalMessage, 'disabled because it has only disabled partners');
            $message = $allCampaignsDisabled
                ? 'API-ключ распознан, но API отключен для всех магазинов этого кабинета. Включите API хотя бы для одного магазина в настройках Яндекс Маркета.'
                : $originalMessage;
            $account->update(['last_error' => mb_substr($message, 0, 4000)]);
            preg_match('/business\s+(\d+)/i', $originalMessage, $businessMatch);

            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => $allCampaignsDisabled ? 'all_campaigns_disabled' : 'connection_failed',
                'data' => $this->accountData($account->refresh()),
                'help' => $allCampaignsDisabled ? [
                    'Откройте кабинет продавца Яндекс Маркета → Настройки → API и модули.',
                    'Включите интеграцию API хотя бы для одного магазина кабинета.',
                    'Если включение недоступно, проверьте активный договор, юридические данные и подключенную программу размещения.',
                    'Подождите до одной минуты и повторите проверку подключения.',
                ] : null,
                'diagnostics' => [
                'api_url' => $account->api_url, 'api_key_length' => mb_strlen(trim((string) $account->api_key)),
                'api_key_fingerprint' => substr(hash('sha256', trim((string) $account->api_key)), 0, 12),
                'business_id' => isset($businessMatch[1]) ? (int) $businessMatch[1] : $account->business_id,
                'original_message' => $allCampaignsDisabled ? $originalMessage : null,
            ]], 422);
        }
    }

    public function campaigns()
    {
        $campaigns = (new YandexMarketClient($this->account()))->campaigns();
        return response()->json(['success' => true, 'data' => collect($campaigns)->map(fn ($item) => $this->campaignData((array) $item))->values()]);
    }

    public function localCategories()
    {
        return response()->json(['success' => true, 'data' => ShopCategory::query()->where('is_active', true)
            ->select('id', 'name', 'parent_id')->orderBy('name')->get()]);
    }

    public function localTags()
    {
        return response()->json(['success' => true, 'data' => ShopTag::query()->where('is_active', true)->select('id', 'name')->orderBy('name')->get()]);
    }

    public function localSources()
    {
        $properties = \App\Models\Shop\Property::query()->select('id', 'name')->orderBy('name')->get();
        $variations = ShopVariationAttribute::query()->select('id', 'name')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => ['properties' => $properties, 'variations' => $variations]]);
    }

    public function localSourceValues(Request $request, YandexMarketProductResolver $resolver)
    {
        $data = $request->validate([
            'source' => 'required|string|in:brand,property,variation_attribute',
            'source_key' => 'nullable|integer|min:1', 'selection_tag_id' => 'nullable|integer|exists:shop_tags,id',
            'category_ids' => 'required|array|min:1', 'category_ids.*' => 'integer',
            'search' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:100',
            'mapped_values' => 'nullable|array', 'mapped_values.*' => 'string|max:1000',
        ]);
        $account = $this->account();
        $tagId = (int) ($data['selection_tag_id'] ?? $account->selection_tag_id);
        $mapping = ['source' => $data['source'], 'source_key' => $data['source_key'] ?? null];
        $cacheKey = 'yandex-market:local-source-values:'.hash('sha256', json_encode([$account->id, $mapping, $data['category_ids'], $tagId]));
        $counts = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($data, $tagId, $resolver, $mapping) {
            $query = \App\Models\ShopGood::query()->with(['brands:id,name', 'properties.values', 'variations.attributeValues.attribute'])
                ->where('is_active', true)
                ->whereHas('categories', fn ($q) => $q->whereIn('shop_categories.id', $data['category_ids']));
            if ($tagId) $query->whereHas('tags', fn ($q) => $q->where('shop_tags.id', $tagId));
            $counts = [];
            $query->chunkById(200, function ($goods) use (&$counts, $resolver, $mapping) {
                foreach ($goods as $good) {
                    $variations = $mapping['source'] === 'variation_attribute' ? $good->variations->where('is_active', true) : collect([null]);
                    foreach ($variations as $variation) {
                        $value = trim((string) $resolver->sourceValue($mapping, $good, $variation));
                        if ($value !== '') $counts[$value] = ($counts[$value] ?? 0) + 1;
                    }
                }
            });
            uksort($counts, 'strnatcasecmp');
            return $counts;
        });
        $rows = collect($counts)->map(fn ($count, $value) => ['value' => $value, 'count' => $count]);
        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));
        if ($search !== '') $rows = $rows->filter(fn ($item) => str_contains(mb_strtolower($item['value']), $search))->values();
        $mappedValues = array_fill_keys($data['mapped_values'] ?? [], true);
        $mappedCount = collect(array_keys($counts))->filter(fn ($value) => isset($mappedValues[$value]))->count();
        $perPage = (int) ($data['per_page'] ?? 50); $page = (int) ($data['page'] ?? 1); $total = $rows->count();
        return response()->json(['success' => true, 'data' => $rows->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'mapped_count' => $mappedCount]]);
    }

    public function localSourceValueStats(Request $request, YandexMarketProductResolver $resolver)
    {
        $data = $request->validate([
            'category_ids' => 'required|array|min:1', 'category_ids.*' => 'integer',
            'selection_tag_id' => 'nullable|integer|exists:shop_tags,id',
            'mappings' => 'required|array|min:1|max:100',
            'mappings.*.key' => 'required|string|max:255',
            'mappings.*.source' => 'required|string|in:brand,property,variation_attribute',
            'mappings.*.source_key' => 'nullable|integer|min:1',
            'mappings.*.mapped_values' => 'nullable|array',
            'mappings.*.mapped_values.*' => 'string|max:1000',
        ]);
        $account = $this->account();
        $tagId = (int) ($data['selection_tag_id'] ?? $account->selection_tag_id);
        $mappings = collect($data['mappings'])->map(fn ($mapping) => [
            'key' => $mapping['key'], 'source' => $mapping['source'], 'source_key' => $mapping['source_key'] ?? null,
            'mapped_values' => array_fill_keys($mapping['mapped_values'] ?? [], true),
        ])->values()->all();
        $cacheKey = 'yandex-market:local-source-value-stats:'.hash('sha256', json_encode([$account->id, $data['category_ids'], $tagId, collect($mappings)->map(fn ($mapping) => Arr::except($mapping, 'mapped_values'))->all()]));
        $countsByMapping = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($data, $tagId, $resolver, $mappings) {
            $counts = array_fill_keys(array_column($mappings, 'key'), []);
            $query = \App\Models\ShopGood::query()->with(['brands:id,name', 'properties.values', 'variations.attributeValues.attribute'])
                ->where('is_active', true)->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $data['category_ids']));
            if ($tagId) $query->whereHas('tags', fn ($query) => $query->where('shop_tags.id', $tagId));
            $query->chunkById(200, function ($goods) use (&$counts, $mappings, $resolver) {
                foreach ($goods as $good) foreach ($mappings as $mapping) {
                    $variations = $mapping['source'] === 'variation_attribute' ? $good->variations->where('is_active', true) : collect([null]);
                    foreach ($variations as $variation) {
                        $value = trim((string) $resolver->sourceValue($mapping, $good, $variation));
                        if ($value !== '') $counts[$mapping['key']][$value] = ($counts[$mapping['key']][$value] ?? 0) + 1;
                    }
                }
            });
            return $counts;
        });
        $stats = [];
        foreach ($mappings as $mapping) {
            $values = $countsByMapping[$mapping['key']] ?? [];
            $stats[$mapping['key']] = ['total' => count($values), 'mapped' => count(array_intersect_key($values, $mapping['mapped_values']))];
        }
        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function categories(Request $request)
    {
        $tree = (new YandexMarketClient($this->account()))->categoryTree($request->boolean('refresh'));
        return response()->json(['success' => true, 'data' => $this->sortTree($tree)]);
    }

    public function parameters(Request $request)
    {
        $data = $request->validate(['category_id' => 'required|integer|min:1', 'refresh' => 'nullable|boolean']);
        $result = (new YandexMarketClient($this->account()))->categoryParameters((int) $data['category_id'], (bool) ($data['refresh'] ?? false));
        $parameters = collect($result['parameters'] ?? $result['categoryParameters'] ?? [])->map(fn ($item) => $this->parameterData((array) $item))->sortBy(fn ($item) => sprintf('%d%d%s', $item['required'] ? 0 : 1, $item['distinctive'] ? 0 : 1, $item['name']), SORT_NATURAL | SORT_FLAG_CASE)->values();
        $templates = ShopYandexMarketAttributeTemplate::query()
            ->where('account_id', $this->account()->id)
            ->get(['market_parameter_id', 'source_signature', 'mapping'])
            ->groupBy('market_parameter_id')
            ->map(fn ($items) => $items->map(fn (ShopYandexMarketAttributeTemplate $template) => array_merge(
                $template->mapping ?? [], ['source_signature' => $template->source_signature]
            ))->values()->all())
            ->all();

        return response()->json(['success' => true, 'data' => [
            'parameters' => $parameters,
            'templates' => $templates,
            'allow_variations' => (bool) ($result['allowVariations'] ?? true),
        ]]);
    }

    public function dictionaryValues(Request $request)
    {
        $data = $request->validate(['category_id' => 'required|integer|min:1', 'parameter_id' => 'required|integer|min:1', 'search' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:100']);
        $result = (new YandexMarketClient($this->account()))->categoryParameters((int) $data['category_id']);
        $parameter = collect($result['parameters'] ?? $result['categoryParameters'] ?? [])->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $data['parameter_id']);
        if (! $parameter) return response()->json(['success' => false, 'message' => 'Характеристика не найдена в словаре выбранной категории.'], 404);
        $values = collect($parameter['values'] ?? [])->map(fn ($value) => ['id' => (int) ($value['id'] ?? 0), 'value' => html_entity_decode((string) ($value['value'] ?? $value['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')]);
        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));
        if ($search !== '') $values = $values->filter(fn ($value) => str_contains(mb_strtolower($value['value']), $search))->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = (int) ($data['page'] ?? 1); $total = $values->count();
        return response()->json(['success' => true, 'data' => $values->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]]);
    }

    public function dictionarySuggestions(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'required|integer|min:1', 'parameter_id' => 'required|integer|min:1',
            'values' => 'required|array|min:1|max:50', 'values.*' => 'string|max:1000',
        ]);
        $result = (new YandexMarketClient($this->account()))->categoryParameters((int) $data['category_id']);
        $parameter = collect($result['parameters'] ?? $result['categoryParameters'] ?? [])->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $data['parameter_id']);
        if (! $parameter) return response()->json(['success' => false, 'message' => 'Характеристика не найдена в словаре выбранной категории.'], 404);

        $values = collect($parameter['values'] ?? [])->map(fn ($value) => [
            'id' => (int) ($value['id'] ?? 0),
            'value' => html_entity_decode((string) ($value['value'] ?? $value['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ])->filter(fn ($value) => $value['id'] > 0 && $value['value'] !== '')->values();
        $suggestions = [];
        foreach (array_values(array_unique($data['values'])) as $localValue) {
            $needle = $this->dictionaryComparable($localValue);
            if ($needle === '') continue;
            $exact = $values->first(fn ($value) => $this->dictionaryComparable($value['value']) === $needle);
            if ($exact) {
                $suggestions[] = ['local' => $localValue, 'candidates' => [array_merge($exact, ['score' => 100])]];
                continue;
            }
            $candidates = [];
            foreach ($values as $remote) {
                $score = $this->dictionarySimilarity($needle, $this->dictionaryComparable($remote['value']));
                if ($score >= 35) $candidates[] = array_merge($remote, ['score' => $score]);
            }
            usort($candidates, fn ($left, $right) => $right['score'] <=> $left['score'] ?: strnatcasecmp($left['value'], $right['value']));
            if ($candidates) $suggestions[] = ['local' => $localValue, 'candidates' => array_slice($candidates, 0, 3)];
        }
        return response()->json(['success' => true, 'data' => $suggestions]);
    }

    public function mappings()
    {
        $account = ShopYandexMarketAccount::query()->first();
        if (! $account) return response()->json(['success' => true, 'data' => []]);
        $mappings = ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->latest()->get();
        $categoryNames = ShopCategory::query()->whereIn('id', $mappings->flatMap(fn ($mapping) => $mapping->categoryIds())->unique())->pluck('name', 'id');
        return response()->json(['success' => true, 'data' => $mappings->map(function ($mapping) use ($categoryNames) {
            $data = $mapping->toArray();
            $data['local_categories'] = $mapping->categoryIds()->map(fn ($id) => ['id' => $id, 'name' => $categoryNames[$id] ?? "ID {$id}"])->values();
            return $data;
        })]);
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer', 'category_ids' => 'required|array|min:1', 'category_ids.*' => 'integer|exists:shop_categories,id',
            'market_category_id' => 'required|integer|min:1', 'market_category_name' => 'required|string|max:255',
            'selection_tag_id' => 'nullable|integer|exists:shop_tags,id', 'campaign_id' => 'nullable|integer|min:1',
            'attribute_mappings' => 'nullable|array', 'dimension_settings' => 'nullable|array',
            'price_adjustment' => 'nullable|array', 'is_active' => 'nullable|boolean',
            'save_attribute_templates' => 'nullable|boolean',
        ]);
        $account = $this->account();
        $mapping = isset($data['id']) ? ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->findOrFail($data['id']) : new ShopYandexMarketCategoryMapping(['account_id' => $account->id]);
        $saveTemplates = $data['save_attribute_templates'] ?? true;
        unset($data['id'], $data['save_attribute_templates']);
        $mapping->fill($data)->save();
        if ($saveTemplates) $this->saveAttributeTemplates($account, $mapping->attribute_mappings ?? []);
        return response()->json(['success' => true, 'message' => 'Профиль категории сохранен.', 'data' => $mapping]);
    }

    public function saveDictionaryTemplate(Request $request)
    {
        $data = $request->validate([
            'market_parameter_id' => 'required|integer|min:1',
            'market_parameter_name' => 'required|string|max:255',
            'source' => 'required|string|in:brand,property,variation_attribute,main_image,good,static',
            'source_key' => 'nullable',
            'profile_id' => 'nullable|integer|min:1',
            'dictionary_map' => 'required|array',
            'dictionary_labels' => 'nullable|array',
        ]);

        $account = $this->account();
        $signature = $this->sourceSignature($data['source'], $data['source_key'] ?? null);
        [$template, $profileSaved] = DB::transaction(function () use ($account, $data, $signature) {
            $template = ShopYandexMarketAttributeTemplate::query()->firstOrNew([
                'account_id' => $account->id,
                'market_parameter_id' => (int) $data['market_parameter_id'],
                'source_signature' => $signature,
            ]);
            $current = $template->mapping ?? [];
            $template->fill([
                'market_parameter_name' => $data['market_parameter_name'],
                'mapping' => array_merge($current, [
                    'source' => $data['source'],
                    'source_key' => $data['source_key'] ?? '',
                    'dictionary_map' => array_merge($current['dictionary_map'] ?? [], $data['dictionary_map']),
                    'dictionary_labels' => array_merge($current['dictionary_labels'] ?? [], $data['dictionary_labels'] ?? []),
                ]),
            ])->save();

            $profileSaved = false;
            if (! empty($data['profile_id'])) {
                $profile = ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->findOrFail((int) $data['profile_id']);
                $mappings = collect($profile->attribute_mappings ?? [])->map(function ($mapping) use ($data) {
                    if ((int) ($mapping['id'] ?? 0) !== (int) $data['market_parameter_id']) return $mapping;
                    $mapping['dictionary_map'] = array_merge($mapping['dictionary_map'] ?? [], $data['dictionary_map']);
                    $mapping['dictionary_labels'] = array_merge($mapping['dictionary_labels'] ?? [], $data['dictionary_labels'] ?? []);
                    return $mapping;
                })->values()->all();
                $profile->update(['attribute_mappings' => $mappings]);
                $profileSaved = true;
            }
            return [$template, $profileSaved];
        });

        return response()->json(['success' => true, 'message' => $profileSaved ? 'Сопоставления сохранены в текущем профиле и общем шаблоне.' : 'Сопоставления сохранены в общем шаблоне. Сохраните новый профиль, чтобы применить их в нём.', 'data' => $template->mapping, 'profile_saved' => $profileSaved]);
    }

    public function deleteMapping(ShopYandexMarketCategoryMapping $mapping)
    {
        abort_unless($mapping->account_id === $this->account()->id, 404);
        $mapping->delete();
        return response()->json(['success' => true, 'message' => 'Профиль удален.']);
    }

    public function preview(Request $request, YandexMarketPayloadBuilder $builder, YandexMarketProductResolver $resolver)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:50', 'search' => 'nullable|string|max:255', 'good_ids' => 'nullable|array|max:100', 'good_ids.*' => 'integer']);
        $account = $this->account();
        if (! $this->hasUsableMappings($account)) return response()->json(['success' => false, 'message' => 'Создайте активный профиль с категорией и тегом отбора (или задайте тег по умолчанию в подключении).'], 422);
        $page = (int) ($data['page'] ?? 1); $perPage = (int) ($data['per_page'] ?? 10);
        $query = $resolver->query($account, $data['good_ids'] ?? null)->orderByDesc('shop_goods.id');
        if (filled($data['search'] ?? null)) {
            $search = trim($data['search']);
            $query->where(fn ($q) => $q->where('shop_goods.name', 'like', "%{$search}%")->orWhere('shop_goods.sku', 'like', "%{$search}%")->orWhere('shop_goods.id', ctype_digit($search) ? (int) $search : 0)->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', "%{$search}%")));
        }
        $total = (clone $query)->count(); $lastPage = max(1, (int) ceil($total / $perPage)); $page = min($page, $lastPage);
        $mappings = $resolver->mappings($account); $rows = [];
        foreach ($query->forPage($page, $perPage)->get() as $good) {
            $mapping = $resolver->mappingFor($good, $mappings, $account);
            foreach ($resolver->rowsForGood($good, $mapping) as $row) {
                $built = $builder->build($good, $row['variation'], $account, $mapping);
                $built['errors'] = array_values(array_unique(array_merge($built['errors'], $row['row_errors'])));
                $rows[] = $built;
            }
        }
        return response()->json(['success' => true, 'data' => $rows, 'meta' => [
            'eligible_goods' => $total, 'offers' => count($rows), 'offers_with_errors' => collect($rows)->filter(fn ($row) => ! empty($row['errors']))->count(),
            'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage,
        ]]);
    }

    public function startSync(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:products,prices,stocks', 'good_ids' => 'nullable|array', 'good_ids.*' => 'integer|exists:shop_goods,id']);
        $account = $this->account();
        if (! $this->hasUsableMappings($account)) return response()->json(['success' => false, 'message' => 'Создайте активный профиль с категорией и тегом отбора (или задайте тег по умолчанию в подключении).'], 422);
        if (! $account->business_id) return response()->json(['success' => false, 'message' => 'Сначала проверьте подключение и выберите кабинет.'], 422);
        if ($data['mode'] === 'stocks' && ! $this->hasStockCampaign($account)) return response()->json(['success' => false, 'message' => 'Укажите магазин/склад Маркета хотя бы в одном активном профиле или в настройках подключения по умолчанию.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => $data['mode'], 'good_ids' => $data['good_ids'] ?? null]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Синхронизация поставлена в очередь.', 'data' => $run], 202);
    }

    public function products(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'status' => 'nullable|string|max:50', 'stock_filter' => 'nullable|in:zero', 'error_filter' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:10|max:100']);
        $query = $this->productBindingsQuery($this->account(), $data)
            ->with(['good:id,name,sku,is_active,stock_quantity', 'variation:id,good_id,name,sku,is_active,stock_quantity']);
        if ($data['error_filter'] ?? false) $query->whereNotNull('errors');
        return response()->json(['success' => true, 'data' => $query->paginate($data['per_page'] ?? 50)]);
    }

    public function deleteProducts(Request $request)
    {
        $data = $request->validate([
            'binding_ids' => 'nullable|array|max:10000', 'binding_ids.*' => 'integer',
            'all_filtered' => 'nullable|boolean', 'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50', 'stock_filter' => 'nullable|in:zero',
        ]);
        if (empty($data['binding_ids']) && ! ($data['all_filtered'] ?? false)) {
            return response()->json(['success' => false, 'message' => 'Выберите карточки или примените действие ко всем отфильтрованным.'], 422);
        }
        $account = $this->account();
        if (! $account->business_id) return response()->json(['success' => false, 'message' => 'Не указан Business ID Яндекс Маркета.'], 422);
        $query = $this->productBindingsQuery($account, $data);
        if (! ($data['all_filtered'] ?? false)) $query->whereIn('id', $data['binding_ids']);
        $bindingIds = $query->pluck('id')->all();
        if (! $bindingIds) return response()->json(['success' => false, 'message' => 'Подходящие карточки не найдены.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => 'delete', 'total' => count($bindingIds), 'meta' => ['binding_ids' => $bindingIds]]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Другая операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Удаление товаров из каталога Яндекс Маркета поставлено в очередь.', 'data' => $run], 202);
    }

    public function hideProducts(Request $request)
    {
        $data = $request->validate([
            'binding_ids' => 'nullable|array|max:10000', 'binding_ids.*' => 'integer',
            'all_filtered' => 'nullable|boolean', 'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50', 'stock_filter' => 'nullable|in:zero',
        ]);
        if (empty($data['binding_ids']) && ! ($data['all_filtered'] ?? false)) {
            return response()->json(['success' => false, 'message' => 'Выберите карточки или примените действие ко всем отфильтрованным.'], 422);
        }
        $account = $this->account();
        if (! $account->campaign_id) return response()->json(['success' => false, 'message' => 'Для скрытия товаров выберите магазин Campaign ID.'], 422);
        $query = $this->productBindingsQuery($account, $data);
        if (! ($data['all_filtered'] ?? false)) $query->whereIn('id', $data['binding_ids']);
        $bindingIds = $query->pluck('id')->all();
        if (! $bindingIds) return response()->json(['success' => false, 'message' => 'Подходящие карточки не найдены.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => 'hide', 'total' => count($bindingIds), 'meta' => ['binding_ids' => $bindingIds]]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Другая операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Скрытие товаров с витрины Яндекс Маркета поставлено в очередь.', 'data' => $run], 202);
    }

    public function purgeCatalog(Request $request)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $account = $this->account();
        if (! $account->business_id) return response()->json(['success' => false, 'message' => 'Не указан Business ID Яндекс Маркета.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => 'purge_catalog', 'meta' => ['source' => 'market_catalog']]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Другая операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Очистка всего каталога Яндекс Маркета поставлена в очередь. Сначала будут получены все Offer ID, затем они удалятся пачками.', 'data' => $run], 202);
    }

    public function refreshProducts(Request $request)
    {
        $account = $this->account();
        if (! $account->business_id) return response()->json(['success' => false, 'message' => 'Сначала проверьте подключение к Яндекс Маркету.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => 'catalog_import']);
        if (! $created) return response()->json(['success' => true, 'message' => 'Другая операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);

        return response()->json(['success' => true, 'message' => 'Загрузка фактического каталога Яндекс Маркета поставлена в очередь.', 'data' => $run], 202);
    }

    public function readBackProducts(Request $request)
    {
        $account = $this->account();
        if (! $account->business_id) {
            return response()->json(['success' => false, 'message' => 'Сначала проверьте подключение к Яндекс Маркету.'], 422);
        }

        $count = ShopYandexMarketProductBinding::query()->where('account_id', $account->id)->count();
        if (! $count) {
            return response()->json(['success' => false, 'message' => 'Нет карточек для сверки. Сначала отправьте карточки или загрузите каталог из Яндекса.'], 422);
        }

        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => 'readback', 'total' => $count]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Другая операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);

        return response()->json(['success' => true, 'message' => 'Сверка фактических карточек с Яндекс Маркетом поставлена в очередь.', 'data' => $run], 202);
    }

    public function runs(Request $request)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $account = ShopYandexMarketAccount::query()->first();
        $runs = $account
            ? ShopYandexMarketSyncRun::query()->where('account_id', $account->id)->latest()->paginate($data['per_page'] ?? 20)
            : null;

        return response()->json(['success' => true, 'data' => $runs ?: [
            'data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $data['per_page'] ?? 20, 'total' => 0,
        ]]);
    }

    public function clearRuns()
    {
        $account = $this->account();
        $active = ShopYandexMarketSyncRun::query()
            ->where('account_id', $account->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($active) {
            return response()->json(['success' => false, 'message' => 'Нельзя очистить журнал, пока выполняется операция синхронизации.'], 422);
        }

        DB::transaction(function () use ($account) {
            $runIds = ShopYandexMarketSyncRun::query()->where('account_id', $account->id)->pluck('id');
            ShopYandexMarketSyncItem::query()->whereIn('run_id', $runIds)->delete();
            ShopYandexMarketSyncRun::query()->whereIn('id', $runIds)->delete();
        });

        return response()->json(['success' => true, 'message' => 'Журнал синхронизации очищен. Карточки и настройки профилей не затронуты.']);
    }

    public function run(ShopYandexMarketSyncRun $run)
    {
        abort_unless($run->account_id === $this->account()->id, 404);
        return response()->json(['success' => true, 'data' => $run->load(['items' => fn ($q) => $q->latest()->limit(200)])]);
    }

    public function cancelRun(ShopYandexMarketSyncRun $run)
    {
        abort_unless($run->account_id === $this->account()->id, 404);
        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update(['status' => 'cancelled', 'finished_at' => now(), 'error_message' => 'Операция отменена пользователем.']);
        }
        return response()->json(['success' => true, 'message' => 'Операция отменена.', 'data' => $run->fresh()]);
    }

    private function account(): ShopYandexMarketAccount { return ShopYandexMarketAccount::query()->firstOrFail(); }

    /** @return array{0: ShopYandexMarketSyncRun, 1: bool} */
    private function startRun(ShopYandexMarketAccount $account, ?int $userId, array $attributes): array
    {
        return DB::transaction(function () use ($account, $userId, $attributes) {
            ShopYandexMarketAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $active = ShopYandexMarketSyncRun::query()
                ->where('account_id', $account->id)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->first();

            if ($active) return [$active, false];

            return [ShopYandexMarketSyncRun::create(array_merge([
                'account_id' => $account->id,
                'user_id' => $userId,
                'status' => 'pending',
            ], $attributes)), true];
        });
    }

    private function productBindingsQuery(ShopYandexMarketAccount $account, array $filters)
    {
        $query = ShopYandexMarketProductBinding::query()->where('account_id', $account->id)->latest('id');
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($q) => $q->where('offer_id', 'like', "%{$search}%")
                ->orWhere('market_sku', 'like', "%{$search}%")
                ->orWhereHas('good', fn ($g) => $g->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")));
        }
        if (($filters['stock_filter'] ?? null) === 'zero') {
            $zeroStock = fn ($stockQuery) => $stockQuery->where('stock_quantity', '<=', 0);
            $query->where(function ($nested) use ($zeroStock) {
                $nested->where(fn ($parent) => $parent->whereNull('variation_id')->whereHas('good', $zeroStock))
                    ->orWhereHas('variation', $zeroStock);
            });
        }
        return $query;
    }
    private function saveAttributeTemplates(ShopYandexMarketAccount $account, array $mappings): void
    {
        foreach ($mappings as $mapping) {
            $parameterId = (int) ($mapping['id'] ?? 0);
            if ($parameterId < 1 || empty($mapping['active'])) continue;

            // Category metadata itself must never be copied into the reusable rule.
            $template = collect($mapping)->except([
                'id', 'name', 'type', 'required', 'distinctive', 'multivalue', 'values',
                'units', 'default_unit_id', 'allow_custom_values', 'mapping_origin', 'local_values', 'source_signature',
            ])->all();

            ShopYandexMarketAttributeTemplate::query()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'market_parameter_id' => $parameterId,
                    'source_signature' => $this->sourceSignature($template['source'] ?? '', $template['source_key'] ?? null),
                ],
                ['market_parameter_name' => (string) ($mapping['name'] ?? "ID {$parameterId}"), 'mapping' => $template],
            );
        }
    }

    private function sourceSignature(string $source, mixed $sourceKey): string
    {
        return mb_substr($source.':'.trim((string) $sourceKey), 0, 100);
    }

    private function hasUsableMappings(ShopYandexMarketAccount $account): bool
    {
        return ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->where('is_active', true)
            ->get()->contains(fn ($mapping) => $mapping->categoryIds()->isNotEmpty() && ($mapping->selection_tag_id ?: $account->selection_tag_id));
    }
    private function hasStockCampaign(ShopYandexMarketAccount $account): bool
    {
        return (bool) $account->campaign_id || ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->where('is_active', true)->whereNotNull('campaign_id')->exists();
    }
    private function accountData(ShopYandexMarketAccount $account): array { return array_merge($account->toArray(), ['has_api_key' => filled($account->api_key), 'api_key' => '']); }
    private function campaignData(array $item): array { return ['id' => (int) ($item['id'] ?? 0), 'domain' => $item['domain'] ?? $item['name'] ?? '', 'business_id' => (int) data_get($item, 'business.id', $item['businessId'] ?? 0), 'business_name' => data_get($item, 'business.name', ''), 'placement_type' => $item['placementType'] ?? '', 'api_availability' => $item['apiAvailability'] ?? 'AVAILABLE']; }
    private function capabilities(array $scopes, array $campaign): array
    {
        $scopes = array_map('strtoupper', $scopes);
        $all = in_array('ALL_METHODS', $scopes, true);
        $allRead = $all || in_array('ALL_METHODS_READ_ONLY', $scopes, true);
        return [
            'connection' => true,
            'catalog_read' => $allRead || (bool) array_intersect($scopes, ['OFFERS_AND_CARDS_MANAGEMENT', 'OFFERS_AND_CARDS_MANAGEMENT_READ_ONLY']),
            'cards_write' => $all || in_array('OFFERS_AND_CARDS_MANAGEMENT', $scopes, true),
            'prices_write' => $all || in_array('PRICING', $scopes, true),
            'stocks_write' => $all || in_array('INVENTORY_AND_ORDER_PROCESSING', $scopes, true),
            'campaign_available' => ($campaign['api_availability'] ?? '') === 'AVAILABLE',
        ];
    }
    private function parameterData(array $item): array { return ['id' => (int) ($item['id'] ?? 0), 'name' => html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'description' => $item['description'] ?? '', 'type' => $item['type'] ?? 'TEXT', 'required' => (bool) ($item['required'] ?? $item['isRequired'] ?? false), 'distinctive' => (bool) ($item['distinctive'] ?? $item['isDistinctive'] ?? false), 'multivalue' => (bool) ($item['multivalue'] ?? $item['isMultivalue'] ?? false), 'allow_custom_values' => (bool) ($item['allowCustomValues'] ?? $item['allow_custom_values'] ?? true), 'dictionary_count' => count($item['values'] ?? []), 'values' => [], 'units' => data_get($item, 'unit.units', []), 'default_unit_id' => data_get($item, 'unit.defaultUnitId')]; }
    private function dictionaryComparable(string $value): string { $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); $value = mb_strtolower(trim($value)); $value = str_replace('ё', 'е', $value); return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value)); }
    private function dictionarySimilarity(string $left, string $right): int
    {
        if ($left === '' || $right === '') return 0;
        if ($left === $right) return 100;
        if (str_contains($left, $right) || str_contains($right, $left)) return 88;
        $leftTokens = array_values(array_unique(preg_split('/\s+/u', $left, -1, PREG_SPLIT_NO_EMPTY)));
        $rightTokens = array_values(array_unique(preg_split('/\s+/u', $right, -1, PREG_SPLIT_NO_EMPTY)));
        $common = count(array_intersect($leftTokens, $rightTokens));
        if ($common === 0) return 0;
        return (int) round(100 * $common / max(count($leftTokens), count($rightTokens)));
    }
    private function sortTree(array $node): array
    {
        if (array_is_list($node)) {
            $nodes = array_map(fn ($item) => $this->sortTree((array) $item), $node);
            usort($nodes, fn ($a, $b) => strnatcasecmp($a['name'] ?? '', $b['name'] ?? ''));
            return $nodes;
        }
        $children = array_map(fn ($child) => $this->sortTree((array) $child), array_filter((array) ($node['children'] ?? [])));
        usort($children, fn ($a, $b) => strnatcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        $node['children'] = $children;
        return $node;
    }
    private function syncLegacySettings(ShopYandexMarketAccount $account): void { foreach ([['yandex_market_campaign_id', 'Campaign ID Яндекс Маркета', $account->campaign_id, 'string'], ['yandex_market_auth_token', 'Токен API Яндекс Маркета', $account->api_key, 'password'], ['yandex_market_auth_type', 'Тип авторизации API Яндекс Маркета', 'api_key', 'string']] as [$key, $name, $value, $type]) Setting::updateOrCreate(['key' => $key], ['name' => $name, 'value' => $value ?: '', 'type' => $type, 'group' => 'yandex_market']); }
}
