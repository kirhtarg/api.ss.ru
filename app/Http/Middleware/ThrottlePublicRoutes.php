<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePublicRoutes
{
    /**
     * Handle an incoming request.
     * Пропускает публичные маршруты из общего throttle:api,
     * так как для них используется отдельный throttle:public
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $limiter = 'api'): Response
    {
        // Если это публичный маршрут, пропускаем его без throttle:api
        // throttle:public будет применен отдельно в routes/api.php
        if ($request->is('api/public/*')) {
            return $next($request);
        }

        // Для остальных маршрутов применяем стандартный throttle:api
        return app(ThrottleRequests::class)->handle($request, $next, $limiter);
    }
}

