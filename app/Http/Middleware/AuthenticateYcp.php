<?php

namespace App\Http\Middleware;

use App\Services\YcpSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateYcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(YcpSettingsService::class)->get();
        $provided = (string) $request->bearerToken();
        if (! $settings['enabled'] || $settings['access_token'] === '' || $provided === '' || ! hash_equals($settings['access_token'], $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
