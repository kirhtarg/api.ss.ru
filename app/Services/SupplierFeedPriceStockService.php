<?php

namespace App\Services;

use App\Jobs\SyncSupplierFeedPriceStockJob;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\SupplierCatalogProfile;
use App\Models\SupplierFeedPriceStockChange;
use App\Models\SupplierFeedPriceStockRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use XMLReader;

class SupplierFeedPriceStockService
{
    private const PRICE_FIELDS = ['price', 'sale_price', 'demping_price'];
    private const STOCK_FIELDS = ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function queue(SupplierCatalogProfile $profile, string $trigger = 'manual', string $mode = 'sync'): SupplierFeedPriceStockRun
    {
        $this->validateSettings($profile);
        if (! in_array($mode, ['preview', 'sync'], true)) {
            throw new \InvalidArgumentException('Некорректный режим проверки фида.');
        }
        $run = SupplierFeedPriceStockRun::create([
            'profile_id' => $profile->id,
            'status' => 'queued',
            'trigger' => $trigger,
            'mode' => $mode,
        ]);
        SyncSupplierFeedPriceStockJob::dispatch($run->id);

        return $run;
    }

    public function scheduleDueRuns(): void
    {
        SupplierCatalogProfile::query()->active()->get()->each(function (SupplierCatalogProfile $profile): void {
            $settings = $profile->settings ?? [];
            if (! ($settings['feed_sync_enabled'] ?? false) || empty($settings['yml_url'])) {
                return;
            }
            $minutes = max(5, min(1440, (int) ($settings['feed_sync_interval_minutes'] ?? 60)));
            $lastRun = SupplierFeedPriceStockRun::query()
                ->where('profile_id', $profile->id)
                ->whereIn('status', ['completed', 'failed'])
                ->latest('finished_at')
                ->first();
            if ($lastRun?->finished_at && $lastRun->finished_at->gt(now()->subMinutes($minutes))) {
                return;
            }
            if (SupplierFeedPriceStockRun::query()->where('profile_id', $profile->id)->whereIn('status', ['queued', 'running'])->exists()) {
                return;
            }
            $this->queue($profile, 'scheduled');
        });
    }

    public function run(int $runId): void
    {
        $run = SupplierFeedPriceStockRun::find($runId);
        if (! $run || $run->status === 'completed') {
            return;
        }
        $profile = $run->profile;
        if (! $profile) {
            return;
        }
        $lock = Cache::lock('supplier-feed-price-stock:'.$profile->id, 600);
        if (! $lock->get()) {
            $run->update(['status' => 'queued']);
            SyncSupplierFeedPriceStockJob::dispatch($run->id)->delay(now()->addSeconds(30));
            return;
        }

        try {
            $this->validateSettings($profile);
            $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);
            $feed = $this->offers($profile);
            $offers = $feed['items'];
            if ($offers === []) {
                throw new \RuntimeException('В фиде не найдено ни одного предложения offer. Рабочая база не изменена.');
            }
            $result = $this->synchronize($profile, $offers, $run->id, $run->mode !== 'preview');
            $run->update([
                'status' => 'completed',
                'offers_total' => $feed['total'],
                'offers_without_sku' => $feed['without_sku'],
                ...$result,
                'finished_at' => now(),
            ]);
            $this->notifications->notifySupplierFeedSync($run->fresh());
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
            $this->notifications->notifySupplierFeedSync($run->fresh());
            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }

    /** @return array{items: array<int, array{sku: string, price: ?string, available: ?bool}>, total: int, without_sku: int} */
    private function offers(SupplierCatalogProfile $profile): array
    {
        $url = trim((string) data_get($profile->settings, 'yml_url'));
        $response = Http::timeout(90)->connectTimeout(15)->accept('application/xml,text/xml,*/*')->get($url);
        if (! $response->successful() || trim($response->body()) === '') {
            throw new \RuntimeException('Не удалось получить фид: HTTP '.$response->status());
        }

        libxml_use_internal_errors(true);
        $reader = new XMLReader();
        if (! $reader->XML($response->body(), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Фид не является корректным XML/YML.');
        }
        $skuSource = data_get($profile->settings, 'feed_sku_source', 'offer_id');
        $skuParams = collect(explode(',', (string) data_get($profile->settings, 'feed_sku_param', 'Артикул')))
            ->map(fn (string $value) => mb_strtolower(trim($value)))
            ->filter()
            ->values()
            ->all();
        $offers = [];
        $total = 0;
        $withoutSku = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'offer') {
                continue;
            }
            $total++;
            $xml = @simplexml_load_string($reader->readOuterXml());
            if (! $xml) {
                continue;
            }
            $id = trim((string) ($xml['id'] ?? ''));
            $vendorCode = trim((string) ($xml->vendorCode ?? ''));
            $paramSku = null;
            if ($skuSource === 'param') {
                $paramValues = [];
                foreach ($xml->param as $param) {
                    $paramName = mb_strtolower(trim((string) ($param['name'] ?? '')));
                    $paramValue = trim((string) $param);
                    if ($paramName !== '' && $paramValue !== '') {
                        $paramValues[$paramName] = $paramValue;
                    }
                }
                foreach ($skuParams as $skuParam) {
                    if (isset($paramValues[$skuParam])) {
                        $paramSku = $paramValues[$skuParam];
                        break;
                    }
                }
            }
            $sku = match ($skuSource) {
                'vendor_code' => $vendorCode,
                'param' => $paramSku ?? '',
                default => $id,
            };
            if ($sku === '') {
                $withoutSku++;
                continue;
            }
            $availableValue = mb_strtolower(trim((string) ($xml['available'] ?? '')));
            $available = $availableValue === '' ? null : in_array($availableValue, ['true', '1', 'yes', 'y', 'да'], true);
            $price = trim((string) ($xml->price ?? ''));
            $offers[] = ['sku' => $sku, 'price' => $price === '' ? null : $price, 'available' => $available];
        }
        $reader->close();

        return ['items' => $offers, 'total' => $total, 'without_sku' => $withoutSku];
    }

    /** @param array<int, array{sku: string, price: ?string, available: ?bool}> $offers */
    private function synchronize(SupplierCatalogProfile $profile, array $offers, int $runId, bool $apply): array
    {
        $settings = $profile->settings ?? [];
        $priceField = (string) ($settings['feed_price_target'] ?? 'price');
        $stockField = (string) ($settings['feed_stock_target'] ?? 'stock_quantity');
        $availableQuantity = max(1, (int) ($settings['feed_available_quantity'] ?? 1));
        $supplierNames = array_values(array_filter((array) $profile->supplier_names));
        $skus = collect($offers)->pluck('sku')->map(fn ($sku) => trim((string) $sku))->unique()->values();
        $variations = ShopGoodVariation::query()
            ->whereIn('supplier', $supplierNames)
            ->whereIn('sku', $skus)
            ->with('good:id,name')
            ->get()
            ->keyBy(fn (ShopGoodVariation $item) => trim((string) $item->sku));
        $goods = ShopGood::query()->whereIn('supplier', $supplierNames)->whereIn('sku', $skus)->get()->keyBy(fn (ShopGood $item) => trim((string) $item->sku));
        $summary = ['matched_skus' => [], 'not_found_skus' => []];
        $counters = ['matched' => 0, 'updated_prices' => 0, 'updated_stocks' => 0, 'unchanged' => 0, 'not_found' => 0];

        DB::transaction(function () use ($offers, $variations, $goods, $priceField, $stockField, $availableQuantity, $runId, $apply, &$summary, &$counters): void {
            $changeRows = [];
            foreach ($offers as $offer) {
                $sku = trim($offer['sku']);
                $model = $variations->get($sku) ?? $goods->get($sku);
                if (! $model) {
                    $counters['not_found']++;
                    if (count($summary['not_found_skus']) < 100) $summary['not_found_skus'][] = $sku;
                    $changeRows[] = [
                        'run_id' => $runId, 'sku' => $sku, 'entity_type' => 'none',
                        'good_id' => null, 'variation_id' => null, 'good_name' => null,
                        'field' => 'match', 'status' => 'not_found',
                        'before_value' => null, 'after_value' => null, 'is_applied' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    if (count($changeRows) >= 500) {
                        SupplierFeedPriceStockChange::query()->insert($changeRows);
                        $changeRows = [];
                    }
                    continue;
                }
                $counters['matched']++;
                if (count($summary['matched_skus']) < 100) $summary['matched_skus'][] = $sku;
                $changes = [];
                if ($offer['price'] !== null) {
                    $price = $this->decimal($offer['price']);
                    if ($price !== null) {
                        $isDifferent = (string) $model->{$priceField} !== $price;
                        if ($isDifferent) {
                            $changes[$priceField] = $price;
                            $counters['updated_prices']++;
                        }
                        $changeRows[] = $this->feedReportRow($runId, $sku, $model, $priceField, $price, $isDifferent ? 'different' : 'match', $apply && $isDifferent);
                    }
                }
                if ($offer['available'] !== null) {
                    $stock = $offer['available'] ? $availableQuantity : 0;
                    $isDifferent = (string) $model->{$stockField} !== (string) $stock;
                    if ($isDifferent) {
                        $changes[$stockField] = $stock;
                        $counters['updated_stocks']++;
                    }
                    $changeRows[] = $this->feedReportRow($runId, $sku, $model, $stockField, $stock, $isDifferent ? 'different' : 'match', $apply && $isDifferent);
                }
                if ($changes === []) {
                    $counters['unchanged']++;
                } elseif ($apply) {
                    $model->update($changes);
                }
                if (count($changeRows) >= 500) {
                    SupplierFeedPriceStockChange::query()->insert($changeRows);
                    $changeRows = [];
                }
            }
            if ($changeRows !== []) {
                SupplierFeedPriceStockChange::query()->insert($changeRows);
            }
        });

        return [...$counters, 'summary' => $summary];
    }

    /** @return array<string, mixed> */
    private function feedReportRow(int $runId, string $sku, ShopGood|ShopGoodVariation $model, string $field, mixed $after, string $status, bool $isApplied): array
    {
        return [
            'run_id' => $runId,
            'sku' => $sku,
            'entity_type' => $model instanceof ShopGoodVariation ? 'variation' : 'good',
            'good_id' => $model instanceof ShopGoodVariation ? $model->good_id : $model->id,
            'variation_id' => $model instanceof ShopGoodVariation ? $model->id : null,
            'good_name' => $model instanceof ShopGoodVariation ? $model->good?->name : $model->name,
            'field' => $field,
            'status' => $status,
            'before_value' => (string) ($model->{$field} ?? ''),
            'after_value' => (string) $after,
            'is_applied' => $isApplied,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function decimal(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));
        if (! is_numeric($value)) return null;
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function validateSettings(SupplierCatalogProfile $profile): void
    {
        $settings = $profile->settings ?? [];
        if (empty($settings['yml_url'])) throw new \InvalidArgumentException('Не указан URL фида.');
        if (! in_array($settings['feed_price_target'] ?? 'price', self::PRICE_FIELDS, true)) throw new \InvalidArgumentException('Некорректно назначено поле цены фида.');
        if (! in_array($settings['feed_stock_target'] ?? 'stock_quantity', self::STOCK_FIELDS, true)) throw new \InvalidArgumentException('Некорректно назначено поле остатка фида.');
        if (! in_array($settings['feed_sku_source'] ?? 'offer_id', ['offer_id', 'vendor_code', 'param'], true)) throw new \InvalidArgumentException('Некорректно назначено поле SKU фида.');
        if (($settings['feed_sku_source'] ?? 'offer_id') === 'param' && trim((string) ($settings['feed_sku_param'] ?? '')) === '') throw new \InvalidArgumentException('Укажите название параметра фида для SKU.');
    }
}
