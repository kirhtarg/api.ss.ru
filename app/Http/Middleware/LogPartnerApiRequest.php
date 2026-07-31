<?php

namespace App\Http\Middleware;

use App\Models\PartnerApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LogPartnerApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $id = (string) ($request->header('X-Request-ID') ?: Str::uuid());
        $request->attributes->set('partnerRequestId', $id);

        try {
            $response = $next($request);
            $this->persist($request, $id, $started, $response->getStatusCode());
            $response->headers->set('X-Request-ID', $id);

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $this->persist($request, $id, $started, $status, class_basename($exception));

            throw $exception;
        }
    }

    private function persist(Request $request, string $id, int $started, int $status, ?string $errorCode = null): void
    {
        try {
            PartnerApiRequestLog::create([
                'partner_id' => $request->attributes->get('partner')?->id,
                'request_id' => $id,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'response_status' => $status,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'ip_address' => $request->ip(),
                'request_hash' => hash('sha256', (string) $request->getContent()),
                'error_code' => $errorCode,
            ]);
        } catch (Throwable $exception) {
            Log::error('Partner API request log persistence failed', [
                'request_id' => $id,
                'partner_id' => $request->attributes->get('partner')?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
