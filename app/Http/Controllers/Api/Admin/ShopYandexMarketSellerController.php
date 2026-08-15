<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessYandexMarketSellerSyncJob;
use App\Models\Setting;
use App\Models\ShopCategory;
use App\Models\ShopGood;
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
            'unmapped_only' => 'nullable|boolean',
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
        $valuesTotal = $rows->count();
        $mappedValues = array_fill_keys($data['mapped_values'] ?? [], true);
        $mappedCount = collect(array_keys($counts))->filter(fn ($value) => isset($mappedValues[$value]))->count();
        if ($data['unmapped_only'] ?? false) {
            $rows = $rows->filter(fn ($item) => ! isset($mappedValues[$item['value']]))->values();
        }
        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));
        if ($search !== '') $rows = $rows->filter(fn ($item) => str_contains(mb_strtolower($item['value']), $search))->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = (int) ($data['page'] ?? 1); $total = $rows->count();
        return response()->json(['success' => true, 'data' => $rows->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'values_total' => $valuesTotal, 'mapped_count' => $mappedCount]]);
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
        return response()->json(['success' => true, 'data' => $mappings->map(function ($mapping) use ($categoryNames, $account) {
            $data = $mapping->toArray();
            $data['local_categories'] = $mapping->categoryIds()->map(fn ($id) => ['id' => $id, 'name' => $categoryNames[$id] ?? "ID {$id}"])->values();
            $data['selection_tag_name'] = $mapping->selection_tag_id
                ? ShopTag::query()->find($mapping->selection_tag_id)?->name
                : ShopTag::query()->find($account->selection_tag_id)?->name;
            $data['profile_stats'] = $this->profileStats($mapping, $account);
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

    public function removeDictionaryTemplateMapping(Request $request)
    {
        $data = $request->validate([
            'market_parameter_id' => 'required|integer|min:1',
            'source' => 'required|string|in:brand,property,variation_attribute,main_image,good,static',
            'source_key' => 'nullable', 'profile_id' => 'nullable|integer|min:1',
            'local_value' => 'required|string|max:1000',
        ]);

        $account = $this->account();
        $signature = $this->sourceSignature($data['source'], $data['source_key'] ?? null);
        $profileSaved = false;
        DB::transaction(function () use ($account, $data, $signature, &$profileSaved) {
            $template = ShopYandexMarketAttributeTemplate::query()
                ->where('account_id', $account->id)
                ->where('market_parameter_id', (int) $data['market_parameter_id'])
                ->where('source_signature', $signature)
                ->first();
            if ($template) {
                $mapping = $template->mapping ?? [];
                unset($mapping['dictionary_map'][$data['local_value']], $mapping['dictionary_labels'][$data['local_value']]);
                $template->update(['mapping' => $mapping]);
            }

            if (! empty($data['profile_id'])) {
                $profile = ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->findOrFail((int) $data['profile_id']);
                $mappings = collect($profile->attribute_mappings ?? [])->map(function ($mapping) use ($data) {
                    $sameParameter = (int) ($mapping['id'] ?? 0) === (int) $data['market_parameter_id'];
                    $sameSource = ($mapping['source'] ?? '') === $data['source']
                        && (string) ($mapping['source_key'] ?? '') === (string) ($data['source_key'] ?? '');
                    if (! $sameParameter || ! $sameSource) return $mapping;
                    unset($mapping['dictionary_map'][$data['local_value']], $mapping['dictionary_labels'][$data['local_value']]);
                    return $mapping;
                })->values()->all();
                $profile->update(['attribute_mappings' => $mappings]);
                $profileSaved = true;
            }
        });

        return response()->json(['success' => true, 'message' => $profileSaved ? 'Привязка удалена из профиля и общего шаблона.' : 'Привязка удалена из общего шаблона.']);
    }

    public function deleteMapping(ShopYandexMarketCategoryMapping $mapping)
    {
        abort_unless($mapping->account_id === $this->account()->id, 404);
        $mapping->delete();
        return response()->json(['success' => true, 'message' => 'Профиль удален.']);
    }

    public function preview(Request $request, YandexMarketPayloadBuilder $builder, YandexMarketProductResolver $resolver)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:50', 'search' => 'nullable|string|max:255', 'good_ids' => 'nullable|array|max:100', 'good_ids.*' => 'integer', 'mapping_id' => 'nullable|integer|min:1', 'errors_only' => 'nullable|boolean', 'stock_zero' => 'nullable|boolean', 'validation_filter' => 'nullable|in:valid_offers,valid_goods,valid_variations,valid_variation_goods,valid_simple_goods', 'error_types' => 'nullable|array', 'error_types.*' => 'in:image,required,price,discount,category,market,other']);
        $account = $this->account();
        if (! $this->hasUsableMappings($account)) return response()->json(['success' => false, 'message' => 'Создайте активный профиль с категорией и тегом отбора (или задайте тег по умолчанию в подключении).'], 422);
        $page = (int) ($data['page'] ?? 1); $perPage = (int) ($data['per_page'] ?? 10);
        $mappings = $resolver->mappings($account);
        $selectedMapping = filled($data['mapping_id'] ?? null)
            ? $mappings->firstWhere('id', (int) $data['mapping_id'])
            : null;
        if (filled($data['mapping_id'] ?? null) && ! $selectedMapping) {
            return response()->json(['success' => false, 'message' => 'Профиль категории не найден или выключен.'], 422);
        }
        $baseQuery = $resolver->query($account, $data['good_ids'] ?? null)->orderByDesc('shop_goods.id');
        $categoryFacets = $this->previewCategoryFacets($mappings, $account, $resolver);
        $query = clone $baseQuery;
        if ($selectedMapping) {
            $tagId = $resolver->selectionTagId($selectedMapping, $account);
            $query->whereHas('tags', fn ($tags) => $tags->where('shop_tags.id', $tagId))
                ->whereHas('categories', fn ($categories) => $categories->whereIn('shop_categories.id', $selectedMapping->categoryIds()->all()));
        }
        $total = (clone $query)->count(); $lastPage = max(1, (int) ceil($total / $perPage)); $page = min($page, $lastPage);
        $eligibleIds = (clone $query)->reorder()->select('shop_goods.id');
        $variationSummary = DB::query()->fromSub(
            DB::table('shop_good_variations')
                ->where('is_active', true)
                ->whereIn('good_id', $eligibleIds)
                ->selectRaw('good_id, COUNT(*) as variations_count')
                ->groupBy('good_id'),
            'eligible_variations'
        )->selectRaw('COALESCE(SUM(variations_count), 0) as variations_total')
            ->selectRaw('SUM(CASE WHEN variations_count > 1 THEN 1 ELSE 0 END) as variation_groups_total')
            ->selectRaw('SUM(CASE WHEN variations_count = 1 THEN 1 ELSE 0 END) as single_variation_goods_total')
            ->first();
        $goodsWithVariations = (int) ($variationSummary->variation_groups_total ?? 0) + (int) ($variationSummary->single_variation_goods_total ?? 0);
        $offersTotal = (int) ($variationSummary->variations_total ?? 0) + max(0, $total - $goodsWithVariations);
        $allRows = [];
        $placeholderImage = $this->siteLogoUrl();
        $goods = $query->get();
        foreach ($goods as $good) {
            $mapping = $selectedMapping ?: $resolver->mappingFor($good, $mappings, $account);
            foreach ($resolver->rowsForGood($good, $mapping) as $row) {
                $built = $builder->build($good, $row['variation'], $account, $mapping);
                $built['local_errors'] = array_values(array_unique(array_merge($built['errors'], $row['row_errors'])));
                $built['local_warnings'] = $built['warnings'] ?? [];
                $built['media_summary']['placeholder'] = $placeholderImage;
                $built['local_categories'] = $this->goodCategoriesForMapping($good, $mapping);
                $allRows[] = $built;
            }
        }
        if (filled($data['search'] ?? null)) {
            $search = (string) $data['search'];
            $allRows = array_values(array_filter($allRows, fn (array $row) => $this->previewSearchMatches($row, $search)));
            $matchedGoodIds = collect($allRows)->pluck('good_id')->map(fn ($id) => (int) $id)->unique();
            $goods = $goods->filter(fn (ShopGood $good) => $matchedGoodIds->contains((int) $good->id))->values();
            $total = $goods->count();
            $offersTotal = count($allRows);
            $variationCounts = collect($allRows)->groupBy('good_id')->map(fn ($rows) => $rows->filter(fn (array $row) => ! empty($row['variation_id']))->count());
            $variationSummary = (object) [
                'variations_total' => $variationCounts->sum(),
                'variation_groups_total' => $variationCounts->filter(fn ($count) => $count > 1)->count(),
                'single_variation_goods_total' => $variationCounts->filter(fn ($count) => $count === 1)->count(),
            ];
        }
        $marketErrors = $this->latestMarketErrorsByOffer($account->id, array_column($allRows, 'offer_id'));
        $allRows = array_map(function (array $row) use ($marketErrors) {
            $row['market_errors'] = $marketErrors[$row['offer_id']] ?? [];
            $row['errors'] = array_values(array_unique(array_merge(
                $row['local_errors'],
                array_map(fn (string $error) => "Яндекс Маркет: {$error}", $row['market_errors']),
            )));
            $row['error_types'] = $this->previewErrorTypes($row);
            return $row;
        }, $allRows);
        $allGoods = collect($allRows)->groupBy('good_id');
        $validRows = collect($allRows)->filter(fn (array $row) => empty($row['errors']));
        $validGoods = $allGoods->filter(fn ($rows) => $rows->every(fn (array $row) => empty($row['errors'])));
        $validVariationRows = $validRows->filter(fn (array $row) => ! empty($row['variation_id']));
        $validVariationGoods = $validGoods->filter(fn ($rows) => $rows->contains(fn (array $row) => ! empty($row['variation_id'])));
        $validSimpleGoods = $validGoods->filter(fn ($rows) => $rows->every(fn (array $row) => empty($row['variation_id'])));
        $errorTypes = array_values(array_unique($data['error_types'] ?? []));
        $stockZero = (bool) ($data['stock_zero'] ?? false);
        $validationFilter = $data['validation_filter'] ?? null;
        $allowedGoodIds = match ($validationFilter) {
            'valid_goods' => $validGoods->keys()->map(fn ($id) => (int) $id)->all(),
            'valid_variation_goods' => $validVariationGoods->keys()->map(fn ($id) => (int) $id)->all(),
            'valid_simple_goods' => $validSimpleGoods->keys()->map(fn ($id) => (int) $id)->all(),
            default => null,
        };
        $candidateRows = collect($allRows)->filter(function (array $row) use ($validationFilter, $allowedGoodIds) {
            return match ($validationFilter) {
                'valid_offers' => empty($row['errors']),
                'valid_variations' => ! empty($row['variation_id']) && empty($row['errors']),
                'valid_goods', 'valid_variation_goods', 'valid_simple_goods' => in_array((int) $row['good_id'], $allowedGoodIds, true),
                default => true,
            };
        });
        $filteredRows = $candidateRows
            ->filter(function (array $row) use ($stockZero, $data, $errorTypes) {
                if ($stockZero && (int) $row['stock'] !== 0) {
                    return false;
                }
                if (! ($data['errors_only'] ?? false) && ! $errorTypes) {
                    return true;
                }
                if (empty($row['errors']) && empty($row['local_warnings'])) {
                    return false;
                }
                return ! $errorTypes || ! empty(array_intersect($row['error_types'], $errorTypes));
            });
        $filteredGoodIds = $filteredRows->pluck('good_id')->map(fn ($id) => (int) $id)->unique()->all();
        $displayGoods = (($data['errors_only'] ?? false) || $errorTypes || $stockZero || $validationFilter)
            ? $goods->filter(fn (ShopGood $good) => in_array((int) $good->id, $filteredGoodIds, true))->values()
            : $goods;
        $displayTotal = $displayGoods->count();
        $displayLastPage = max(1, (int) ceil($displayTotal / $perPage));
        $page = min($page, $displayLastPage);
        $pageGoodIds = $displayGoods->forPage($page, $perPage)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $visibleOfferIds = $filteredRows->pluck('offer_id')->all();
        $rows = array_values(array_filter($allRows, fn (array $row) => in_array((int) $row['good_id'], $pageGoodIds, true) && in_array($row['offer_id'], $visibleOfferIds, true)));
        return response()->json(['success' => true, 'data' => $rows, 'meta' => [
            'eligible_goods' => $total,
            'eligible_offers' => $offersTotal,
            'eligible_variations' => (int) ($variationSummary->variations_total ?? 0),
            'eligible_variation_groups' => (int) ($variationSummary->variation_groups_total ?? 0),
            'eligible_single_variation_goods' => (int) ($variationSummary->single_variation_goods_total ?? 0),
            'category_facets' => $categoryFacets,
            'offers' => count($rows),
            'offers_with_errors' => collect($allRows)->filter(fn ($row) => ! empty($row['errors']) || ! empty($row['local_warnings']))->count(),
            'offers_with_errors_on_page' => collect($rows)->filter(fn ($row) => ! empty($row['errors']) || ! empty($row['local_warnings']))->count(),
            'zero_stock_offers' => collect($allRows)->filter(fn (array $row) => (int) $row['stock'] === 0)->count(),
            'valid_offers' => $validRows->count(),
            'valid_goods' => $validGoods->count(),
            'valid_variations' => $validVariationRows->count(),
            'valid_variation_groups' => $validVariationGoods->count(),
            'valid_goods_without_variations' => $validSimpleGoods->count(),
            'error_type_counts' => collect($allRows)->flatMap(fn (array $row) => collect($row['error_types'] ?? [])->map(fn ($type) => ['type' => $type, 'offer_id' => $row['offer_id']]))
                ->groupBy('type')->map(fn ($items) => $items->pluck('offer_id')->unique()->count())->all(),
            'current_page' => $page, 'last_page' => $displayLastPage, 'per_page' => $perPage,
        ]]);
    }

    public function startSync(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:products,prices,stocks', 'purpose' => 'nullable|in:upload,validation', 'good_ids' => 'nullable|array', 'good_ids.*' => 'integer|exists:shop_goods,id']);
        $account = $this->account();
        if (! $this->hasUsableMappings($account)) return response()->json(['success' => false, 'message' => 'Создайте активный профиль с категорией и тегом отбора (или задайте тег по умолчанию в подключении).'], 422);
        if (! $account->business_id) return response()->json(['success' => false, 'message' => 'Сначала проверьте подключение и выберите кабинет.'], 422);
        if ($data['mode'] === 'stocks' && ! $this->hasStockCampaign($account)) return response()->json(['success' => false, 'message' => 'Укажите магазин/склад Маркета хотя бы в одном активном профиле или в настройках подключения по умолчанию.'], 422);
        [$run, $created] = $this->startRun($account, $request->user()?->id, ['type' => $data['mode'], 'good_ids' => $data['good_ids'] ?? null, 'meta' => ['purpose' => $data['purpose'] ?? 'upload']]);
        if (! $created) return response()->json(['success' => true, 'message' => 'Операция уже выполняется.', 'data' => $run], 202);
        ProcessYandexMarketSellerSyncJob::dispatch($run->id);
        $message = ($data['purpose'] ?? null) === 'validation'
            ? 'Проверка офферов через API Яндекс Маркета поставлена в очередь.'
            : 'Синхронизация поставлена в очередь.';
        return response()->json(['success' => true, 'message' => $message, 'data' => $run], 202);
    }

    public function clearMarketResponses()
    {
        $account = $this->account();
        $bindings = ShopYandexMarketProductBinding::query()->where('account_id', $account->id);
        $bindingsCount = (clone $bindings)->where(fn ($query) => $query->whereNotNull('errors')->orWhereNotNull('warnings')->orWhereNotNull('remote_payload'))->count();
        $bindings->update(['errors' => null, 'warnings' => null, 'remote_payload' => null]);

        $runIds = ShopYandexMarketSyncRun::query()->where('account_id', $account->id)->whereIn('type', ['products', 'readback'])->pluck('id');
        $items = ShopYandexMarketSyncItem::query()->whereIn('run_id', $runIds);
        $itemsCount = (clone $items)->where(fn ($query) => $query->whereNotNull('errors')->orWhereNotNull('response_payload'))->count();
        $items->update(['errors' => null, 'response_payload' => null]);

        return response()->json(['success' => true, 'message' => "Очищены ответы Яндекс Маркета: привязок {$bindingsCount}, записей журнала {$itemsCount}."]);
    }

    public function products(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'status' => 'nullable|string|max:50', 'stock_filter' => 'nullable|in:zero', 'error_filter' => 'nullable|boolean', 'issue_type' => 'nullable|in:image,required,price,discount,not_found,other', 'per_page' => 'nullable|integer|min:10|max:100']);
        $account = $this->account();
        $query = $this->productBindingsQuery($account, $data)
            ->with(['good:id,name,sku,is_active,stock_quantity', 'variation:id,good_id,name,sku,is_active,stock_quantity']);
        if ($data['error_filter'] ?? false) {
            $query->where(function ($query) {
                $query->where('status', 'error')
                    ->orWhereRaw("JSON_LENGTH(COALESCE(errors, JSON_ARRAY())) > 0");
            });
        }
        if (filled($data['issue_type'] ?? null)) {
            $matchingIds = ShopYandexMarketProductBinding::query()->where('account_id', $account->id)
                ->get(['id', 'status', 'errors', 'warnings'])
                ->filter(fn (ShopYandexMarketProductBinding $binding) => in_array($data['issue_type'], $this->productIssueTypes($binding), true))
                ->pluck('id');
            $query->whereIn('id', $matchingIds);
        }
        $paginator = $query->paginate($data['per_page'] ?? 50);
        $submissionErrors = $this->latestMarketErrorsByOffer(
            $account->id,
            collect($paginator->items())->pluck('offer_id')->all(),
        );
        $paginator->getCollection()->transform(function (ShopYandexMarketProductBinding $binding) use ($submissionErrors) {
            $binding->setAttribute('submission_errors', $submissionErrors[$binding->offer_id] ?? []);
            return $binding;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
            'meta' => $this->productBindingStats($account),
        ]);
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

    /** @return array{total: int, statuses: array<string, int>, issue_types: array<string, int>} */
    private function productBindingStats(ShopYandexMarketAccount $account): array
    {
        $bindings = ShopYandexMarketProductBinding::query()
            ->where('account_id', $account->id)
            ->get(['status', 'errors', 'warnings']);
        $statuses = $bindings->countBy(fn (ShopYandexMarketProductBinding $binding) => $binding->status ?: 'unknown')->all();
        $issueTypes = [];
        foreach ($bindings as $binding) {
            foreach ($this->productIssueTypes($binding) as $type) {
                $issueTypes[$type] = ($issueTypes[$type] ?? 0) + 1;
            }
        }

        return ['total' => $bindings->count(), 'statuses' => $statuses, 'issue_types' => $issueTypes];
    }

    /** @return array<int, string> */
    private function productIssueTypes(ShopYandexMarketProductBinding $binding): array
    {
        $issues = collect(array_merge((array) $binding->errors, (array) $binding->warnings))
            ->map(fn ($issue) => mb_strtolower(is_string($issue) ? $issue : json_encode($issue, JSON_UNESCAPED_UNICODE)));
        $types = [];
        if ($issues->contains(fn ($issue) => str_contains($issue, 'изображен'))) $types[] = 'image';
        if ($issues->contains(fn ($issue) => str_contains($issue, 'обязательн') || str_contains($issue, 'характеристик'))) $types[] = 'required';
        if ($issues->contains(fn ($issue) => str_contains($issue, 'цен'))) $types[] = 'price';
        if ($issues->contains(fn ($issue) => str_contains($issue, 'скидк'))) $types[] = 'discount';
        if ($binding->status === 'error' || $issues->contains(fn ($issue) => str_contains($issue, 'не вернул карточку'))) $types[] = 'not_found';
        if (! $types && $issues->isNotEmpty()) $types[] = 'other';
        return array_values(array_unique($types));
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

    /** @return array{goods: int, variations: int, variation_groups: int, offers: int} */
    private function profileStats(ShopYandexMarketCategoryMapping $mapping, ShopYandexMarketAccount $account): array
    {
        $tagId = (int) ($mapping->selection_tag_id ?: $account->selection_tag_id);
        if (! $tagId || $mapping->categoryIds()->isEmpty()) {
            return ['goods' => 0, 'variations' => 0, 'variation_groups' => 0, 'offers' => 0];
        }

        $goods = ShopGood::query()
            ->where('is_active', true)
            ->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $mapping->categoryIds()->all()))
            ->whereHas('tags', fn ($query) => $query->where('shop_tags.id', $tagId))
            ->withCount(['variations as active_variations_count' => fn ($query) => $query->where('is_active', true)])
            ->get(['id']);
        $variations = (int) $goods->sum('active_variations_count');
        $variationGroups = $goods->filter(fn (ShopGood $good) => $good->active_variations_count > 1)->count();

        return [
            'goods' => $goods->count(),
            'variations' => $variations,
            'variation_groups' => $variationGroups,
            'offers' => $variations + $goods->where('active_variations_count', 0)->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function previewCategoryFacets($mappings, ShopYandexMarketAccount $account, YandexMarketProductResolver $resolver): array
    {
        $categoryIds = $mappings->flatMap(fn (ShopYandexMarketCategoryMapping $mapping) => $mapping->categoryIds())->unique()->values();
        $categoryNames = ShopCategory::query()->whereIn('id', $categoryIds)->pluck('name', 'id');
        $tagIds = $mappings->map(fn (ShopYandexMarketCategoryMapping $mapping) => $resolver->selectionTagId($mapping, $account))->filter()->unique()->values();
        $tagNames = ShopTag::query()->whereIn('id', $tagIds)->pluck('name', 'id');

        return $mappings->map(function (ShopYandexMarketCategoryMapping $mapping) use ($account, $resolver, $categoryNames, $tagNames) {
            $stats = $this->profileStats($mapping, $account);
            return [
                'key' => (string) $mapping->id,
                'mapping_id' => (int) $mapping->id,
                'name' => $mapping->market_category_name,
                'tag' => $tagNames[$resolver->selectionTagId($mapping, $account)] ?? null,
                'goods' => $stats['goods'],
                'variations' => $stats['variations'],
                'offers' => $stats['offers'],
                'local_categories' => $mapping->categoryIds()->map(fn ($id) => ['id' => (int) $id, 'name' => $categoryNames[$id] ?? "ID {$id}"])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function goodCategoriesForMapping(ShopGood $good, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        if (! $mapping) {
            return [];
        }

        return $good->categories
            ->filter(fn (ShopCategory $category) => $mapping->categoryIds()->contains((int) $category->id))
            ->map(fn (ShopCategory $category) => ['id' => (int) $category->id, 'name' => $category->name])
            ->values()
            ->all();
    }

    /** @return array<string, array<int, string>> */
    private function latestMarketErrorsByOffer(int $accountId, array $offerIds): array
    {
        $offerIds = array_values(array_filter(array_unique($offerIds)));
        if (! $offerIds) {
            return [];
        }

        $latestItemIds = DB::table('shop_yandex_market_sync_items as items')
            ->join('shop_yandex_market_sync_runs as runs', 'runs.id', '=', 'items.run_id')
            ->where('runs.account_id', $accountId)
            ->where('runs.type', 'products')
            ->whereIn('items.offer_id', $offerIds)
            ->groupBy('items.offer_id')
            ->selectRaw('MAX(items.id) as id')
            ->pluck('id');

        return ShopYandexMarketSyncItem::query()
            ->whereIn('id', $latestItemIds)
            ->get(['offer_id', 'errors'])
            ->filter(fn (ShopYandexMarketSyncItem $item) => ! empty($item->errors))
            ->mapWithKeys(fn (ShopYandexMarketSyncItem $item) => [$item->offer_id => array_values((array) $item->errors)])
            ->all();
    }

    /** @return array<int, string> */
    private function previewErrorTypes(array $row): array
    {
        $errors = collect($row['local_errors'] ?? [])->map(fn ($error) => mb_strtolower((string) $error));
        $warnings = collect($row['local_warnings'] ?? [])->map(fn ($warning) => mb_strtolower((string) $warning));
        $types = [];
        if ($errors->contains(fn ($error) => str_contains($error, 'изображен')) || $warnings->contains(fn ($warning) => str_contains($warning, 'изображен'))) $types[] = 'image';
        if ($errors->contains(fn ($error) => str_contains($error, 'обязательн') || str_contains($error, 'отличающ'))) $types[] = 'required';
        if ($errors->contains(fn ($error) => str_contains($error, 'цена'))) $types[] = 'price';
        if ($warnings->contains(fn ($warning) => str_contains($warning, 'скидк'))) $types[] = 'discount';
        if ($errors->contains(fn ($error) => str_contains($error, 'категор') || str_contains($error, 'профил'))) $types[] = 'category';
        if (! empty($row['market_errors'])) $types[] = 'market';
        if (! $types && ! empty($row['errors'])) $types[] = 'other';
        return array_values(array_unique($types));
    }

    private function previewSearchMatches(array $row, string $search): bool
    {
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $attributes = collect($row['variation_attributes'] ?? [])
            ->flatMap(fn (array $attribute) => [$attribute['name'] ?? '', $attribute['value'] ?? ''])
            ->all();
        $haystack = array_merge([
            $row['name'] ?? '',
            $row['sku'] ?? '',
            $row['offer_id'] ?? '',
            $row['good_id'] ?? '',
            $row['variation_id'] ?? '',
        ], $attributes);

        return collect($haystack)->contains(fn ($value) => str_contains(mb_strtolower((string) $value), $needle));
    }

    private function siteLogoUrl(): ?string
    {
        $settings = Setting::query()
            ->whereIn('key', ['main_site', 'site_logo'])
            ->pluck('value', 'key');
        $logo = trim((string) ($settings['site_logo'] ?? ''));

        if ($logo === '') {
            return null;
        }
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        $base = rtrim((string) ($settings['main_site'] ?? config('app.frontend_url', config('app.url'))), '/');
        return $base === '' ? null : $base.'/'.ltrim($logo, '/');
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
