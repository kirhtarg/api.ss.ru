<?php

namespace App\Jobs;

use App\Models\ShopOzonDeliveryPoint;
use App\Models\ShopOzonDeliveryPointSyncRun;
use App\Services\OzonDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncOzonDeliveryPickupPointsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(OzonDeliveryService $ozon): void
    {
        $run = ShopOzonDeliveryPointSyncRun::findOrFail($this->runId);
        $run->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            $settings = $ozon->getSettingsForPickupPointSync();
            $cursor = null;
            $seenCursors = [];
            $pageCount = 0;
            $pointCount = 0;

            do {
                if (++$pageCount > 10000) {
                    throw new \RuntimeException('Синхронизация остановлена: превышен защитный лимит страниц Ozon.');
                }

                $page = $ozon->request($settings, '/v1/delivery-point/list', [
                    'pagination' => ['cursor' => $cursor, 'limit' => 100],
                ]);
                $summaries = collect((array) data_get($page, 'delivery_points', []))
                    ->filter(fn ($point) => is_array($point) && ! empty($point['delivery_point_id']))
                    ->keyBy(fn ($point) => (string) $point['delivery_point_id']);
                $detailsById = collect();

                foreach ($summaries->keys()->chunk(100) as $ids) {
                    $details = $ozon->request($settings, '/v1/delivery-point/info', [
                        'delivery_point_ids' => $ids->map(fn ($id) => (int) $id)->values()->all(),
                    ]);
                    $detailsById = $detailsById->merge(collect((array) data_get($details, 'delivery_points', []))
                        ->filter(fn ($point) => is_array($point) && ! empty($point['delivery_point_id']))
                        ->keyBy(fn ($point) => (string) $point['delivery_point_id']));
                }

                $rows = [];
                foreach ($summaries as $id => $summary) {
                    $point = array_replace_recursive($summary, (array) $detailsById->get((string) $id, []));
                    $point['delivery_point_id'] = (int) $id;
                    $searchText = $this->searchText($point);
                    $rows[] = [
                        'delivery_point_id' => (int) $id,
                        'name' => mb_substr((string) ($point['name'] ?? $point['type'] ?? ''), 0, 255),
                        'full_address' => $this->address($point),
                        'search_text' => $searchText,
                        'shipment_method_ids' => json_encode(array_values((array) ($point['shipment_method_ids'] ?? [])), JSON_UNESCAPED_UNICODE),
                        'point_data' => json_encode($point, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'is_active' => ! in_array($point['is_active'] ?? true, [false, 0, '0', 'false'], true),
                        'last_sync_run_id' => $run->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('shop_ozon_delivery_points')->upsert(
                        $chunk,
                        ['delivery_point_id'],
                        ['name', 'full_address', 'search_text', 'shipment_method_ids', 'point_data', 'is_active', 'last_sync_run_id', 'updated_at']
                    );
                }

                $pointCount += count($rows);
                $run->update(['pages_synced' => $pageCount, 'points_synced' => $pointCount]);
                $nextCursor = data_get($page, 'next_cursor');
                if (! is_string($nextCursor) || trim($nextCursor) === '' || isset($seenCursors[$nextCursor])) {
                    break;
                }
                $seenCursors[$nextCursor] = true;
                $cursor = $nextCursor;
            } while (true);

            if ($pointCount === 0) {
                throw new \RuntimeException('Ozon вернул пустой каталог ПВЗ. Существующий локальный справочник сохранен.');
            }

            DB::transaction(function () use ($run, $pageCount, $pointCount): void {
                ShopOzonDeliveryPoint::query()
                    ->where(function ($query) use ($run): void {
                        $query->whereNull('last_sync_run_id')->orWhere('last_sync_run_id', '!=', $run->id);
                    })
                    ->delete();
                $run->update([
                    'status' => 'completed',
                    'pages_synced' => $pageCount,
                    'points_synced' => $pointCount,
                    'finished_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function searchText(array $point): string
    {
        $values = [];
        $append = static function ($value) use (&$values, &$append): void {
            if (is_scalar($value)) {
                $values[] = (string) $value;
            } elseif (is_array($value)) {
                foreach ($value as $nested) {
                    $append($nested);
                }
            }
        };
        foreach (['city', 'name', 'full_address', 'address', 'location', 'region', 'settlement'] as $key) {
            if (array_key_exists($key, $point)) {
                $append($point[$key]);
            }
        }

        return mb_strtolower(implode(' ', $values));
    }

    private function address(array $point): ?string
    {
        foreach (['full_address', 'address'] as $key) {
            $value = $point[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) {
                $parts = [];
                array_walk_recursive($value, static function ($item) use (&$parts): void {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        $parts[] = trim((string) $item);
                    }
                });
                if ($parts) {
                    return implode(', ', array_unique($parts));
                }
            }
        }

        return null;
    }
}
