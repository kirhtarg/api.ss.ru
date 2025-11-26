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

        // Отдельный rate limiter для публичных эндпоинтов с более высоким лимитом
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });
    }
}
