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

        // Для тяжёлых операций импорта (download-images-batch, import-logs/*-batch, images/import-batch)
        // — более мягкий лимит 500/мин, чтобы не было 429 при массовой загрузке изображений
        $path = $request->path();
        if (str_contains($path, 'download-images-batch')
            || str_contains($path, 'image-error-batch')
            || str_contains($path, 'image-success-batch')
            || str_contains($path, 'import-batch')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'import');
        }

        // Для остальных маршрутов применяем стандартный throttle:api
        return app(ThrottleRequests::class)->handle($request, $next, $limiter);
    }
}

