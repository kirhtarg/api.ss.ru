<?php

use App\Http\Controllers\Api\Partner\V1\CatalogController;
use App\Http\Controllers\Api\Partner\V1\CheckoutController;
use App\Http\Controllers\Api\Partner\V1\CommissionController;
use App\Http\Controllers\Api\Partner\V1\DocumentationController;
use App\Http\Controllers\Api\Partner\V1\DeliveryController;
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
            'data' => ['version' => 'v1.1', 'time' => now()->toIso8601String()],
        ]));

        Route::middleware('partner.scope:catalog:read')->group(function (): void {
            Route::get('/catalog/categories', [CatalogController::class, 'categories']);
            Route::get('/catalog/brands', [CatalogController::class, 'brands']);
            Route::get('/catalog/products', [CatalogController::class, 'index']);
            Route::get('/catalog/products/{good}', [CatalogController::class, 'show']);
        });

        Route::middleware('partner.scope:orders:write')->group(function (): void {
            Route::post('/orders', [OrderController::class, 'store']);
            Route::post('/orders/{externalOrderId}/cancel', [OrderController::class, 'cancel']);
        });
        Route::middleware('partner.scope:checkout:read')->group(function (): void {
            Route::get('/checkout/options', [CheckoutController::class, 'options']);
            Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
            Route::get('/delivery/cities', [DeliveryController::class, 'cities']);
            Route::post('/delivery/tariffs', [DeliveryController::class, 'tariffs']);
            Route::get('/delivery/pickup-points', [DeliveryController::class, 'pickupPoints']);
            Route::post('/delivery/pickup-points/validate', [DeliveryController::class, 'validatePickupPoint']);
        });
        Route::middleware('partner.scope:payments:write')->post('/orders/{externalOrderId}/payment', [CheckoutController::class, 'payment']);
        Route::middleware('partner.scope:orders:read')->group(function (): void {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{externalOrderId}', [OrderController::class, 'show']);
        });
        Route::middleware('partner.scope:commissions:read')->get('/commissions', [CommissionController::class, 'index']);
    });
