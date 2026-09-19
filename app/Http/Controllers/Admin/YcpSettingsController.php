<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\YcpSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YcpSettingsController extends Controller
{
    public function show(YcpSettingsService $settings): JsonResponse
    {
        $data = $settings->get();
        return response()->json(['success' => true, 'data' => [
            'enabled' => $data['enabled'],
            'configured' => $data['access_token'] !== '',
            'access_token_masked' => $data['access_token'] !== '' ? '********' : '',
            'access_token' => '',
            'delivery_mode' => $data['delivery_mode'],
            'api_url' => $data['api_url'],
        ]]);
    }

    public function update(Request $request, YcpSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'access_token' => ['nullable', 'string', 'min:24', 'max:512'],
            'delivery_mode' => ['required', 'in:merchant,yandex'],
        ]);
        $current = $settings->get();
        if ($data['enabled'] && trim((string) ($data['access_token'] ?? $current['access_token'])) === '') {
            return response()->json(['success' => false, 'message' => 'Для включения YCP необходимо указать токен доступа'], 422);
        }

        $saved = $settings->save($data);
        return response()->json(['success' => true, 'message' => 'Настройки YCP сохранены', 'data' => [
            'enabled' => $saved['enabled'],
            'configured' => $saved['access_token'] !== '',
            'access_token_masked' => $saved['access_token'] !== '' ? '********' : '',
            'access_token' => '',
            'delivery_mode' => $saved['delivery_mode'],
            'api_url' => $saved['api_url'],
        ]]);
    }
}
