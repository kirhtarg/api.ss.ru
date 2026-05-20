<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Увеличенный лимит для обычных API маршрутов
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(150)->by($request->user()?->id ?: $request->ip());
        });

        // Отдельный rate limiter для публичных эндпоинтов.
        // SSR frontend renders many public API requests from the same server IP,
        // so a low per-IP limit causes random 429 errors for real users.
        RateLimiter::for('public', function (Request $request) {
            $path = $request->path();

            if (str_starts_with($path, 'api/public/shop/')
                || str_starts_with($path, 'api/public/site-info')
                || str_starts_with($path, 'api/public/settings')
                || str_starts_with($path, 'api/public/site/')
                || str_starts_with($path, 'api/public/favicon')) {
                return Limit::perMinute(5000)->by($request->ip());
            }

            return Limit::perMinute(1000)->by($request->ip());
        });

        // Повышенный лимит для тяжёлых операций импорта: download-images-batch, import-logs/*-batch, images/import-batch
        RateLimiter::for('import', function (Request $request) {
            return Limit::perMinute(500)->by($request->user()?->id ?: $request->ip());
        });
    }
}
