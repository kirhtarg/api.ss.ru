<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\YcpSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

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

    public function testConnection(YcpSettingsService $settings): JsonResponse
    {
        $config = $settings->get();
        if (! $config['enabled']) {
            return response()->json(['success' => false, 'message' => 'Сначала включите YCP и сохраните настройки']);
        }
        if ($config['access_token'] === '') {
            return response()->json(['success' => false, 'message' => 'Токен YCP не сохранён']);
        }

        try {
            $response = Http::acceptJson()
                ->withToken($config['access_token'])
                ->connectTimeout(3)
                ->timeout(7)
                ->get($config['api_url'].'/warehouses', ['limit' => 1, 'offset' => 0]);
        } catch (ConnectionException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось подключиться к API YCP по указанному адресу. Проверьте доступность домена и HTTPS.',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['success' => false, 'message' => 'Ошибка при проверке API YCP']);
        }

        if ($response->status() === 401) {
            return response()->json(['success' => false, 'message' => 'API доступен, но токен отклонён. Сверьте токен в настройках сайта и кабинете YCP.']);
        }
        if (! $response->successful()) {
            return response()->json(['success' => false, 'message' => 'API ответил с HTTP '.$response->status().'. Проверьте настройки YCP и доступность метода складов.']);
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['warehouses']) || ! is_array($payload['warehouses']) || ! isset($payload['total_count'])) {
            return response()->json(['success' => false, 'message' => 'API ответил, но формат ответа списка складов не соответствует YCP.']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Соединение установлено: API доступен, токен принят, ответ YCP корректен.',
            'data' => ['warehouses_count' => (int) $payload['total_count']],
        ]);
    }
}
