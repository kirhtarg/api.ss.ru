<?php

namespace App\Services;

use App\Models\ShopCarrierDeliverySettings;
use App\Models\ShopCdekSettings;
use App\Models\ShopDeliveryMethod;
use App\Models\ShopDellinSettings;
use App\Models\ShopDpdSettings;
use App\Models\ShopRussianPostSettings;
use Illuminate\Database\Eloquent\Model;

class ShopDeliveryActivitySyncService
{
    private array $carrierTypes = [
        'cdek' => ['cdek'],
        'dellin' => ['dellin'],
        'russianpost' => ['russianpost', 'post', 'ems'],
        'dpd' => ['dpd'],
        'yandex' => ['yandex'],
        'ozon' => ['ozon'],
    ];

    public function applyMethodActiveToSettings(ShopDeliveryMethod $method): void
    {
        $carrier = $this->carrierByMethodType((string) $method->type);
        if (! $carrier) {
            return;
        }

        $settings = $this->getExistingSettings($carrier);
        if ($settings) {
            $settings->update(['is_active' => (bool) $method->is_active]);
        }
    }

    public function applySettingsActiveToMethod(string $carrier, bool $isActive): void
    {
        $method = $this->findDeliveryMethodForCarrier($carrier);
        if ($method) {
            $method->update(['is_active' => $isActive]);
        }
    }

    public function mergeMethodActive(string $carrier, ?Model $settings): ?Model
    {
        if (! $settings) {
            return $settings;
        }

        $method = $this->findDeliveryMethodForCarrier($carrier);
        if ($method) {
            $settings->setAttribute('is_active', (bool) $method->is_active);
        }

        return $settings;
    }

    public function getMethodActive(string $carrier): ?bool
    {
        $method = $this->findDeliveryMethodForCarrier($carrier);

        return $method ? (bool) $method->is_active : null;
    }

    public function carrierByMethodType(string $type): ?string
    {
        foreach ($this->carrierTypes as $carrier => $types) {
            if (in_array($type, $types, true)) {
                return $carrier;
            }
        }

        return null;
    }

    private function findDeliveryMethodForCarrier(string $carrier): ?ShopDeliveryMethod
    {
        $types = $this->carrierTypes[$carrier] ?? [$carrier];
        $primaryType = $types[0] ?? $carrier;

        return ShopDeliveryMethod::where(function ($query) use ($types, $carrier) {
                $query->whereIn('type', $types)
                    ->orWhere('type', 'like', '%'.$carrier.'%')
                    ->orWhere('name', 'like', '%'.$this->carrierNamePattern($carrier).'%')
                    ->orWhere('settings->path', 'like', '%'.$carrier.'%');
            })
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [$primaryType])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function carrierNamePattern(string $carrier): string
    {
        return match ($carrier) {
            'cdek' => 'СДЭК',
            'dellin' => 'Деловые',
            'russianpost' => 'Почта',
            'dpd' => 'DPD',
            'yandex' => 'Яндекс',
            'ozon' => 'Ozon',
            default => $carrier,
        };
    }

    private function getExistingSettings(string $carrier): ?Model
    {
        return match ($carrier) {
            'cdek' => ShopCdekSettings::first(),
            'dellin' => ShopDellinSettings::first(),
            'russianpost' => ShopRussianPostSettings::first(),
            'dpd' => ShopDpdSettings::first(),
            'yandex', 'ozon' => ShopCarrierDeliverySettings::where('carrier', $carrier)->first(),
            default => null,
        };
    }
}
