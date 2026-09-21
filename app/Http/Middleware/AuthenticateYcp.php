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
        $provided = (string) $request->bearerToken();
        $matchesSiteToken = $provided !== ''
            && $settings['access_token'] !== ''
            && hash_equals($settings['access_token'], $provided);
        $matchesYcpApiToken = $provided !== ''
            && $settings['api_token'] !== ''
            && hash_equals($settings['api_token'], $provided);

        if (! $settings['enabled'] || $settings['access_token'] === '' || ! $matchesSiteToken) {
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
                'authorization_scheme' => $scheme,
                'bearer_present' => $provided !== '',
                'matches_site_token' => $matchesSiteToken,
                'matches_ycp_api_token' => $matchesYcpApiToken,
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
