<?php

namespace App\Services;

use App\Jobs\SyncOzonDeliveryPickupPointsJob;
use App\Models\ShopOzonDeliveryPoint;
use App\Models\ShopOzonDeliveryPointSyncRun;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class OzonDeliveryPickupPointSyncService
{
    public function status(): array
    {
        $latest = ShopOzonDeliveryPointSyncRun::query()->latest('id')->first();

        return [
            'points_count' => ShopOzonDeliveryPoint::query()->count(),
            'active_points_count' => ShopOzonDeliveryPoint::query()->where('is_active', true)->count(),
            'last_success_at' => ShopOzonDeliveryPointSyncRun::query()->where('status', 'completed')->latest('id')->value('finished_at'),
            'run' => $latest?->toArray(),
        ];
    }

    public function start(?int $userId = null, bool $onlyIfStale = false): ShopOzonDeliveryPointSyncRun
    {
        ShopOzonDeliveryPointSyncRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subHours(3))
            ->update([
                'status' => 'failed',
                'error_message' => 'Синхронизация не завершилась за 3 часа; разрешен повторный запуск.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $active = ShopOzonDeliveryPointSyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($active) {
            return $active;
        }

        $lastSuccess = ShopOzonDeliveryPointSyncRun::query()->where('status', 'completed')->latest('id')->first();
        if ($onlyIfStale && $lastSuccess?->finished_at?->gt(now()->subDay())) {
            return $lastSuccess;
        }

        if (config('queue.default') === 'sync') {
            throw new RuntimeException('Для фоновой синхронизации ПВЗ включите очередь (QUEUE_CONNECTION=database или redis) и запустите queue worker.');
        }

        app(OzonDeliveryService::class)->getActiveSettings();

        $run = ShopOzonDeliveryPointSyncRun::create([
            'status' => 'queued',
            'requested_by' => $userId,
        ]);

        try {
            Bus::dispatch((new SyncOzonDeliveryPickupPointsJob($run->id))->onQueue('default'));
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $exception;
        }

        return $run->fresh();
    }
}
