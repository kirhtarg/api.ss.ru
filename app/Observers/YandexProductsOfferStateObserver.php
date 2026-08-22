<?php

namespace App\Observers;

use App\Models\ShopGoodVariation;
use App\Services\YandexProductsOfferSyncService;
use Illuminate\Database\Eloquent\Model;

class YandexProductsOfferStateObserver
{
    /** @var array<int, array{available: bool, price: ?float, old_price: ?float}> */
    private static array $beforeStates = [];

    private const OFFER_FIELDS = [
        'is_active', 'price', 'sale_price', 'demping_price', 'show_demping',
        'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity',
    ];

    public function updating(Model $model): void
    {
        if (! $model->isDirty(self::OFFER_FIELDS)) {
            return;
        }

        $yandexSync = app(YandexProductsOfferSyncService::class);
        if (! $yandexSync->isConfigured()) {
            return;
        }

        // This observer deliberately runs before the SQL write. The feed
        // observer consumes this value after the enclosing transaction commits.
        self::$beforeStates[spl_object_id($model)] = $yandexSync->offerState($this->goodId($model));
    }

    /** @return array{available: bool, price: ?float, old_price: ?float}|null */
    public static function pullBeforeState(Model $model): ?array
    {
        $key = spl_object_id($model);
        $state = self::$beforeStates[$key] ?? null;
        unset(self::$beforeStates[$key]);

        return $state;
    }

    private function goodId(Model $model): int
    {
        return (int) ($model instanceof ShopGoodVariation ? $model->good_id : $model->id);
    }
}
