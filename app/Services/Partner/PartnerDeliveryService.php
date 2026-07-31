<?php

namespace App\Services\Partner;

use App\Models\ShopCdekSettings;
use App\Models\ShopDeliveryMethod;
use App\Services\CdekService;
use App\Services\DeliveryPackageService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerDeliveryService
{
    public function __construct(
        private readonly CdekService $cdek,
        private readonly DeliveryPackageService $packages,
    ) {}

    public function calculate(array $delivery, array $items, float $itemsAmount): array
    {
        $method = isset($delivery['method_id'])
            ? ShopDeliveryMethod::query()->active()->find($delivery['method_id'])
            : ShopDeliveryMethod::getDefault();

        if (! $method) {
            throw new UnprocessableEntityHttpException('Active delivery method was not found');
        }

        Log::info('Partner delivery calculation started', [
            'delivery_method_id' => $method->id,
            'delivery_type' => $method->type,
            'items_amount' => $itemsAmount,
        ]);

        $metadata = ['type' => $method->type];
        $amount = (float) $method->getDeliveryCost($itemsAmount);

        if ($method->type === 'cdek') {
            $settings = ShopCdekSettings::getActive();
            $cityCode = (int) ($delivery['city_code'] ?? 0);
            if (! $settings || ! $settings->sender_city_code || $cityCode <= 0) {
                throw new UnprocessableEntityHttpException('CDEK delivery requires active settings and delivery.city_code');
            }

            $tariffs = $this->cdek->calculateDeliveryForPackages(
                (int) $settings->sender_city_code,
                $cityCode,
                $this->packages->fromCartItems($items, $settings),
            );
            if (! $tariffs) {
                throw new UnprocessableEntityHttpException('CDEK could not calculate delivery for the selected destination');
            }

            $requestedTariff = isset($delivery['tariff_code']) ? (string) $delivery['tariff_code'] : null;
            $tariff = $requestedTariff
                ? collect($tariffs)->first(fn (array $item): bool => (string) $item['tariff_code'] === $requestedTariff)
                : collect($tariffs)->sortBy('delivery_sum')->first();
            if (! $tariff) {
                throw new UnprocessableEntityHttpException('Requested CDEK tariff is unavailable');
            }

            $amount = (float) $tariff['delivery_sum'];
            $metadata = array_merge($metadata, [
                'city_code' => $cityCode,
                'tariff_code' => (string) $tariff['tariff_code'],
                'tariff_name' => $tariff['tariff_name'],
                'period_min' => $tariff['period_min'],
                'period_max' => $tariff['period_max'],
                'pvz_code' => $delivery['pvz_code'] ?? null,
            ]);
        }

        Log::info('Partner delivery calculation completed', [
            'delivery_method_id' => $method->id,
            'amount' => $amount,
            'tariff_code' => $metadata['tariff_code'] ?? null,
        ]);

        return ['method' => $method, 'amount' => round($amount, 2), 'metadata' => $metadata];
    }
}
