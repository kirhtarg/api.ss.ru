<?php

namespace App\Jobs;

use App\Services\YandexProductsOfferSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncYandexProductsOfferJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 90;

    public function __construct(public int $goodId) {}

    public function uniqueId(): string
    {
        return (string) $this->goodId;
    }

    public function handle(YandexProductsOfferSyncService $service): void
    {
        $service->syncGood($this->goodId);
    }
}
