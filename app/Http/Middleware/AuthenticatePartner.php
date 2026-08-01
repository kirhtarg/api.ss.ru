<?php

namespace App\Http\Middleware;

use App\Models\PartnerApiCredential;
use App\Services\Partner\PartnerSignatureCanonicalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartner
{
    public function __construct(private readonly PartnerSignatureCanonicalizer $canonicalizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header('X-Partner-Key');
        $timestamp = (string) $request->header('X-Partner-Timestamp');
        $nonce = (string) $request->header('X-Partner-Nonce');
        $signature = (string) $request->header('X-Partner-Signature');
        $credential = PartnerApiCredential::with('partner')->where('key_id', $keyId)->first();
        if (! $credential?->isUsable() || ! ctype_digit($timestamp) || $nonce === '' || $signature === '') {
            return $this->deny('invalid_credentials');
        }
        if (abs(now()->timestamp - (int) $timestamp) > config('partners.signature_ttl_seconds', 300)) {
            return $this->deny('expired_signature');
        }
        $allowed = array_filter($credential->partner->allowed_ips ?? []);
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            Log::warning('Partner API IP denied', ['partner_id' => $credential->partner_id, 'ip' => $request->ip()]);

            return $this->deny('ip_not_allowed');
        }
        $nonceKey = 'partner-api:nonce:'.$credential->id.':'.hash('sha256', $nonce);
        if (! Cache::add($nonceKey, true, now()->addSeconds(config('partners.signature_ttl_seconds', 300)))) {
            return $this->deny('replayed_request');
        }
        $canonical = $this->canonicalizer->request(
            $request->method(),
            $request->path(),
            (string) $request->server->get('QUERY_STRING', ''),
            (string) $request->getContent(),
            $timestamp,
            $nonce,
        );
        if (! hash_equals(hash_hmac('sha256', $canonical, $credential->secret), $signature)) {
            Cache::forget($nonceKey);

            return $this->deny('invalid_signature');
        }
        $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('partner', $credential->partner);
        $request->attributes->set('partnerCredential', $credential);

        return $next($request);
    }

    private function deny(string $code): Response
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => 'Partner authentication failed']], 401);
    }
}
