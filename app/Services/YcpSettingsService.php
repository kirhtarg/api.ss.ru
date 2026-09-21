<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class YcpSettingsService
{
    public function get(): array
    {
        $settings = Setting::query()->where('group', 'ycp')->pluck('value', 'key');
        $encrypted = (string) ($settings['ycp_access_token'] ?? $settings['access_token'] ?? '');
        $token = $this->decryptSecret($encrypted);
        $apiToken = $this->decryptSecret((string) ($settings['ycp_api_token'] ?? ''));

        $deliveryMode = $settings['ycp_delivery_mode'] ?? $settings['delivery_mode'] ?? 'merchant';

        return [
            'enabled' => ($settings['ycp_enabled'] ?? $settings['enabled'] ?? '0') === '1',
            'access_token' => $token,
            'api_token' => $apiToken,
            'delivery_mode' => in_array($deliveryMode, ['merchant', 'yandex'], true)
                ? $deliveryMode : 'merchant',
            'api_url' => rtrim(config('app.url'), '/').'/api/v1',
        ];
    }

    public function save(array $data): array
    {
        $current = $this->get();
        $token = trim((string) ($data['access_token'] ?? '')) ?: $current['access_token'];
        $apiToken = trim((string) ($data['api_token'] ?? '')) ?: $current['api_token'];

        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'ycp_enabled'],
            ['name' => 'YCP enabled', 'value' => $data['enabled'] ? '1' : '0', 'type' => 'boolean']
        );
        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'ycp_access_token'],
            ['name' => 'YCP access token', 'value' => $token !== '' ? Crypt::encryptString($token) : '', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'ycp_api_token'],
            ['name' => 'YCP API token', 'value' => $apiToken !== '' ? Crypt::encryptString($apiToken) : '', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'ycp', 'key' => 'ycp_delivery_mode'],
            ['name' => 'YCP delivery calculation mode', 'value' => $data['delivery_mode'] ?? 'merchant', 'type' => 'string']
        );

        return $this->get();
    }

    private function decryptSecret(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            // Keep compatibility with any secret stored as plain text previously.
            return $encrypted;
        }
    }
}
