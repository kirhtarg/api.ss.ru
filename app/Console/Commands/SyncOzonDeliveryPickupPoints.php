<?php

namespace App\Console\Commands;

use App\Models\ShopCarrierDeliverySettings;
use App\Services\OzonDeliveryPickupPointSyncService;
use Illuminate\Console\Command;

class SyncOzonDeliveryPickupPoints extends Command
{
    protected $signature = 'ozon:sync-delivery-pickup-points';

    protected $description = 'Queue synchronization of Ozon Delivery pickup points';

    public function handle(OzonDeliveryPickupPointSyncService $sync): int
    {
        $settings = ShopCarrierDeliverySettings::query()->where('carrier', 'ozon')->first();
        if (! $settings || ! $settings->is_active || blank($settings->oauth_client_id) || blank($settings->oauth_client_secret)) {
            $this->line('Ozon Delivery is not configured and active; skipping scheduled pickup-point sync.');

            return self::SUCCESS;
        }

        try {
            $run = $sync->start(null, onlyIfStale: true);
            $this->info('Ozon pickup-point synchronization status: '.$run->status.'.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
