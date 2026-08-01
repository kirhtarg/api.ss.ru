<?php

namespace App\Jobs;

use App\Models\PartnerWebhookDelivery;
use App\Services\Partner\PartnerWebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeliverPartnerWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $deliveryId) {}

    public function tries(): int
    {
        return max(1, (int) config('partners.webhook_max_attempts', 8));
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800, 21600, 43200];
    }

    public function handle(PartnerWebhookUrlGuard $urlGuard): void
    {
        $delivery = PartnerWebhookDelivery::query()->with('partner')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }

        $partner = $delivery->partner;
        if (! $partner?->is_active || ! $partner->webhook_url || ! $partner->webhook_secret) {
            $delivery->update([
                'status' => 'failed',
                'last_error' => 'Partner webhook configuration is unavailable',
                'next_attempt_at' => null,
            ]);

            return;
        }

        try {
            $curlResolve = $urlGuard->curlResolveEntries($partner->webhook_url);
        } catch (RuntimeException $exception) {
            $delivery->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'next_attempt_at' => null,
            ]);
            Log::warning('Partner webhook URL rejected', [
                'partner_id' => $partner->id,
                'delivery_id' => $delivery->public_id,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $attempt = $delivery->attempts + 1;
        $delivery->update(['status' => 'processing', 'attempts' => $attempt, 'next_attempt_at' => null]);

        Log::info('Partner webhook delivery started', [
            'partner_id' => $partner->id,
            'delivery_id' => $delivery->public_id,
            'event' => $delivery->event,
            'attempt' => $attempt,
        ]);
        $startedAt = hrtime(true);

        try {
            $response = Http::acceptJson()
                ->timeout(config('partners.webhook_timeout_seconds', 10))
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => $curlResolve],
                ])
                ->withHeaders([
                    'X-Partner-Delivery' => $delivery->public_id,
                    'X-Partner-Event' => $delivery->event,
                    'X-Partner-Timestamp' => $timestamp,
                    'X-Partner-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $partner->webhook_secret),
                ])
                ->withBody($body, 'application/json')
                ->post($partner->webhook_url);

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $responseBody = json_encode([
                'content_type' => $response->header('Content-Type'),
                'body_size' => strlen($response->body()),
                'body_sha256' => hash('sha256', $response->body()),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! $response->successful()) {
                $delivery->update([
                    'status' => 'retrying',
                    'response_status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'response_body' => $responseBody,
                    'last_error' => 'Webhook endpoint returned HTTP '.$response->status(),
                    'next_attempt_at' => $this->nextAttemptAt($attempt),
                ]);
                throw new RuntimeException('Partner webhook returned HTTP '.$response->status());
            }

            $delivery->update([
                'status' => 'delivered',
                'response_status' => $response->status(),
                'duration_ms' => $durationMs,
                'response_body' => $responseBody,
                'last_error' => null,
                'next_attempt_at' => null,
                'delivered_at' => now(),
            ]);

            Log::info('Partner webhook delivery completed', [
                'partner_id' => $partner->id,
                'delivery_id' => $delivery->public_id,
                'event' => $delivery->event,
                'attempt' => $attempt,
                'response_status' => $response->status(),
                'duration_ms' => $durationMs,
            ]);
        } catch (ConnectionException $exception) {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $delivery->update([
                'status' => 'retrying',
                'duration_ms' => $durationMs,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'next_attempt_at' => $this->nextAttemptAt($attempt),
            ]);
            Log::warning('Partner webhook connection failed', [
                'partner_id' => $partner->id,
                'delivery_id' => $delivery->public_id,
                'attempt' => $attempt,
                'duration_ms' => $durationMs,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        PartnerWebhookDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'last_error' => mb_substr($exception?->getMessage() ?? 'Webhook delivery failed', 0, 2000),
            'next_attempt_at' => null,
        ]);

        Log::error('Partner webhook delivery exhausted retries', [
            'delivery_database_id' => $this->deliveryId,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function nextAttemptAt(int $attempt)
    {
        $delays = $this->backoff();

        return now()->addSeconds($delays[min($attempt - 1, count($delays) - 1)]);
    }
}
