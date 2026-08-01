<?php

namespace App\Services\Partner;

use App\Models\ShopCdekSettings;
use App\Models\ShopDeliveryMethod;
use App\Services\CdekService;
use App\Services\DeliveryPackageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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

        return [
            'method' => $method,
            'amount' => round($amount, 2),
            'metadata' => $metadata,
            'available_tariffs' => isset($tariffs) ? $this->normalizeTariffs($tariffs) : [],
        ];
    }

    public function cities(string $query): array
    {
        return Cache::remember('partner:cdek:cities:'.hash('sha256', mb_strtolower($query)), now()->addMinutes(10), function () use ($query): array {
            Log::debug('Partner delivery cities cache miss', ['query_length' => mb_strlen($query)]);

            return collect($this->cdek->getCities($query) ?: [])->map(fn (array $city): array => [
                'code' => isset($city['code']) ? (int) $city['code'] : null,
                'name' => $city['city'] ?? $city['name'] ?? null,
                'region' => $city['region'] ?? null,
                'country_code' => $city['country_code'] ?? 'RU',
            ])->filter(fn (array $city) => $city['code'] && $city['name'])->values()->all();
        });
    }

    public function pickupPoints(int $cityCode): array
    {
        return Cache::remember('partner:cdek:pvz:'.$cityCode, now()->addMinutes(10), function () use ($cityCode): array {
            Log::debug('Partner delivery pickup points cache miss', ['city_code' => $cityCode]);

            return collect($this->cdek->getPickupPoints($cityCode) ?: [])->map(fn (array $point): array => [
                'code' => $point['code'] ?? null,
                'name' => $point['name'] ?? null,
                'address' => $point['location']['address_full'] ?? $point['location']['address'] ?? $point['address'] ?? null,
                'latitude' => $point['location']['latitude'] ?? null,
                'longitude' => $point['location']['longitude'] ?? null,
                'work_time' => $point['work_time'] ?? null,
                'type' => $point['type'] ?? null,
            ])->filter(fn (array $point) => $point['code'])->values()->all();
        });
    }

    public function validatePickupPoint(int $cityCode, string $pvzCode): ?array
    {
        return collect($this->pickupPoints($cityCode))->first(fn (array $point): bool => hash_equals((string) $point['code'], $pvzCode));
    }

    private function normalizeTariffs(array $tariffs): array
    {
        return collect($tariffs)->map(fn (array $tariff): array => [
            'code' => (string) ($tariff['tariff_code'] ?? ''),
            'name' => $tariff['tariff_name'] ?? null,
            'amount' => round((float) ($tariff['delivery_sum'] ?? 0), 2),
            'currency' => 'RUB',
            'period_min' => isset($tariff['period_min']) ? (int) $tariff['period_min'] : null,
            'period_max' => isset($tariff['period_max']) ? (int) $tariff['period_max'] : null,
        ])->filter(fn (array $tariff) => $tariff['code'] !== '')->values()->all();
    }
}
