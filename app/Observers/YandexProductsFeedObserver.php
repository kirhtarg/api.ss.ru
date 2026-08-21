<?php

namespace App\Observers;

use App\Jobs\RefreshYmlFeedJob;
use App\Services\YandexProductsOfferSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class YandexProductsFeedObserver implements ShouldHandleEventsAfterCommit
{
    private const OFFER_FIELDS = [
        'is_active', 'price', 'sale_price', 'demping_price', 'show_demping',
        'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity',
    ];

    public function created(Model $model): void
    {
        $this->queue($model);
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged(self::OFFER_FIELDS)) {
            $this->queue($model);
        }
    }

    public function deleted(Model $model): void
    {
        $goodId = $this->goodId($model);
        RefreshYmlFeedJob::dispatch()->delay(now()->addSeconds(20));
        app(YandexProductsOfferSyncService::class)->queueGood($goodId);
    }

    private function queue(Model $model): void
    {
        RefreshYmlFeedJob::dispatch()->delay(now()->addSeconds(20));
        app(YandexProductsOfferSyncService::class)->queueGood($this->goodId($model));
    }

    private function goodId(Model $model): int
    {
        return (int) ($model instanceof \App\Models\ShopGoodVariation ? $model->good_id : $model->id);
    }
}
