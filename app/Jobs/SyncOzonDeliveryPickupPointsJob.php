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
use Illuminate\Support\Facades\Log;
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
            $totalPoints = 0;
            $pageLimit = 100;

            do {
                if (++$pageCount > 10000) {
                    throw new \RuntimeException('Синхронизация остановлена: превышен защитный лимит страниц Ozon.');
                }

                $pageResult = $this->fetchPointListPage($ozon, $settings, $cursor, $pageLimit);
                $page = $pageResult['page'];
                $pageLimit = $pageResult['limit'];
                $reportedTotal = data_get($page, 'delivery_points_count')
                    ?? data_get($page, 'total_count')
                    ?? data_get($page, 'total');
                if (is_numeric($reportedTotal) && (int) $reportedTotal > 0) {
                    $totalPoints = max($totalPoints, (int) $reportedTotal);
                }
                $summaries = collect((array) data_get($page, 'delivery_points', []))
                    ->filter(fn ($point) => is_array($point) && ! empty($point['delivery_point_id']))
                    ->keyBy(fn ($point) => (string) $point['delivery_point_id']);
                $detailsById = collect();

                foreach ($summaries->keys()->chunk(100) as $ids) {
                    $detailsById = $detailsById->merge(collect($this->fetchPointDetails($ozon, $settings, $ids->map(fn ($id) => (int) $id)->values()->all()))
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
                $run->update([
                    'pages_synced' => $pageCount,
                    'points_synced' => $pointCount,
                    'total_points' => $totalPoints > 0 ? $totalPoints : null,
                ]);
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

            $totalPoints = $totalPoints > 0 ? $totalPoints : $pointCount;
            DB::transaction(function () use ($run, $pageCount, $pointCount, $totalPoints): void {
                ShopOzonDeliveryPoint::query()
                    ->where(function ($query) use ($run): void {
                        $query->whereNull('last_sync_run_id')->orWhere('last_sync_run_id', '!=', $run->id);
                    })
                    ->delete();
                $run->update([
                    'status' => 'completed',
                    'pages_synced' => $pageCount,
                    'points_synced' => $pointCount,
                    'total_points' => $totalPoints,
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

    /**
     * Ozon's info endpoint may time out on a large batch. Start with small
     * groups, and split a timed-out request recursively so one slow response
     * doesn't discard the whole directory synchronization.
     */
    protected function fetchPointDetails(OzonDeliveryService $ozon, $settings, array $ids): array
    {
        try {
            $response = $ozon->request($settings, '/v1/delivery-point/info', [
                'delivery_point_ids' => $ids,
            ], timeoutSeconds: 60);

            return array_values(array_filter((array) data_get($response, 'delivery_points', []), static fn ($point) => is_array($point) && ! empty($point['delivery_point_id'])
            ));
        } catch (Throwable $exception) {
            if (! $this->isTimeoutException($exception) || count($ids) <= 1) {
                throw $exception;
            }

            $middle = (int) ceil(count($ids) / 2);

            return array_merge(
                $this->fetchPointDetails($ozon, $settings, array_slice($ids, 0, $middle)),
                $this->fetchPointDetails($ozon, $settings, array_slice($ids, $middle))
            );
        }
    }

    protected function fetchPointListPage(OzonDeliveryService $ozon, $settings, ?string $cursor, int $preferredLimit): array
    {
        $limits = array_values(array_unique(array_filter(
            [$preferredLimit, 50, 25, 10, 5, 1],
            static fn (int $limit): bool => $limit > 0 && $limit <= $preferredLimit
        )));

        foreach ($limits as $limit) {
            try {
                $page = $ozon->request($settings, '/v1/delivery-point/list', [
                    'pagination' => ['cursor' => $cursor, 'limit' => $limit],
                ], timeoutSeconds: 60);

                return ['page' => $page, 'limit' => $limit];
            } catch (Throwable $exception) {
                if (! $this->isTimeoutException($exception) || $limit === end($limits)) {
                    throw $exception;
                }

                Log::warning('Ozon Delivery pickup-point list timed out; retrying with smaller page size.', [
                    'requested_limit' => $limit,
                    'next_limit' => $limits[array_search($limit, $limits, true) + 1] ?? null,
                    'message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            }
        }

        throw new \RuntimeException('Ozon не вернул страницу списка ПВЗ.');
    }

    private function isTimeoutException(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'curl error 28') || str_contains($message, 'operation timed out') || str_contains($message, 'timed out');
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
