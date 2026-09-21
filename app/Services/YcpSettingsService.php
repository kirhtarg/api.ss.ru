<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class YcpSettingsService
{
    public function get(): array
    {
        $settings = Setting::query()->where('group', 'ycp')->pluck('value', 'key');
        $encrypted = (string) ($settings['access_token'] ?? '');
        $token = '';
        if ($encrypted !== '') {
            try {
                $token = Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                // Do not break settings screens if a previous value was plain text.
                $token = $encrypted;
            }
        }

        $deliveryMode = $settings['delivery_mode'] ?? 'merchant';

        return [
            'enabled' => ($settings['enabled'] ?? '0') === '1',
            'access_token' => $token,
            'delivery_mode' => in_array($deliveryMode, ['merchant', 'yandex'], true)
                ? $deliveryMode : 'merchant',
            'api_url' => rtrim(config('app.url'), '/').'/api/v1',
        ];
    }

    public function save(array $data): array
    {
        $current = $this->get();
        $token = trim((string) ($data['access_token'] ?? '')) ?: $current['access_token'];

        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'enabled'],
            ['name' => 'YCP enabled', 'value' => $data['enabled'] ? '1' : '0', 'type' => 'boolean']
        );
        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'access_token'],
            ['name' => 'YCP access token', 'value' => $token !== '' ? Crypt::encryptString($token) : '', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'delivery_mode'],
            ['name' => 'YCP delivery calculation mode', 'value' => $data['delivery_mode'] ?? 'merchant', 'type' => 'string']
        );

        return $this->get();
    }
}
