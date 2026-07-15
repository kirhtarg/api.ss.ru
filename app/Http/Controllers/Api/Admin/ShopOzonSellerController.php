<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOzonSyncRunJob;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopOzonAccount;
use App\Models\ShopOzonCategoryMapping;
use App\Models\ShopOzonSyncRun;
use App\Services\Ozon\OzonProductPayloadBuilder;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            'api_url' => 'required|url|max:500', 'warehouse_id' => 'nullable|string|max:255', 'image_base_url' => 'required|url|max:500',
            'vat' => 'required|string|max:20', 'is_active' => 'boolean',
        ]);
        $account = ShopOzonAccount::query()->first() ?? new ShopOzonAccount;
        if (! filled($data['api_key'] ?? null)) unset($data['api_key']);
        if (! $account->exists && empty($data['api_key'])) return response()->json(['success' => false, 'message' => 'Укажите API-ключ Ozon.'], 422);
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function localCategories()
    {
        $items = ShopCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function ozonCategories(Request $request)
    {
        $language = $request->string('language', 'DEFAULT')->toString();
        $key = "ozon:{$this->account()->id}:category-tree:{$language}";
        if ($request->boolean('refresh')) Cache::forget($key);
        $data = Cache::remember($key, now()->addHours(12), fn () => (new OzonSellerClient($this->account()))->post('/v1/description-category/tree', ['language' => $language]));
        return response()->json(['success' => true, 'data' => data_get($data, 'result', $data)]);
    }

    public function attributes(Request $request)
    {
        $input = $request->validate(['description_category_id' => 'required|integer', 'type_id' => 'required|integer', 'language' => 'nullable|string']);
        $key = "ozon:{$this->account()->id}:attributes:{$input['description_category_id']}:{$input['type_id']}";
        $data = Cache::remember($key, now()->addHours(12), fn () => (new OzonSellerClient($this->account()))->post('/v1/description-category/attribute', [
            'description_category_id' => (int) $input['description_category_id'], 'type_id' => (int) $input['type_id'], 'language' => $input['language'] ?? 'DEFAULT',
        ]));
        return response()->json(['success' => true, 'data' => data_get($data, 'result', $data)]);
    }

    public function attributeValues(Request $request)
    {
        $input = $request->validate(['description_category_id' => 'required|integer', 'type_id' => 'required|integer', 'attribute_id' => 'required|integer', 'last_value_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:5000']);
        $data = (new OzonSellerClient($this->account()))->post('/v1/description-category/attribute/values', array_merge($input, ['language' => 'DEFAULT', 'limit' => $input['limit'] ?? 1000]));
        return response()->json(['success' => true, 'data' => data_get($data, 'result', $data)]);
    }

    public function mappings()
    {
        $items = ShopOzonCategoryMapping::query()->where('account_id', $this->account()->id)->with('category:id,name')->orderByDesc('id')->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer', 'category_id' => 'required|exists:shop_categories,id', 'description_category_id' => 'required|integer',
            'type_id' => 'required|integer', 'ozon_category_name' => 'nullable|string|max:500', 'attribute_mappings' => 'nullable|array', 'is_active' => 'boolean',
        ]);
        $mapping = ShopOzonCategoryMapping::updateOrCreate(
            ['account_id' => $this->account()->id, 'category_id' => $data['category_id']],
            array_merge($data, ['account_id' => $this->account()->id])
        );
        return response()->json(['success' => true, 'message' => 'Сопоставление сохранено.', 'data' => $mapping->load('category:id,name')]);
    }

    public function deleteMapping(ShopOzonCategoryMapping $mapping)
    {
        abort_unless($mapping->account_id === $this->account()->id, 404);
        $mapping->delete();
        return response()->json(['success' => true]);
    }

    public function preview(Request $request, OzonProductPayloadBuilder $builder)
    {
        $ids = $request->validate(['good_ids' => 'required|array|min:1|max:50', 'good_ids.*' => 'integer'])['good_ids'];
        $account = $this->account();
        $result = [];
        $goods = ShopGood::with(['categories', 'images', 'variations.images'])->whereIn('id', $ids)->get();
        foreach ($goods as $good) {
            $variations = $good->variations->where('is_active', true);
            if ($variations->isEmpty()) $result[] = array_merge(['good_id' => $good->id, 'variation_id' => null], $builder->build($good, null, $account));
            else foreach ($variations as $variation) $result[] = array_merge(['good_id' => $good->id, 'variation_id' => $variation->id], $builder->build($good, $variation, $account));
        }
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function startSync(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:products,prices,stocks', 'good_ids' => 'nullable|array', 'good_ids.*' => 'integer|exists:shop_goods,id']);
        $run = ShopOzonSyncRun::create(['account_id' => $this->account()->id, 'user_id' => $request->user()?->id, 'mode' => $data['mode'], 'good_ids' => $data['good_ids'] ?? null, 'status' => 'pending']);
        ProcessOzonSyncRunJob::dispatch($run->id);
        return response()->json(['success' => true, 'message' => 'Синхронизация поставлена в очередь.', 'data' => $run], 202);
    }

    public function runs()
    {
        return response()->json(['success' => true, 'data' => ShopOzonSyncRun::where('account_id', $this->account()->id)->latest()->limit(30)->get()]);
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
}
