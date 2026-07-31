<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePartner;
use App\Models\Partner;
use App\Models\PartnerApiCredential;
use App\Services\Partner\PartnerWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DiagnosticController extends Controller
{
    public function connection(Request $request, PartnerApiCredential $credential, AuthenticatePartner $middleware): JsonResponse
    {
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $path = '/api/partner/v1/health';
        $canonical = implode("\n", ['GET', $path, '', hash('sha256', ''), $timestamp, $nonce]);
        $credential->loadMissing('partner');
        $testIp = $credential->partner?->allowed_ips[0] ?? $request->ip();
        $synthetic = Request::create($path, 'GET', server: [
            'HTTP_X_PARTNER_KEY' => $credential->key_id,
            'HTTP_X_PARTNER_TIMESTAMP' => $timestamp,
            'HTTP_X_PARTNER_NONCE' => $nonce,
            'HTTP_X_PARTNER_SIGNATURE' => hash_hmac('sha256', $canonical, $credential->secret),
            'REMOTE_ADDR' => $testIp,
        ]);
        $response = $middleware->handle($synthetic, fn () => response()->json(['success' => true]));
        $passed = $response->getStatusCode() === 200;

        Log::info('Partner API credential connection tested', [
            'user_id' => $request->user()?->id,
            'partner_id' => $credential->partner_id,
            'credential_id' => $credential->id,
            'passed' => $passed,
            'status' => $response->getStatusCode(),
        ]);

        return response()->json([
            'success' => $passed,
            'message' => $passed ? 'Подключение и HMAC-подпись работают' : 'Проверка подключения не пройдена',
            'data' => ['status' => $response->getStatusCode(), 'checked_at' => now()->toIso8601String()],
        ], $passed ? 200 : 422);
    }

    public function webhook(Request $request, Partner $partner, PartnerWebhookService $webhooks): JsonResponse
    {
        if (! $partner->webhook_url || ! $partner->webhook_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Сначала укажите webhook URL и webhook secret',
            ], 422);
        }

        $delivery = $webhooks->queueTestEvent($partner);

        Log::info('Partner test webhook requested by administrator', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partner->id,
            'delivery_id' => $delivery->public_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Тестовый webhook поставлен в очередь',
            'data' => $delivery,
        ], 202);
    }

    public function rotateWebhookSecret(Request $request, Partner $partner): JsonResponse
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $partner->update(['webhook_secret' => $secret]);

        Log::warning('Partner webhook secret rotated', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partner->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook secret обновлён',
            'data' => ['secret' => $secret, 'secret_visible_once' => true],
        ], 201);
    }
}
