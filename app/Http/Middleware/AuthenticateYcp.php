<?php

namespace App\Http\Middleware;

use App\Services\YcpSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateYcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(YcpSettingsService::class)->get();
        // Some gateway/proxy implementations can leave whitespace around the bearer value.
        $provided = trim((string) $request->bearerToken());
        $matchesSiteToken = $provided !== ''
            && $settings['access_token'] !== ''
            && hash_equals($settings['access_token'], $provided);
        $matchesYcpApiToken = $provided !== ''
            && $settings['api_token'] !== ''
            && hash_equals($settings['api_token'], $provided);

        if (! $settings['enabled'] || $settings['api_token'] === '' || ! $matchesYcpApiToken) {
            $authorization = (string) $request->header('Authorization', '');
            $scheme = str_contains($authorization, ' ')
                ? strtolower((string) strtok($authorization, ' '))
                : ($authorization === '' ? null : 'unrecognized');

            // Deliberately log only booleans and request metadata, never either secret or a token fingerprint.
            Log::warning('YCP request authentication rejected', [
                'path' => $request->path(),
                'method' => $request->method(),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
                'enabled' => $settings['enabled'],
                'site_token_configured' => $settings['access_token'] !== '',
                'ycp_api_token_configured' => $settings['api_token'] !== '',
                'authorization_scheme' => $scheme,
                'bearer_present' => $provided !== '',
                'matches_site_token' => $matchesSiteToken,
                'matches_ycp_api_token' => $matchesYcpApiToken,
                'provided_token_fingerprint' => $provided === '' ? null : substr(hash('sha256', $provided), 0, 12),
                'site_token_fingerprint' => $settings['access_token'] === '' ? null : substr(hash('sha256', $settings['access_token']), 0, 12),
                'ycp_api_token_fingerprint' => $settings['api_token'] === '' ? null : substr(hash('sha256', $settings['api_token']), 0, 12),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
