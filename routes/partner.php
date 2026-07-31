<?php

use App\Http\Controllers\Api\Partner\V1\CatalogController;
use App\Http\Controllers\Api\Partner\V1\CheckoutController;
use App\Http\Controllers\Api\Partner\V1\DocumentationController;
use App\Http\Controllers\Api\Partner\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('partner/v1')->group(function (): void {
    Route::get('/openapi.json', [DocumentationController::class, 'specification']);
    Route::get('/docs', [DocumentationController::class, 'reference']);
});

Route::prefix('partner/v1')
    ->middleware(['partner.log', 'partner.auth', 'throttle:partner'])
    ->group(function (): void {
        Route::get('/health', fn () => response()->json([
            'success' => true,
            'data' => ['version' => 'v1', 'time' => now()->toIso8601String()],
        ]));

        Route::middleware('partner.scope:catalog:read')->group(function (): void {
            Route::get('/catalog/categories', [CatalogController::class, 'categories']);
            Route::get('/catalog/products', [CatalogController::class, 'index']);
            Route::get('/catalog/products/{good}', [CatalogController::class, 'show']);
        });

        Route::middleware('partner.scope:orders:write')->post('/orders', [OrderController::class, 'store']);
        Route::middleware('partner.scope:checkout:read')->get('/checkout/options', [CheckoutController::class, 'options']);
        Route::middleware('partner.scope:payments:write')->post('/orders/{externalOrderId}/payment', [CheckoutController::class, 'payment']);
        Route::middleware('partner.scope:orders:read')->get('/orders/{externalOrderId}', [OrderController::class, 'show']);
    });
