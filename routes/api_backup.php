<?php

use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Public\CartController;
use App\Http\Controllers\Api\Public\CdekController;
use App\Http\Controllers\Api\Public\ContactController;
use App\Http\Controllers\Api\Public\CookieConsentController;
use App\Http\Controllers\Api\Public\ShopBonusSettingsController;
use App\Http\Controllers\Api\Public\ShopBrandsController;
use App\Http\Controllers\Api\Public\ShopCategoriesController;
use App\Http\Controllers\Api\Public\ShopCategoryController;
use App\Http\Controllers\Api\Public\ShopDeliveryController;
use App\Http\Controllers\Api\Public\ShopGoodsController;
use App\Http\Controllers\Api\Public\ShopOrdersController;
use App\Http\Controllers\Api\Public\ShopPaymentController;
use App\Http\Controllers\Api\Public\ShopPropertiesController;
use App\Http\Controllers\Api\Public\ShopTemplateController;
use App\Http\Controllers\Api\Public\SiteInfoController;
use App\Http\Controllers\Api\Public\SiteMenuController;
use App\Http\Controllers\Api\Public\SiteTemplateController;
use App\Http\Controllers\Api\Public\SliderController;
use App\Http\Controllers\Api\Public\TestBankController;
use App\Http\Controllers\Api\Public\UserBonusController;
use App\Http\Controllers\Api\Public\UserOrdersController;
use App\Http\Controllers\Api\Public\UserProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public API routes
Route::prefix('public')->group(function () {
    // Site info
    Route::get('/site-info', [SiteInfoController::class, 'index']);
    Route::get('/site/info', [SiteInfoController::class, 'index']);
    Route::get('/site/menu', [SiteMenuController::class, 'getMenu']);
    Route::get('/site/template/active', [SiteTemplateController::class, 'getActive']);
    Route::get('/site/template/active-main', [SiteTemplateController::class, 'getActive']);
    Route::get('/slider', [SliderController::class, 'index']);
    Route::get('/sliders', [SliderController::class, 'index']);

    // Shop routes
    Route::get('/shop/categories', [ShopCategoriesController::class, 'index']);
    Route::get('/shop/categories/main', [ShopCategoriesController::class, 'main']);
    Route::get('/shop/category/{id}', [ShopCategoryController::class, 'show']);
    Route::get('/shop/goods', [ShopGoodsController::class, 'index']);
    Route::post('/shop/goods/batch', [ShopGoodsController::class, 'getBatch']);
    Route::get('/shop/goods/main-blocks', [ShopGoodsController::class, 'getMainBlocks']);
    Route::post('/shop/goods/variations/media', [ShopGoodsController::class, 'getVariationsMedia']);
    Route::get('/shop/good/{id}', [ShopGoodsController::class, 'show']);
    Route::get('/shop/goods/{id}', [ShopGoodsController::class, 'show']);
    Route::get('/shop/brands', [ShopBrandsController::class, 'index']);
    Route::get('/shop/properties', [ShopPropertiesController::class, 'index']);
    Route::get('/shop/property-values', [ShopPropertiesController::class, 'getValues']);
    Route::get('/shop/template/active', [ShopTemplateController::class, 'getActive']);
    Route::get('/shop/template/active-card', [ShopTemplateController::class, 'getActiveCard']);
    Route::get('/shop/delivery-methods', [ShopDeliveryController::class, 'index']);
    Route::get('/shop/payment-methods', [ShopPaymentController::class, 'index']);
    Route::get('/shop/bonus-settings', [ShopBonusSettingsController::class, 'getActive']);
    Route::get('/shop/settings', [ShopBonusSettingsController::class, 'getActive']);

    // Cart routes
    Route::post('/cart/add', [CartController::class, 'add']);
    Route::post('/cart/remove', [CartController::class, 'remove']);
    Route::post('/cart/update', [CartController::class, 'update']);
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/clear', [CartController::class, 'clear']);
    Route::post('/cart/create-order', [ShopOrdersController::class, 'createOrder']);

    // CDEK routes
    Route::post('/cdek/cities', [CdekController::class, 'getCities']);
    Route::post('/cdek/tariffs', [CdekController::class, 'getTariffs']);
    Route::post('/cdek/pvz', [CdekController::class, 'getPvz']);

    // Test Bank routes
    Route::post('/test-bank/pay', [TestBankController::class, 'pay']);

    // Contact routes
    Route::get('/contacts/header-data', [ContactController::class, 'headerData']);

    // Cookie consent routes
    Route::get('/cookie-consent/check', [CookieConsentController::class, 'checkConsent']);
    Route::post('/cookie-consent/accept', [CookieConsentController::class, 'saveConsent']);

    // User routes (authenticated)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user/profile', [UserProfileController::class, 'index']);
        Route::post('/user/profile', [UserProfileController::class, 'update']);
        Route::get('/user/orders', [UserOrdersController::class, 'index']);
        Route::get('/user/orders/{id}', [UserOrdersController::class, 'show']);
        Route::get('/user/bonuses', [UserBonusController::class, 'index']);
        Route::post('/user/deduct-bonuses', [UserBonusController::class, 'deductBonuses']);
    });
});

// Admin API routes
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::post('/orders/{order}/unfreeze-bonuses', [OrderController::class, 'unfreezeBonuses']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
