<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Регистрируем кастомные middleware
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'shop.access' => \App\Http\Middleware\CheckShopAccess::class,
        ]);
        
        // Настраиваем API middleware - только token-based аутентификация
        $middleware->api([
            \Illuminate\Http\Middleware\HandleCors::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
