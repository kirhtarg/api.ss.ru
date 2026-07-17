<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessYandexMarketSellerSyncJob;
use App\Models\Setting;
use App\Models\ShopCategory;
use App\Models\ShopTag;
use App\Models\ShopVariationAttribute;
use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketCategoryMapping;
use App\Models\ShopYandexMarketProductBinding;
use App\Models\ShopYandexMarketSyncItem;
use App\Models\ShopYandexMarketSyncRun;
use App\Services\YandexMarket\YandexMarketClient;
use App\Services\YandexMarket\YandexMarketPayloadBuilder;
use App\Services\YandexMarket\YandexMarketProductResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ]);
        $account = $this->account();
        $query = \App\Models\ShopGood::query()->with(['brands:id,name', 'properties.values', 'variations.attributeValues.attribute'])
            ->where('is_active', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('shop_categories.id', $data['category_ids']));
        $tagId = (int) ($data['selection_tag_id'] ?? $account->selection_tag_id);
        if ($tagId) $query->whereHas('tags', fn ($q) => $q->where('shop_tags.id', $tagId));
        $counts = [];
        $mapping = ['source' => $data['source'], 'source_key' => $data['source_key'] ?? null];
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
        return response()->json(['success' => true, 'data' => collect($counts)->map(fn ($count, $value) => ['value' => $value, 'count' => $count])->values()]);
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
        return response()->json(['success' => true, 'data' => ['parameters' => $parameters, 'allow_variations' => (bool) ($result['allowVariations'] ?? true)]]);
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
        ]);
        $account = $this->account();
        $mapping = isset($data['id']) ? ShopYandexMarketCategoryMapping::query()->where('account_id', $account->id)->findOrFail($data['id']) : new ShopYandexMarketCategoryMapping(['account_id' => $account->id]);
        unset($data['id']);
        $mapping->fill($data)->save();
        return response()->json(['success' => true, 'message' => 'Профиль категории сохранен.', 'data' => $mapping]);
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
    private function parameterData(array $item): array { return ['id' => (int) ($item['id'] ?? 0), 'name' => html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'description' => $item['description'] ?? '', 'type' => $item['type'] ?? 'TEXT', 'required' => (bool) ($item['required'] ?? $item['isRequired'] ?? false), 'distinctive' => (bool) ($item['distinctive'] ?? $item['isDistinctive'] ?? false), 'multivalue' => (bool) ($item['multivalue'] ?? $item['isMultivalue'] ?? false), 'allow_custom_values' => (bool) ($item['allowCustomValues'] ?? $item['allow_custom_values'] ?? true), 'values' => collect($item['values'] ?? [])->map(fn ($value) => ['id' => (int) ($value['id'] ?? 0), 'value' => html_entity_decode((string) ($value['value'] ?? $value['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')])->values(), 'units' => data_get($item, 'unit.units', []), 'default_unit_id' => data_get($item, 'unit.defaultUnitId')]; }
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
