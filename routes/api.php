<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\ShopGoodImageController;
use App\Http\Controllers\Api\Public\ContactController;
use App\Http\Controllers\Api\Public\SiteInfoController;
use App\Http\Controllers\Api\Public\SiteTemplateController;
use App\Http\Controllers\Api\Public\ShopTemplateController;
use App\Http\Controllers\Api\Public\SiteMenuController;


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

// DEBUG: Тестовый роут для проверки PHP настроек
Route::get('/debug/php-info', function () {
    return response()->json([
        'php_version' => phpversion(),
        'settings' => [
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_input_vars' => ini_get('max_input_vars'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    ]);
});

// Обработка OPTIONS запросов для CORS - должна быть первой
Route::match(['OPTIONS'], '/{any}', function (Request $request) {
    $origin = $request->header('Origin');
    $allowedOrigins = [
        'https://skateandsnow-test.ru',
        'https://admin.skateandsnow-test.ru',
        'https://api.skateandsnow-test.ru',
        'https://skateandsnow.ru',
        'https://admin.skateandsnow.ru',
        'https://api.skateandsnow.ru',
        'http://localhost:3000',
        'http://localhost:3001',
    ];

    // Если origin не разрешен, возвращаем заголовок только если есть Origin
    $allowOrigin = $origin && in_array($origin, $allowedOrigins) ? $origin : ($origin ?: '*');

    return response('', 200)
        ->header('Access-Control-Allow-Origin', $allowOrigin)
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID')
        ->header('Access-Control-Allow-Credentials', $allowOrigin !== '*' ? 'true' : 'false')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');

// Маршрут для получения данных контактов для заголовка (публичные, с отдельным rate limiter)
Route::middleware(['throttle:public'])->group(function () {
    Route::get('/public/contacts/header-data', [\App\Http\Controllers\Api\Public\ContactController::class, 'headerData']);
    // Маршрут для получения основных контактов магазина
    Route::get('/public/contacts/main', [\App\Http\Controllers\Api\Public\ContactController::class, 'getMain']);
    // Маршрут для получения адресов самовывоза
    Route::get('/public/contacts/pickup-addresses', [\App\Http\Controllers\Api\Public\ContactController::class, 'getPickupAddresses']);
});




// Публичные маршруты для авторизации (без Sanctum stateful middleware)
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'registerWithToken']); // Старая регистрация с токенами
Route::post('/register-with-code', [AuthController::class, 'registerWithCode']); // Новая регистрация с кодами
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationEmail']);
Route::post('/check-email', [AuthController::class, 'checkEmail']);
Route::post('/check-phone', [AuthController::class, 'checkPhone']);

// Password recovery routes
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/verify-code', [AuthController::class, 'verifyPasswordResetCode']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// Phone authentication routes
Route::post('/phone/send-code', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'sendPhoneCode']);
Route::post('/phone/verify-code', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'verifyPhoneCode']);
Route::post('/phone/check-status', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'checkCodeStatus']);


// Свойства товаров для импорта (временно без middleware для тестирования)
Route::get('/admin/shop/goods/properties', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'list']);

// Google OAuth маршруты (требуют сессии)
Route::middleware(['web'])->group(function () {
    Route::get('/auth/google', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'handleGoogleCallback']);
    Route::get('/auth/google/url', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'getGoogleAuthUrl']);
});

// VK OAuth маршруты (требуют сессии)
Route::middleware(['web'])->group(function () {
    Route::get('/auth/vk', [\App\Http\Controllers\Auth\VkAuthController::class, 'redirectToVk']);
    Route::get('/auth/vk/callback', [\App\Http\Controllers\Auth\VkAuthController::class, 'handleVkCallback']);
    Route::get('/auth/vk/url', [\App\Http\Controllers\Auth\VkAuthController::class, 'getVkAuthUrl']);
});

// VK ID SDK маршруты (не требуют сессии)
Route::match(['get', 'post'], '/auth/vk/sdk-callback', [\App\Http\Controllers\Auth\VkAuthController::class, 'handleVkSdkCallback']);

// Yandex OAuth маршруты (требуют сессии)
Route::middleware(['web'])->group(function () {
    Route::get('/auth/yandex', [\App\Http\Controllers\Auth\YandexAuthController::class, 'redirectToYandex']);
    Route::get('/auth/yandex/callback', [\App\Http\Controllers\Auth\YandexAuthController::class, 'handleYandexCallback']);
    Route::get('/auth/yandex/url', [\App\Http\Controllers\Auth\YandexAuthController::class, 'getYandexAuthUrl']);
});

// Тестовый маршрут для проверки OAuth
Route::get('/test/oauth', function () {
    $sessionDriver = config('session.driver');
    $sessionTableExists = \Illuminate\Support\Facades\Schema::hasTable('sessions');

    return response()->json([
        'success' => true,
        'message' => 'OAuth маршруты работают',
        'config' => [
            'google' => [
                'client_id' => config('services.google.client_id') ? 'настроен' : 'не настроен',
                'client_secret' => config('services.google.client_secret') ? 'настроен' : 'не настроен',
                'redirect' => config('services.google.redirect'),
            ],
            'vk' => [
                'client_id' => config('services.vkontakte.client_id') ? 'настроен' : 'не настроен',
                'client_secret' => config('services.vkontakte.client_secret') ? 'настроен' : 'не настроен',
                'redirect' => config('services.vkontakte.redirect'),
            ],
            'yandex' => [
                'client_id' => config('services.yandex.client_id') ? 'настроен' : 'не настроен',
                'client_secret' => config('services.yandex.client_secret') ? 'настроен' : 'не настроен',
                'redirect' => config('services.yandex.redirect'),
            ]
        ],
        'session' => [
            'driver' => $sessionDriver,
            'table_exists' => $sessionTableExists,
            'status' => $sessionTableExists ? 'OK' : 'Нужно запустить миграцию'
        ]
    ]);
});


    // Публичные маршруты для получения информации о сайте (с CORS middleware и отдельным rate limiter)
    Route::middleware(['cors', 'throttle:public'])->group(function () {
    Route::options('/public/site-info', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site-info', [SiteInfoController::class, 'index']);

    Route::options('/public/settings', function () {
        return response()->json([], 200);
    });
    Route::get('/public/settings', [App\Http\Controllers\Api\Public\SiteInfoController::class, 'settings']);

    Route::options('/public/settings/seo', function () {
        return response()->json([], 200);
    });
    Route::get('/public/settings/seo', [App\Http\Controllers\Api\Public\SiteInfoController::class, 'seo']);

    // Публичные маршруты для шаблонов сайта
    Route::options('/public/site/template/active', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site/template/active', [SiteTemplateController::class, 'getActive']);

    Route::options('/public/site/template/active-main', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site/template/active-main', [App\Http\Controllers\Api\Public\SiteTemplateController::class, 'getActiveMain']);

    Route::options('/public/site/menu', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site/menu', [SiteMenuController::class, 'getMenu']);

    // Публичные маршруты для шаблонов магазина
    Route::options('/public/shop/template/active', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/template/active', [App\Http\Controllers\Api\Public\ShopTemplateController::class, 'getActive']);

    Route::options('/public/shop/template/active-card', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/template/active-card', [ShopTemplateController::class, 'getActiveCard']);

    Route::options('/public/shop/template/active-page', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/template/active-page', [App\Http\Controllers\Api\Public\ShopTemplateController::class, 'getActivePage']);

    Route::options('/public/shop/template/active-brands', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/template/active-brands', [App\Http\Controllers\Api\Public\ShopTemplateController::class, 'getActiveBrands']);

    Route::options('/public/shop/template/active-categories', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/template/active-categories', [App\Http\Controllers\Api\Public\ShopTemplateController::class, 'getActiveCategories']);

    // Публичные маршруты для товаров магазина
    Route::options('/public/shop/goods', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'index']);

    // Оптимизированный endpoint для получения всех типов товаров для главной страницы
    Route::options('/public/shop/goods/main-blocks', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/main-blocks', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getMainBlocks']);

    Route::options('/public/shop/goods/{id}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/{id}', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'show']);

    Route::options('/public/shop/goods/slug/{slug}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/slug/{slug}', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getGoodBySlug']);

    Route::options('/public/shop/goods/{id}/images', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/{id}/images', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getGoodImages']);

    // Публичные маршруты для медиа вариаций
    Route::options('/public/shop/goods/variations/{variationId}/images', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/variations/{variationId}/images', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getVariationImages']);

    Route::options('/public/shop/goods/variations/{variationId}/videos', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/goods/variations/{variationId}/videos', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getVariationVideos']);

    // Массовая загрузка всех медиа вариаций (изображения + видео) - единый endpoint
    Route::options('/public/shop/goods/variations/media', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/goods/variations/media', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getVariationsMedia']);

    // Пакетная загрузка товаров
    Route::options('/public/shop/goods/batch', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/goods/batch', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getBatch']);


    // Публичные маршруты для категорий магазина
    Route::options('/public/shop/categories', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'index']);

    // Маршрут для получения главных категорий (должен быть ПЕРЕД маршрутом с {id})
    Route::options('/public/shop/categories/main', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories/main', [App\Http\Controllers\Api\Public\ShopCategoryController::class, 'getMainCategories']);

    Route::options('/public/shop/categories/{id}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories/{id}', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'show']);

    Route::options('/public/shop/categories/{id}/children', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories/{id}/children', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'getChildren']);
    
    // Batch endpoint для получения подкатегорий нескольких категорий одним запросом
    Route::options('/public/shop/categories/children/batch', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/categories/children/batch', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'getChildrenBatch']);

    Route::options('/public/shop/categories/slug/{slug}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories/slug/{slug}', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getCategoryBySlug']);
    Route::get('/public/shop/categories/slug/{slug}/with-relations', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'getCategoryBySlugWithRelations']);

    Route::options('/public/shop/categories/{id}/extra-menu', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/categories/{id}/extra-menu', [App\Http\Controllers\Api\Public\ShopCategoriesController::class, 'getExtraMenu']);

    // Публичные маршруты для брендов магазина
    Route::options('/public/shop/brands', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/brands', [App\Http\Controllers\Api\Public\ShopBrandsController::class, 'index']);

    // Публичные маршруты для корзины
    Route::options('/public/shop/cart', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cart', [App\Http\Controllers\Api\Public\CartController::class, 'getCart']);
    Route::post('/public/shop/cart/add', [App\Http\Controllers\Api\Public\CartController::class, 'addToCart']);
    Route::put('/public/shop/cart/update', [App\Http\Controllers\Api\Public\CartController::class, 'updateCartItem']);
    Route::delete('/public/shop/cart/remove', [App\Http\Controllers\Api\Public\CartController::class, 'removeFromCart']);
    Route::delete('/public/shop/cart/clear', [App\Http\Controllers\Api\Public\CartController::class, 'clearCart']);
    Route::post('/public/shop/cart/create-order', [App\Http\Controllers\Api\Public\CartController::class, 'createOrder']);

    // Публичные маршруты для промокодов
    Route::options('/public/shop/promocodes/apply', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/promocodes/apply', [App\Http\Controllers\PromocodeController::class, 'apply']);

    Route::options('/public/shop/promocodes/remove', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/promocodes/remove', [App\Http\Controllers\PromocodeController::class, 'remove']);

    Route::options('/public/shop/promocodes/check', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/promocodes/check', [App\Http\Controllers\PromocodeController::class, 'check']);

    Route::options('/public/shop/promocodes/create-absent', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/promocodes/create-absent', [App\Http\Controllers\PromocodeController::class, 'createAbsentPromocode']);

    Route::options('/public/shop/promocodes/check-absent', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/promocodes/check-absent', [App\Http\Controllers\PromocodeController::class, 'checkAbsentPromocode']);

    // Публичные маршруты для способов доставки и оплаты
    Route::options('/public/shop/delivery-methods', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/delivery-methods', [App\Http\Controllers\Api\Public\ShopDeliveryController::class, 'index']);

    Route::options('/public/shop/payment-methods', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/payment-methods', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'index']);

    // Создание платежей
    Route::options('/public/shop/payment/create', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/payment/create', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'createPayment']);

    // Webhook для Ю-Касса
    Route::post('/webhooks/yookassa', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'yookassaWebhook']);

    // Яндекс Пэй маршруты
    Route::options('/yandex-pay/orders', function () {
        return response()->json([], 200);
    });
    Route::post('/yandex-pay/orders', [App\Http\Controllers\Api\YandexPayController::class, 'createOrder']);

    Route::options('/yandex-pay/orders/{orderId}', function () {
        return response()->json([], 200);
    });
    Route::get('/yandex-pay/orders/{orderId}', [App\Http\Controllers\Api\YandexPayController::class, 'getOrderStatus']);
    Route::post('/yandex-pay/orders/{orderId}/cancel', [App\Http\Controllers\Api\YandexPayController::class, 'cancelOrder']);
    Route::post('/yandex-pay/orders/{orderId}/refund', [App\Http\Controllers\Api\YandexPayController::class, 'refundOrder']);

    // Web SDK helper endpoint: returns paymentData for createSession/mountWidget
    Route::options('/yandex-pay/payment-session-data', function () {
        return response()->json([], 200);
    });
    Route::post('/yandex-pay/payment-session-data', [App\Http\Controllers\Api\YandexPayController::class, 'getPaymentSessionData']);

    // Валидация merchant_id и подготовка данных сессии для SDK (согласно инструкции)
    Route::options('/yandex-pay/session', function () {
        return response()->json([], 200);
    });
    Route::post('/yandex-pay/session', [App\Http\Controllers\Api\YandexPayController::class, 'createSession']);
    
    // Проверка статуса платежа
    Route::options('/yandex-pay/status/{orderId}', function () {
        return response()->json([], 200);
    });
    Route::get('/yandex-pay/status/{orderId}', [App\Http\Controllers\Api\YandexPayController::class, 'checkPaymentStatus']);

    // Webhook для Яндекс Пэй
    Route::post('/webhooks/yandex-pay', [App\Http\Controllers\Api\YandexPayController::class, 'handleWebhook']);
    Route::post('/webhooks/yandex-split', [App\Http\Controllers\Api\YandexPayController::class, 'handleWebhook']);

    // Проверка статуса платежа
    Route::options('/public/shop/payment/check-status', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/payment/check-status', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'checkPaymentStatus']);

    // Обновление статуса заказа
    Route::options('/public/shop/payment/update-order-status', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/payment/update-order-status', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'updateOrderStatus']);

    // Обработка возврата с Ю-Касса
    Route::get('/public/shop/payment/return', [App\Http\Controllers\Api\Public\ShopPaymentController::class, 'handlePaymentReturn']);

    // СДЭК интеграция
    Route::options('/public/cdek/cities', function () {
        return response()->json([], 200);
    });
    Route::get('/public/cdek/cities', [App\Http\Controllers\Api\Public\CdekController::class, 'searchCities']);

    Route::options('/public/cdek/streets', function () {
        return response()->json([], 200);
    });
    Route::get('/public/cdek/streets', [App\Http\Controllers\Api\Public\CdekController::class, 'searchStreets']);

    Route::options('/public/cdek/calculate', function () {
        return response()->json([], 200);
    });
    Route::post('/public/cdek/calculate', [App\Http\Controllers\Api\Public\CdekController::class, 'calculateDelivery']);

    Route::options('/public/cdek/pvz', function () {
        return response()->json([], 200);
    });
    Route::get('/public/cdek/pvz', [App\Http\Controllers\Api\Public\CdekController::class, 'getPvzList']);

    // Новые маршруты СДЭК для магазина
    Route::options('/public/shop/cdek/cities', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/cities', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getCities']);

    Route::options('/public/shop/cdek/settings/active', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/settings/active', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getActiveSettings']);

    Route::options('/public/shop/cdek/pvz-list', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/pvz-list', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getPvzList']);

    Route::options('/public/shop/cdek/calculate-delivery', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/cdek/calculate-delivery', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'calculateDelivery']);

    Route::options('/public/shop/cdek/tariffs', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/tariffs', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getTariffs']);

    Route::options('/public/shop/cdek/streets', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/streets', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getStreets']);

    Route::options('/public/shop/cdek/houses', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/cdek/houses', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getHouses']);

    Route::options('/public/shop/cdek/get-postal-code', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/cdek/get-postal-code', [App\Http\Controllers\Api\Public\ShopCdekController::class, 'getPostalCode']);

    // Order details API
    Route::options('/public/order/details', function () {
        return response()->json([], 200);
    });
    Route::get('/public/order/details', [App\Http\Controllers\Api\OrderController::class, 'getOrderDetails']);

    // Тест-Банк интеграция
    Route::options('/public/testbank/payment', function () {
        return response()->json([], 200);
    });
    Route::post('/public/testbank/payment', [App\Http\Controllers\Api\Public\TestBankController::class, 'createPayment']);

    Route::options('/public/testbank/status', function () {
        return response()->json([], 200);
    });
    Route::get('/public/testbank/status', [App\Http\Controllers\Api\Public\TestBankController::class, 'getPaymentStatus']);

    // Webhook для Тест-Банк
    Route::post('/webhooks/testbank', [App\Http\Controllers\Api\Public\TestBankController::class, 'webhook']);

    // Генерация счета для банковского перевода
    Route::options('/payment-methods/transfer/invoice', function () {
        return response()->json([], 200);
    });
    Route::get('/payment-methods/transfer/invoice', [App\Http\Controllers\Api\Public\TransferInvoiceController::class, 'generateInvoice']);
    Route::get('/payment-methods/transfer/invoice/excel', [App\Http\Controllers\Api\Public\TransferInvoiceController::class, 'generateInvoiceExcel']);
    Route::get('/payment-methods/transfer/invoice/pdf', [App\Http\Controllers\Api\Public\TransferInvoiceController::class, 'generateInvoicePdf']);

    // Настройки бонусов (публичные - видны всем)
    Route::get('/public/shop/bonus-settings', [App\Http\Controllers\Api\Public\ShopBonusSettingsController::class, 'getActive']);
    Route::post('/public/shop/bonus-settings/calculate', [App\Http\Controllers\Api\Public\ShopBonusSettingsController::class, 'calculateBonus']);

    // Настройки магазина (публичные - видны всем)
    Route::get('/public/shop/settings', [App\Http\Controllers\ShopSettingsController::class, 'getShopSettings']);

    // Информация о товарах (публичная)
    Route::post('/public/shop/goods/details', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getGoodsDetails']);

    // Значения характеристик товаров (публичные)
    Route::get('/public/shop/property-values', [App\Http\Controllers\Api\Public\ShopGoodsController::class, 'getPropertyValues']);

    // Создание заказов (публичное)
    Route::options('/public/shop/orders', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/orders', [App\Http\Controllers\Api\Public\ShopOrdersController::class, 'store']);

    // Заказы пользователей (требует авторизации)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/public/shop/orders', [App\Http\Controllers\Api\Public\UserOrdersController::class, 'index']);
        Route::get('/public/shop/orders/statuses', [App\Http\Controllers\Api\Public\UserOrdersController::class, 'getStatuses']);
        Route::get('/public/shop/orders/{id}', [App\Http\Controllers\Api\Public\UserOrdersController::class, 'show']);
        Route::post('/public/shop/orders/{id}/cancel', [App\Http\Controllers\Api\Public\UserOrdersController::class, 'cancel']);
        Route::post('/public/shop/orders/{id}/request-cancellation', [App\Http\Controllers\Api\Public\UserOrdersController::class, 'requestCancellation']);

        // Бонусы пользователя
        Route::get('/public/shop/user-bonuses', [App\Http\Controllers\Api\Public\UserBonusController::class, 'index']);
        Route::post('/public/shop/user-bonuses', [App\Http\Controllers\Api\Public\UserBonusController::class, 'store']);
        Route::get('/public/shop/user-bonuses/transactions', [App\Http\Controllers\Api\Public\UserBonusController::class, 'transactions']);
        Route::post('/public/user/deduct-bonuses', [App\Http\Controllers\Api\Public\UserBonusController::class, 'deductBonuses']);

        // CORS для списания бонусов
        Route::options('/public/user/deduct-bonuses', function () {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        });

        // Адреса пользователя
        Route::get('/public/shop/user-addresses', [App\Http\Controllers\Api\Public\UserAddressController::class, 'index']);
        Route::post('/public/shop/user-addresses', [App\Http\Controllers\Api\Public\UserAddressController::class, 'store']);
        Route::put('/public/shop/user-addresses/{id}', [App\Http\Controllers\Api\Public\UserAddressController::class, 'update']);
        Route::delete('/public/shop/user-addresses/{id}', [App\Http\Controllers\Api\Public\UserAddressController::class, 'destroy']);
        Route::post('/public/shop/user-addresses/{id}/set-default', [App\Http\Controllers\Api\Public\UserAddressController::class, 'setDefault']);

        // CORS для адресов пользователя
        Route::options('/public/shop/user-addresses', function () {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        });
        Route::options('/public/shop/user-addresses/{id}/set-default', function () {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        });
    });

    // Маршруты для предзаказов
    Route::options('/public/shop/preorders', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/preorders', [App\Http\Controllers\Api\Public\CartController::class, 'getPreorders']);
    Route::post('/public/shop/preorders/add', [App\Http\Controllers\Api\Public\CartController::class, 'addToPreorder']);

    Route::options('/public/shop/brands/{id}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/brands/{id}', [App\Http\Controllers\Api\Public\ShopBrandsController::class, 'show']);

    // Маршрут для получения бренда по slug
    Route::options('/public/shop/brands/slug/{slug}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/brands/slug/{slug}', [App\Http\Controllers\Api\Public\ShopBrandsController::class, 'getBySlug']);

    // Публичные маршруты для свойств товаров
    Route::options('/public/shop/properties', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/properties', [App\Http\Controllers\Api\Public\ShopPropertiesController::class, 'index']);

    Route::options('/public/shop/properties/{property}/values', function () {
        return response()->json([], 200);
    });
    Route::get('/public/shop/properties/{property}/values', [App\Http\Controllers\Api\Public\ShopPropertiesController::class, 'getValues']);

    // Публичный маршрут для поиска
    Route::options('/public/search', function () {
        return response()->json([], 200);
    });
    Route::get('/public/search', [App\Http\Controllers\Public\SearchController::class, 'search']);

    // Публичные маршруты для изображений магазина
    Route::options('/public/shop/images/batch', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/images/batch', [App\Http\Controllers\Api\Public\ShopImageController::class, 'getBatchImages']);

    Route::options('/public/shop/images/categories', function () {
        return response()->json([], 200);
    });
    Route::post('/public/shop/images/categories', [App\Http\Controllers\Api\Public\ShopImageController::class, 'getCategoryImages']);

    // Тестовый endpoint для проверки брендов
    Route::get('/public/shop/brands-debug', function () {
        try {
            $brandCount = \App\Models\ShopBrand::count();
            $activeBrandCount = \App\Models\ShopBrand::where('is_active', true)->count();
            $brands = \App\Models\ShopBrand::where('is_active', true)->select('id', 'name', 'slug', 'logo')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_brands' => $brandCount,
                    'active_brands' => $activeBrandCount,
                    'brands' => $brands
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ], 500);
        }
    });

    // Тестовый endpoint для проверки товаров
    Route::get('/public/shop/goods-debug', function () {
        try {
            $count = \App\Models\ShopGood::count();
            $activeCount = \App\Models\ShopGood::where('is_active', true)->count();
            $inStockCount = \App\Models\ShopGood::where('stock_quantity', '>', 0)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_goods' => $count,
                    'active_goods' => $activeCount,
                    'in_stock_goods' => $inStockCount,
                    'message' => 'Товары найдены'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ], 500);
        }
    });

    // Публичные маршруты для слайдеров
    Route::options('/public/sliders', function () {
        return response()->json([], 200);
    });
    Route::get('/public/sliders', [App\Http\Controllers\Api\Public\SliderController::class, 'index']);

    Route::options('/public/sliders/{id}', function () {
        return response()->json([], 200);
    });
    Route::get('/public/sliders/{id}', [App\Http\Controllers\Api\Public\SliderController::class, 'show']);

    // Публичные маршруты для согласий на куки
    Route::options('/public/cookie-consent/check', function () {
        return response()->json([], 200);
    });
    Route::get('/public/cookie-consent/check', [App\Http\Controllers\Api\Public\CookieConsentController::class, 'checkConsent']);

    Route::options('/public/cookie-consent/save', function () {
        return response()->json([], 200);
    });
    Route::post('/public/cookie-consent/save', [App\Http\Controllers\Api\Public\CookieConsentController::class, 'saveConsent']);

    Route::options('/public/cookie-consent/revoke', function () {
        return response()->json([], 200);
    });
    Route::post('/public/cookie-consent/revoke', [App\Http\Controllers\Api\Public\CookieConsentController::class, 'revokeConsent']);

    Route::options('/public/cookie-consent/info', function () {
        return response()->json([], 200);
    });
    Route::get('/public/cookie-consent/info', [App\Http\Controllers\Api\Public\CookieConsentController::class, 'getCookieInfo']);
});

// Временный отладочный endpoint для проверки всех настроек
Route::get('/public/debug/settings', function () {
    try {
        $settings = \App\Models\Setting::select('key', 'value', 'type', 'group')
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ], 500);
    }
});

// Маршрут для получения данных контактов для заголовка
Route::options('/public/contacts/header-data', function () {
    return response()->json([], 200);
});


// Публичные маршруты
Route::get('/contacts/main-address', [\App\Http\Controllers\ContactController::class, 'getMainAddress']);
Route::get('/contacts/main-phone', [\App\Http\Controllers\ContactController::class, 'getMainPhone']);
Route::get('/contacts/main-contact-phones', [\App\Http\Controllers\ContactController::class, 'getMainContactPhones']);

// Сообщения с сайта (публичные)
Route::post('/site-messages', [\App\Http\Controllers\SiteMessageController::class, 'store']);

// Подписка на новости (публичные)
Route::post('/site-newsletter', [\App\Http\Controllers\SiteNewsletterController::class, 'subscribe']);
Route::post('/site-newsletter/unsubscribe', [\App\Http\Controllers\SiteNewsletterController::class, 'unsubscribe']);

// Защищенные маршруты (требуют авторизации) - используют только token authentication
Route::middleware('auth:sanctum')->group(function () {
    // Авторизация
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/auth/check', [AuthController::class, 'check']);

    // Избранное - простой функционал
    Route::prefix('shop/favorites')->group(function () {
        Route::post('/toggle', [\App\Http\Controllers\FavoritesController::class, 'toggle']);
        Route::get('/check', [\App\Http\Controllers\FavoritesController::class, 'check']);
        Route::get('/', [\App\Http\Controllers\FavoritesController::class, 'index']);
    });

    // Сравнение товаров - простой функционал
    Route::prefix('shop/comparison')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'index']);
        Route::post('/add', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'add']);
        Route::post('/remove', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'remove']);
        Route::delete('/remove/{id}', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'remove']);
        Route::delete('/clear', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'clear']);
        Route::get('/check', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'check']);
        Route::post('/check-multiple', [\App\Http\Controllers\Api\Public\ComparisonController::class, 'checkMultiple']);
    });

    // Загрузка изображений для rich editor
    Route::post('/admin/upload/good-text-image', [\App\Http\Controllers\Admin\UploadController::class, 'uploadGoodTextImage']);

    // Контакты (доступны админам и пользователям с ролью site)
    Route::middleware(['auth:sanctum', 'role:admin,site'])->prefix('contacts')->group(function () {
        Route::get('/', [\App\Http\Controllers\ContactController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\ContactController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\ContactController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\ContactController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\ContactController::class, 'destroy']);
        Route::get('/social-types/list', [\App\Http\Controllers\ContactController::class, 'getSocialTypes']);
    });

    // Управление типами социальных сетей
    Route::middleware(['auth:sanctum', 'role:admin,site'])->prefix('contact-social-types')->group(function () {
        Route::get('/', [\App\Http\Controllers\ContactSocialTypeController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ContactSocialTypeController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\ContactSocialTypeController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\ContactSocialTypeController::class, 'destroy']);
    });

    // Сообщения с сайта (доступны пользователям с доступом к разделу shop)
    Route::middleware(['auth:sanctum', 'shop.access'])->prefix('site-messages')->group(function () {
        Route::get('/', [\App\Http\Controllers\SiteMessageController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\SiteMessageController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\SiteMessageController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\SiteMessageController::class, 'destroy']);
        Route::post('/{id}/mark-processed', [\App\Http\Controllers\SiteMessageController::class, 'markAsProcessed']);
        Route::get('/stats/overview', [\App\Http\Controllers\SiteMessageController::class, 'stats']);
    });

    // Подписчики на новости (доступны админам)
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('site-newsletter')->group(function () {
        Route::get('/', [\App\Http\Controllers\SiteNewsletterController::class, 'index']);
    });

    // Временный тестовый маршрут для Google Sheets (без авторизации)
    Route::get('/test-google-sheets/{spreadsheetId}', function ($spreadsheetId) {
        try {
            $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid=0";

            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'text/csv,text/plain,*/*',
                ])
                ->get($csvUrl);

            return response()->json([
                'url' => $csvUrl,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'headers' => $response->headers(),
                'body_preview' => substr($response->body(), 0, 1000),
                'body_length' => strlen($response->body())
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    });

    // Google Sheets (вне middleware для тестирования)
    Route::post('/admin/shop/goods/load-google-sheets', function (Request $request) {
        try {
            $request->validate([
                'spreadsheetId' => 'required|string'
            ]);

            $spreadsheetId = $request->input('spreadsheetId');

            // Извлекаем ID из полного URL, если пользователь ввел полный URL
            if (strpos($spreadsheetId, 'docs.google.com/spreadsheets/d/') !== false) {
                preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $spreadsheetId, $matches);
                if (isset($matches[1])) {
                    $spreadsheetId = $matches[1];
                }
            }

            Log::info('Loading Google Sheets', [
                'original_id' => $request->input('spreadsheetId'),
                'extracted_id' => $spreadsheetId
            ]);
            $sheets = [];

            // Пробуем загрузить разные листы (обычно gid=0, 1, 2, 3...)
            $maxSheets = 10; // Максимум 10 листов для проверки

            // Пробуем разные подходы к загрузке Google Sheets

            // Подход 1: Стандартный CSV export
            $gidsToTry = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

            foreach ($gidsToTry as $gid) {
                try {
                    // Пробуем разные варианты URL
                    $csvUrls = [
                        "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}",
                        "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}&usp=sharing",
                        "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&gid={$gid}",
                    ];

                    foreach ($csvUrls as $csvUrl) {
                        Log::info("Trying CSV export gid={$gid}", ['url' => $csvUrl]);

                        $response = \Illuminate\Support\Facades\Http::timeout(30)
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                                'Accept' => 'text/csv,text/plain,*/*',
                                'Accept-Language' => 'en-US,en;q=0.9',
                                'Accept-Encoding' => 'gzip, deflate, br',
                                'Connection' => 'keep-alive',
                                'Upgrade-Insecure-Requests' => '1',
                            ])
                            ->get($csvUrl);

                        Log::info("CSV response for gid={$gid}", [
                            'url' => $csvUrl,
                            'status' => $response->status(),
                            'successful' => $response->successful(),
                            'body_length' => strlen($response->body()),
                            'headers' => $response->headers()
                        ]);

                        if ($response->successful()) {
                            $csvText = $response->body();
                            $lines = array_filter(explode("\n", $csvText), 'trim');

                            Log::info("CSV data for gid={$gid}", [
                                'lines_count' => count($lines),
                                'first_line' => $lines[0] ?? 'empty',
                                'sample_data' => array_slice($lines, 0, 3)
                            ]);

                            if (count($lines) > 0) {
                                $headers = str_getcsv($lines[0]);
                                $data = array_map('str_getcsv', array_slice($lines, 1));

                                Log::info("Parsed data for gid={$gid}", [
                                    'headers_count' => count($headers),
                                    'data_rows' => count($data),
                                    'headers' => $headers,
                                    'sample_row' => $data[0] ?? 'empty'
                                ]);

                                // Проверяем, что лист не пустой
                                if (count($headers) > 0 && count($data) > 0) {
                                    $sheets[] = [
                                        'gid' => $gid,
                                        'name' => "Лист " . ($gid + 1),
                                        'headers' => $headers,
                                        'data' => $data,
                                    ];
                                    Log::info("Sheet gid={$gid} added successfully");
                                    break 2; // Выходим из обоих циклов, если нашли данные
                                } else {
                                    Log::info("Sheet gid={$gid} is empty or invalid");
                                }
                            } else {
                                Log::info("Sheet gid={$gid} has no lines");
                            }
                        } else {
                            Log::warning("Sheet gid={$gid} failed to load", [
                                'url' => $csvUrl,
                                'status' => $response->status(),
                                'body' => substr($response->body(), 0, 500) // Первые 500 символов
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("Google Sheets gid {$gid} error: " . $e->getMessage());
                    continue;
                }
            }

            // Подход 2: Если CSV не работает, пробуем HTML export
            if (empty($sheets)) {
                Log::info("CSV export failed, trying HTML export");

                try {
                    $htmlUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=html&gid=0";
                    Log::info("Trying HTML export", ['url' => $htmlUrl]);

                    $response = \Illuminate\Support\Facades\Http::timeout(30)->get($htmlUrl);

                    if ($response->successful()) {
                        Log::info("HTML export successful", [
                            'status' => $response->status(),
                            'body_length' => strlen($response->body())
                        ]);

                        // Парсим HTML таблицу
                        $html = $response->body();
                        if (preg_match('/<table[^>]*>(.*?)<\/table>/s', $html, $matches)) {
                            $tableHtml = $matches[1];
                            Log::info("Found HTML table", ['table_length' => strlen($tableHtml)]);

                            // Простой парсинг HTML таблицы
                            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tableHtml, $rows)) {
                                $data = [];
                                foreach ($rows[1] as $rowHtml) {
                                    if (preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/s', $rowHtml, $cells)) {
                                        $rowData = array_map('strip_tags', $cells[1]);
                                        $rowData = array_map('trim', $rowData);
                                        if (!empty(array_filter($rowData))) {
                                            $data[] = $rowData;
                                        }
                                    }
                                }

                                if (count($data) > 1) {
                                    $headers = $data[0];
                                    $rows = array_slice($data, 1);

                                    $sheets[] = [
                                        'gid' => 0,
                                        'name' => "Лист 1",
                                        'headers' => $headers,
                                        'data' => $rows,
                                    ];
                                    Log::info("HTML sheet added successfully", [
                                        'headers_count' => count($headers),
                                        'rows_count' => count($rows)
                                    ]);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("HTML export failed: " . $e->getMessage());
                }
            }

            if (empty($sheets)) {
                Log::warning('No sheets found in Google Sheets', [
                    'spreadsheetId' => $spreadsheetId,
                    'tried_gids' => $gidsToTry,
                    'total_attempts' => count($gidsToTry)
                ]);

                // Возвращаем детальную информацию для отладки
                return response()->json([
                    'success' => false,
                    'message' => 'Не найдено ни одного листа с данными в таблице',
                    'debug' => [
                        'spreadsheetId' => $spreadsheetId,
                        'tried_gids' => $gidsToTry,
                        'total_attempts' => count($gidsToTry),
                        'suggestion' => 'Проверьте, что таблица публично доступна для чтения'
                    ]
                ], 404);
            }

            Log::info('Google Sheets loaded successfully', [
                'spreadsheetId' => $spreadsheetId,
                'sheetsCount' => count($sheets)
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'sheets' => $sheets
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки Google Sheets: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки данных: ' . $e->getMessage()
            ], 500);
        }
    });


    // Маршруты для администраторов и менеджеров
    Route::middleware(['auth:sanctum', 'role:admin,manager'])->prefix('admin')->group(function () {
        // Site info for admin
        Route::get('/site-info', [\App\Http\Controllers\Api\Public\SiteInfoController::class, 'index']);

        // Profile management
        Route::get('/profile', [\App\Http\Controllers\Api\Admin\ProfileController::class, 'index']);
        
        // Тестовый endpoint для диагностики профиля
        Route::get('/profile/debug', function (Request $request) {
            try {
                $user = $request->user();
                if (!$user) {
                    return response()->json(['error' => 'User not authenticated'], 401);
                }
                
                $result = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                ];
                
                // Пробуем получить роли через прямой запрос
                try {
                    $roles = \Illuminate\Support\Facades\DB::table('user_roles')
                        ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                        ->where('user_roles.user_id', $user->id)
                        ->get(['roles.name', 'user_roles.is_active']);
                    $result['roles_direct'] = $roles->pluck('name')->toArray();
                    $result['roles_data'] = $roles->toArray();
                } catch (\Exception $e) {
                    $result['roles_direct_error'] = $e->getMessage();
                }
                
                // Пробуем получить роли через связь
                try {
                    $user->load('roles');
                    $result['roles_relation'] = $user->roles ? $user->roles->pluck('name')->toArray() : [];
                } catch (\Exception $e) {
                    $result['roles_relation_error'] = $e->getMessage();
                }
                
                return response()->json($result);
            } catch (\Exception $e) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ], 500);
            }
        });

        // Тестовые маршруты для Google Sheets
        Route::get('/test-google-sheets', function () {
            return response()->json(['message' => 'Google Sheets API is working']);
        });

        Route::get('/test-controller', [\App\Http\Controllers\Admin\GoogleSheetsController::class, 'test']);

        // Тестовый маршрут для проверки Google Sheets
        Route::get('/test-google-sheets/{spreadsheetId}', function ($spreadsheetId) {
            try {
                $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid=0";

                $response = \Illuminate\Support\Facades\Http::timeout(30)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                        'Accept' => 'text/csv,text/plain,*/*',
                    ])
                    ->get($csvUrl);

                return response()->json([
                    'url' => $csvUrl,
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                    'headers' => $response->headers(),
                    'body_preview' => substr($response->body(), 0, 1000),
                    'body_length' => strlen($response->body())
                ]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()]);
            }
        });

        // Settings management
        Route::prefix('settings')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SettingController::class, 'index']);

            // Обновление настроек (доступно менеджерам для настроек магазина, админам - для всех)
            Route::put('/{id}', [\App\Http\Controllers\Admin\SettingController::class, 'update']);

            // Только админы могут создавать, удалять настройки и работать с изображениями
            Route::middleware('role:admin')->group(function () {
                Route::post('/', [\App\Http\Controllers\Admin\SettingController::class, 'store']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\SettingController::class, 'destroy']);

                // Маршрут для загрузки изображений
                Route::post('/{id}/image', [\App\Http\Controllers\Admin\SettingController::class, 'uploadImage']);

                // Маршрут для удаления изображений
                Route::delete('/{id}/image', [\App\Http\Controllers\Admin\SettingController::class, 'deleteImage']);

                // Маршрут для изменения размера изображения
                Route::post('/{id}/resize', [\App\Http\Controllers\Admin\SettingController::class, 'resizeImage']);
            });
        });

        // Roles management (только для админов)
        Route::middleware('role:admin')->prefix('roles')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\RoleController::class, 'index']);
            Route::get('/{id}', [\App\Http\Controllers\Admin\RoleController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Admin\RoleController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Admin\RoleController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Admin\RoleController::class, 'destroy']);
            Route::put('/{id}/status', [\App\Http\Controllers\Admin\RoleController::class, 'updateStatus']);
            Route::get('/statistics/overview', [\App\Http\Controllers\Admin\RoleController::class, 'statistics']);
        });

            // Site Menus management (только для админов)
    Route::middleware('role:admin')->prefix('site-menus')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SiteMenuController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\SiteMenuController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'destroy']);
        Route::put('/{id}/status', [\App\Http\Controllers\Admin\SiteMenuController::class, 'updateStatus']);
        Route::get('/statistics/overview', [\App\Http\Controllers\Admin\SiteMenuController::class, 'statistics']);

        // Menu Items management
        Route::get('/{menuId}/items', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'index']);
        Route::post('/{menuId}/items', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'store']);
        Route::put('/items/order', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'updateOrder']);
        Route::get('/items/{id}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'show']);
        Route::put('/items/{id}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'update']);
        Route::delete('/items/{id}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'destroy']);
    });

        // Debug endpoint для проверки ролей (только для админов)
        Route::middleware('role:admin')->get('/debug/roles', function () {
            try {
                $roles = \App\Models\Role::all();
                return response()->json([
                    'success' => true,
                    'data' => $roles,
                    'message' => 'Roles debug info'
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка: ' . $e->getMessage()
                ], 500);
            }
        });

        // Categories management (просмотр доступен менеджерам и админам)
        Route::prefix('categories')->group(function () {
            // Просмотр категорий доступен менеджерам и админам
            Route::get('/', [\App\Http\Controllers\CategoryController::class, 'index']);
            Route::get('/active', [\App\Http\Controllers\CategoryController::class, 'active']);
            Route::get('/{id}', [\App\Http\Controllers\CategoryController::class, 'show']);
        });

        // Categories management (создание/редактирование/удаление для пользователей с доступом к shop)
        Route::middleware('shop.access')->prefix('categories')->group(function () {
            // Батч-операции с категориями - должны быть ПЕРЕД маршрутом /{id}
            Route::put('/add-to-parent', [\App\Http\Controllers\CategoryController::class, 'addToParent']);
            Route::put('/batch-update', [\App\Http\Controllers\CategoryController::class, 'batchUpdate']);
            
            Route::post('/', [\App\Http\Controllers\CategoryController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\CategoryController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\CategoryController::class, 'destroy']);
            Route::post('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'uploadCategoryImage']);
            Route::delete('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'deleteCategoryImage']);
            Route::post('/{id}/figure-image', [\App\Http\Controllers\ImageUploadController::class, 'uploadCategoryFigureImage']);
            Route::delete('/{id}/figure-image', [\App\Http\Controllers\ImageUploadController::class, 'deleteCategoryFigureImage']);
            Route::post('/temp/image', [\App\Http\Controllers\ImageUploadController::class, 'uploadTempImage']);
            Route::post('/upload-image', [\App\Http\Controllers\CategoryController::class, 'uploadImage']);
            Route::post('/sort-alphabetically', [\App\Http\Controllers\CategoryController::class, 'sortAlphabetically']);
            Route::post('/import', [\App\Http\Controllers\CategoryController::class, 'import']);

            // Экстра-меню категорий
            Route::get('/{id}/extra-menu', [\App\Http\Controllers\CategoryExtraMenuController::class, 'show']);
            Route::put('/{id}/extra-menu', [\App\Http\Controllers\CategoryExtraMenuController::class, 'update']);
            Route::get('/{id}/extra-menu/characteristics', [\App\Http\Controllers\CategoryExtraMenuController::class, 'getCharacteristics']);
            Route::get('/{id}/extra-menu/child-categories', [\App\Http\Controllers\CategoryExtraMenuController::class, 'getChildCategories']);

            // Изменить порядок категорий
            Route::post('/order', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'items' => 'required|array',
                        'items.*.id' => 'required|exists:shop_categories,id',
                        'items.*.sort_order' => 'required|integer|min:0'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    foreach ($request->items as $item) {
                        \App\Models\ShopCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Порядок категорий успешно обновлен'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления порядка: ' . $e->getMessage()
                    ], 500);
                }
            });
        });

        // Настройки магазина (для пользователей с доступом к shop)
        Route::middleware('shop.access')->group(function () {
            Route::get('/shop-settings', [\App\Http\Controllers\ShopSettingsController::class, 'getShopSettings']);
            Route::get('/shop-settings/{key}', [\App\Http\Controllers\ShopSettingsController::class, 'getShopSetting']);
        });


        // Shop management (для пользователей с доступом к shop)
        Route::middleware(['auth:sanctum', 'role:admin,manager'])->prefix('shop')->group(function () {
            // Товары
            Route::prefix('goods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'index']);
                Route::get('/filters', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'filters']);
                Route::get('/categories-stats', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'getCategoriesStats']);
                Route::get('/characteristics/list', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'getCharacteristicsList']);
                Route::post('/categories', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'createCategory']);
                Route::post('/brands', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'createBrand']);
                Route::post('/labels', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'createLabel']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'update']);
                Route::put('/{id}/properties', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'updateProperties']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'destroy']);
                Route::post('/bulk-update', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'bulkUpdate']);
                Route::post('/transfer-data', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'transferData']);
                Route::post('/check-duplicates', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'checkDuplicates']);
                Route::post('/bulk-import', [\App\Http\Controllers\Admin\BulkGoodsImportController::class, 'bulkImport']);
                Route::post('/mass-parse-properties', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'massParseProperties']);

                // Управление изображениями товаров
                Route::prefix('{good}/images')->group(function () {
                    Route::get('/', [ShopGoodImageController::class, 'index']);
                    Route::post('/', [ShopGoodImageController::class, 'store']);
                    Route::post('/{image}/set-main', [ShopGoodImageController::class, 'setMain']);
                    Route::delete('/{image}', [ShopGoodImageController::class, 'destroy']);
                    Route::post('/reorder', [ShopGoodImageController::class, 'reorder']);
                });

                // Импорт/экспорт товаров
                Route::prefix('import-export')->group(function () {
                    Route::post('/export/csv', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'exportCsv']);
                    Route::post('/export/excel', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'exportExcel']);
                    Route::post('/import/csv', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'importCsv']);
                    Route::get('/template', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'getTemplate']);
                });


                // Скачивание изображений для импорта
                Route::post('/download-image', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'downloadImage']);
                Route::post('/download-images-batch', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'downloadImagesBatch']);
                Route::post('/save-image-to-frontend', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'saveImageToFrontend']);

                // Прокси для загрузки YML фидов (обход CORS)
                Route::post('/yml-feed-proxy', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'proxyYMLFeed']);

                // Вариации товаров
                Route::prefix('{goodId}/variations')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'index']);
                    // Новая схема атрибутов вариаций
                    Route::get('/attributes', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'listAttributes']);
                    Route::get('/attributes/{attributeId}/values', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'getAttributeValues']);
                    Route::post('/attributes', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'createAttribute']);
                    Route::post('/attributes/{attributeId}/values', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'createAttributeValue']);
                    Route::put('/attributes/{attributeId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'updateAttribute']);
                    Route::delete('/attributes/{attributeId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'deleteAttribute']);
                    Route::get('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'show']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'store']);
                    Route::post('/bulk', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'storeBulk']);
                    Route::post('/check-duplicate', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'checkDuplicate']);
                    Route::post('/add-attribute-to-all', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'addAttributeToAll']);
                    Route::post('/add-property', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'addProperty']);
                    Route::post('/remove-property', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'removeProperty']);
                    Route::put('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'update']);
                    Route::delete('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'destroy']);
                    Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'reorder']);
                    Route::post('/bulk-update', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'bulkUpdate']);
                    // Атрибуты и остатки вариаций
                    Route::get('/{variationId}/attributes', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'getVariationAttributes']);
                    Route::put('/{variationId}/stock', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'updateVariationStock']);
                    Route::put('/{variationId}/remote-stock', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'updateVariationRemoteStock']);
                    Route::put('/{variationId}/price', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'updateVariationPrice']);
                    Route::put('/{variationId}/demping', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'updateVariationDemping']);
                });
                
                // Глобальное создание атрибутов вариаций (без привязки к товару)
                Route::post('/variations/attributes/global', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'createAttributeGlobal']);
                // Получить список всех характеристик вариаций (без привязки к товару)
                Route::get('/variations/attributes', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'listAttributes']);
                // Массовая загрузка атрибутов вариаций
                Route::post('/variations/attributes/bulk', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'getBulkAttributes']);
                
                // Глобальное массовое обновление вариаций (без привязки к товару)
                Route::post('/variations/bulk-update', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'globalBulkUpdate']);
                // Получить ID вариаций для списка товаров
                Route::post('/variations/get-ids', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'getVariationIdsByGoods']);

                // Видео товаров
                Route::prefix('{goodId}/videos')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'index']);
                    Route::get('/all', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'getAllWithVariations']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'store']);
                    Route::put('/{videoId}', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'update']);
                    Route::delete('/{videoId}', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'destroy']);
                    Route::post('/{videoId}/set-main', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'setMain']);
                    Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'reorder']);
                });

                // Изображения товаров
                Route::prefix('{goodId}/images')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'index']);
                    Route::get('/all', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'getAllWithVariations']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'store']);
                    Route::put('/{imageId}', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'update']);
                    Route::delete('/{imageId}', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'destroy']);
                    Route::put('/{imageId}/main', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'setMain']);
                    Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'reorder']);
                    Route::post('/import', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'createFromImport']);
                });

                // Пакетное создание изображений для импорта
                Route::post('/images/import-batch', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'createFromImportBatch']);

                // Видео товаров
                Route::prefix('{goodId}/videos')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'store']);
                    Route::put('/{videoId}', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'update']);
                    Route::delete('/{videoId}', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'destroy']);
                    Route::put('/{videoId}/main', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'setMain']);
                    Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodVideosController::class, 'reorder']);
                });
            });

            // Изображения вариаций
            Route::prefix('variations/{variationId}/images')->group(function () {
                Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'reorderVariation']);
            });

            // Свойства товаров
            Route::prefix('properties')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\Shop\PropertyController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\Shop\PropertyController::class, 'store']);
                Route::put('/{property}', [\App\Http\Controllers\Admin\Shop\PropertyController::class, 'update']);
                Route::delete('/{property}', [\App\Http\Controllers\Admin\Shop\PropertyController::class, 'destroy']);

                // Значения свойств
                Route::prefix('{propertyId}/values')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopPropertyValuesController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopPropertyValuesController::class, 'store']);
                    Route::post('/check', [\App\Http\Controllers\Admin\ShopPropertyValuesController::class, 'check']);
                    Route::put('/{valueId}', [\App\Http\Controllers\Admin\ShopPropertyValuesController::class, 'update']);
                    Route::delete('/{valueId}', [\App\Http\Controllers\Admin\ShopPropertyValuesController::class, 'destroy']);
                });
            });

            // Бренды
            Route::prefix('brands')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'index']);
                Route::get('/active', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'active']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'destroy']);
                Route::post('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'uploadBrandImage']);
                Route::delete('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'deleteBrandImage']);
                Route::post('/order', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'updateOrder']);
                Route::post('/import', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'import']);
            });

            // Теги
            Route::prefix('tags')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopTagsController::class, 'index']);
                Route::get('/active', [\App\Http\Controllers\Admin\ShopTagsController::class, 'active']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopTagsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopTagsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopTagsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopTagsController::class, 'destroy']);
            });

            // Лейблы
            Route::prefix('labels')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'index']);
                Route::get('/all', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'all']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopLabelsController::class, 'destroy']);
            });


            // Заказы
            Route::prefix('orders')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'index']);
                Route::get('/statistics', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'statistics']);
                Route::get('/statuses', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getStatuses']);
                Route::get('/statuses/all', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getAllStatuses']);
                Route::post('/statuses', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'createOrderStatus']);
                Route::put('/statuses/reorder', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'reorderStatuses']);
                Route::put('/statuses/{statusId}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateOrderStatus']);
                Route::delete('/statuses/{statusId}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'deleteOrderStatus']);
                // Logs
                Route::post('/logs/stats', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getOrdersLogsStats']);
                Route::get('/log-icons', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getLogIcons']);
                Route::post('/log-icons', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'createLogIcon']);
                Route::put('/log-icons/{iconId}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateLogIcon']);
                Route::delete('/log-icons/{iconId}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'deleteLogIcon']);
                Route::get('/{orderId}/logs', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getOrderLogs']);
                Route::post('/{orderId}/logs', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'addOrderLog']);
                Route::put('/{id}/pay-agree', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updatePayAgree']);
                // Bulk operations
                Route::post('/bulk/delete', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'bulkDelete']);
                Route::post('/bulk/status', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'bulkUpdateStatus']);
                Route::post('/bulk/payed', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'bulkUpdatePayed']);
                Route::post('/bulk/log', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'bulkAddLog']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'store']);
                Route::post('/export', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'export']);
                // Специфичные роуты должны быть определены ПЕРЕД общими роутами с {id}
                Route::put('/{id}/status', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateStatus']);
                Route::put('/{id}/payed', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updatePayed']);
                Route::put('/{id}/is-active', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateIsActive']);
                Route::put('/{id}/comment', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateComment']);
                Route::put('/{id}/delivery-status', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateDeliveryStatus']);
                // OPTIONS для CORS preflight
                Route::options('/{id}/finish', function () {
                    return response('', 200)
                        ->header('Access-Control-Allow-Origin', '*')
                        ->header('Access-Control-Allow-Methods', 'PUT, OPTIONS')
                        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
                        ->header('Access-Control-Allow-Credentials', 'true')
                        ->header('Access-Control-Max-Age', '86400');
                });
                Route::put('/{id}/finish', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'finishOrder']);
                Route::get('/{id}/cdek/barcode', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getCdekBarcode']);
                Route::get('/{id}/cdek/barcode/download', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'downloadCdekBarcode']);
                Route::get('/{id}/cdek/waybill', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'getCdekWaybill']);
                Route::get('/{id}/cdek/waybill/download', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'downloadCdekWaybill']);
                Route::post('/{id}/items', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'addItem']);
                Route::delete('/{id}/items/{itemId}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'removeItem']);
                // Общие роуты с {id} должны быть в конце
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'show']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'destroy']);
            });

            // Предзаказы
            Route::prefix('preorders')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'index']);
                Route::get('/stats/overview', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'stats']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'show']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'destroy']);
                Route::put('/{id}/status', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'updateStatus']);
                Route::get('/{id}/logs', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'logs']);
                Route::post('/{id}/logs', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'addLog']);
                Route::post('/{id}/log-add-to-cart', [\App\Http\Controllers\Admin\ShopPreordersController::class, 'logAddToCart']);
            });

            // Промокоды
            Route::prefix('promocodes')->group(function () {
                Route::get('/test', function () {
                    return response()->json([
                        'success' => true,
                        'message' => 'API работает',
                        'count' => \App\Models\Promocode::count()
                    ]);
                });
                Route::get('/', [\App\Http\Controllers\Admin\PromocodeController::class, 'index']);
                Route::get('/select-data', [\App\Http\Controllers\Admin\PromocodeController::class, 'getSelectData']);
                Route::get('/search-items', [\App\Http\Controllers\Admin\PromocodeController::class, 'searchItems']);
                Route::get('/{id}/stats', [\App\Http\Controllers\Admin\PromocodeController::class, 'stats']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\PromocodeController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\PromocodeController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\PromocodeController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\PromocodeController::class, 'destroy']);
            });

            // Оповещения магазина
            Route::prefix('notifications')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'destroy']);
                Route::post('/{id}/toggle-active', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'toggleActive']);
                Route::post('/test-telegram', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'testTelegram']);
                Route::post('/test-email', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'testEmail']);
                Route::post('/get-bot-info', [\App\Http\Controllers\Api\Admin\ShopNotificationController::class, 'getBotInfo']);
            });

            // Управление способами доставки
            Route::prefix('delivery-methods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'destroy']);
                Route::post('/reorder', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'reorder']);

                // Изображения способов доставки
                Route::post('/upload-image', [\App\Http\Controllers\Api\Admin\DeliveryMethodImageController::class, 'upload']);
                Route::post('/remove-image', [\App\Http\Controllers\Api\Admin\DeliveryMethodImageController::class, 'remove']);
            });

            // Управление способами оплаты
            Route::prefix('payment-methods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'destroy']);
                Route::post('/reorder', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'reorder']);

                // Изображения способов оплаты
                Route::post('/upload-image', [\App\Http\Controllers\Api\Admin\PaymentMethodImageController::class, 'upload']);
                Route::post('/remove-image', [\App\Http\Controllers\Api\Admin\PaymentMethodImageController::class, 'remove']);
            });

            // Управление настройками СДЭК
            Route::prefix('cdek')->group(function () {
                Route::get('/settings', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'index']);
                Route::get('/settings/active', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'getActive']);
                Route::post('/settings', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'store']);
                Route::put('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'update']);
                Route::delete('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'destroy']);
                Route::post('/settings/{id}/activate', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'activate']);
                Route::post('/validate-keys', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'validateKeys']);
                Route::get('/available-tariffs', [\App\Http\Controllers\Api\Admin\ShopCdekSettingsController::class, 'getAvailableTariffs']);
            });

            // Управление настройками Деловых линий
            Route::prefix('dellin')->group(function () {
                Route::get('/settings', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'index']);
                Route::get('/settings/active', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'getActive']);
                Route::post('/settings', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'store']);
                Route::put('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'update']);
                Route::delete('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'destroy']);
                Route::post('/settings/{id}/activate', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'activate']);
                Route::post('/validate-key', [\App\Http\Controllers\Api\Admin\ShopDellinSettingsController::class, 'validateKey']);
            });

            // Управление настройками Почты России
            Route::prefix('russianpost')->group(function () {
                Route::get('/settings', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'index']);
                Route::get('/settings/active', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'getActive']);
                Route::post('/settings', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'store']);
                Route::put('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'update']);
                Route::delete('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'destroy']);
                Route::post('/settings/{id}/activate', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'activate']);
                Route::post('/validate-credentials', [\App\Http\Controllers\Api\Admin\ShopRussianPostSettingsController::class, 'validateCredentials']);
            });

            // Управление настройками DPD
            Route::prefix('dpd')->group(function () {
                Route::get('/settings', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'index']);
                Route::get('/settings/active', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'getActive']);
                Route::post('/settings', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'store']);
                Route::put('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'update']);
                Route::delete('/settings/{id}', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'destroy']);
                Route::post('/settings/{id}/activate', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'activate']);
                Route::post('/validate-credentials', [\App\Http\Controllers\Api\Admin\ShopDpdSettingsController::class, 'validateCredentials']);
            });
        });

        // Users management
        Route::prefix('users')->group(function () {
            Route::get('/statistics', function () {
                $stats = [
                    'total_users' => \App\Models\User::count(),
                    'admins' => \App\Models\User::whereHas('roles', function($query) {
                        $query->where('name', 'admin');
                    })->count(),
                    'managers' => \App\Models\User::whereHas('roles', function($query) {
                        $query->where('name', 'manager');
                    })->count(),
                    'regular_users' => \App\Models\User::whereHas('roles', function($query) {
                        $query->where('name', 'user');
                    })->count(),
                    'recent_registrations' => \App\Models\User::where('created_at', '>=', now()->subDays(7))->count(),
                ];

                return response()->json([
                    'success' => true,
                    'data' => $stats,
                    'message' => 'Statistics retrieved successfully'
                ]);
            });
        });

        // Pages management
        Route::prefix('pages')->group(function () {
            // Получить только доступные страницы для текущего пользователя
            Route::get('/accessible', function (Request $request) {
                try {
                    $user = $request->user();
                    $accessiblePages = [];

                    // Получаем все страницы
                    $allPages = \App\Models\AdminPage::orderBy('order')->get();

                    foreach ($allPages as $page) {
                        $hasAccess = false;

                        // Админ имеет доступ ко всем страницам
                        if ($user->hasRole('admin')) {
                            $hasAccess = true;
                        } else {
                            // Dashboard доступен всем авторизованным пользователям
                            if ($page->slug === 'dashboard') {
                                $hasAccess = true;
                            } else {
                                // Проверяем, есть ли у роли пользователя доступ к этой странице
                                $hasAccess = $page->roles()->whereIn('role_id', $user->roles->pluck('id'))->exists();
                            }
                        }

                        if ($hasAccess) {
                            $accessiblePages[] = $page;
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'data' => $accessiblePages
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения доступных страниц: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Получить все страницы (для админов)
            Route::get('/', function () {
                try {
                    $pages = \App\Models\AdminPage::orderBy('order')->get();

                    return response()->json([
                        'success' => true,
                        'data' => $pages
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения списка страниц: ' . $e->getMessage()
                    ], 500);
                }
            });

            Route::get('/by-slug/{slug}', function ($slug) {
                try {
                    // Специальная обработка для страницы menu
                    if ($slug === 'menu') {
                        return response()->json([
                            'success' => true,
                            'data' => [
                                'id' => null,
                                'name' => 'Menu',
                                'slug' => 'menu',
                                'title' => 'Управление меню',
                                'description' => 'Настройка пунктов меню администратора',
                                'icon' => 'fas fa-bars',
                                'component' => 'MenuManager',
                                'order' => 0,
                                'is_active' => true,
                                'created_at' => null,
                                'updated_at' => null,
                            ]
                        ]);
                    }

                    // Обычная обработка для других страниц
                    $page = \App\Models\AdminPage::where('slug', $slug)->first();

                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    return response()->json([
                        'success' => true,
                        'data' => $page
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения страницы: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Только админы могут создавать/редактировать/удалять страницы
            Route::middleware('role:admin')->group(function () {
                // Создать новую страницу
                Route::post('/', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255|unique:admin_pages,name',
                        'slug' => 'required|string|max:255|unique:admin_pages,slug',
                        'title' => 'nullable|string|max:255',
                        'description' => 'nullable|string|max:1000',
                        'icon' => 'nullable|string|max:255',
                        'component' => 'nullable|string|max:255',
                        'order' => 'nullable|integer|min:0',
                        'is_active' => 'boolean'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $page = \App\Models\AdminPage::create([
                        'name' => $request->name,
                        'slug' => $request->slug,
                        'title' => $request->title,
                        'description' => $request->description,
                        'icon' => $request->icon,
                        'component' => $request->component,
                        'order' => $request->order ?? 0,
                        'is_active' => $request->is_active ?? true
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Страница успешно создана',
                        'data' => $page
                    ], 201);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка создания страницы: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Обновить страницу
            Route::put('/{id}', function (Request $request, $id) {
                try {
                    $page = \App\Models\AdminPage::find($id);

                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255|unique:admin_pages,name,' . $id,
                        'slug' => 'required|string|max:255|unique:admin_pages,slug,' . $id,
                        'title' => 'nullable|string|max:255',
                        'description' => 'nullable|string|max:1000',
                        'icon' => 'nullable|string|max:255',
                        'component' => 'nullable|string|max:255',
                        'order' => 'nullable|integer|min:0',
                        'is_active' => 'boolean'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $page->update([
                        'name' => $request->name,
                        'slug' => $request->slug,
                        'title' => $request->title,
                        'description' => $request->description,
                        'icon' => $request->icon,
                        'component' => $request->component,
                        'order' => $request->order ?? $page->order,
                        'is_active' => $request->is_active ?? $page->is_active
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Страница успешно обновлена',
                        'data' => $page
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления страницы: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Удалить страницу
            Route::delete('/{id}', function ($id) {
                try {
                    $page = \App\Models\AdminPage::find($id);

                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    $page->delete();

                    return response()->json([
                        'success' => true,
                        'message' => 'Страница успешно удалена'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка удаления страницы: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Изменить статус страницы
            Route::put('/{id}/status', function (Request $request, $id) {
                try {
                    $page = \App\Models\AdminPage::find($id);

                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'is_active' => 'required|boolean'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $page->update([
                        'is_active' => $request->is_active
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Статус страницы успешно обновлен',
                        'data' => [
                            'id' => $page->id,
                            'is_active' => $page->is_active
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления статуса: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Изменить порядок страниц
            Route::post('/order', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'items' => 'required|array',
                        'items.*.id' => 'required|exists:admin_pages,id',
                        'items.*.order' => 'required|integer|min:0'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    foreach ($request->items as $item) {
                        \App\Models\AdminPage::where('id', $item['id'])->update(['order' => $item['order']]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Порядок страниц успешно обновлен'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления порядка: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Получение доступа к страницам для ролей
            Route::get('/access', function () {
                try {
                    $pageAccess = \App\Models\AdminPage::with('roles')->get()->mapWithKeys(function ($page) {
                        return [$page->id => $page->roles->pluck('id')->toArray()];
                    });

                    return response()->json([
                        'success' => true,
                        'data' => $pageAccess
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения доступа к страницам: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Управление доступом к конкретной странице
            Route::put('/{id}/access', function (Request $request, $id) {
                try {
                    $page = \App\Models\AdminPage::find($id);
                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    $request->validate([
                        'role_ids' => 'array',
                        'role_ids.*' => 'integer|exists:roles,id'
                    ]);

                    // Синхронизируем роли для страницы
                    $page->roles()->sync($request->input('role_ids', []));

                    return response()->json([
                        'success' => true,
                        'message' => 'Доступ к странице обновлен',
                        'data' => [
                            'page_id' => $page->id,
                            'role_ids' => $request->input('role_ids', [])
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления доступа: ' . $e->getMessage()
                    ], 500);
                }
            });
        });
    });

    // Users management (только для админов)
        Route::middleware('role:admin')->prefix('users')->group(function () {
            // Получить список всех пользователей
            Route::get('/', function () {
                try {
                    // Получаем всех пользователей с ролями
                    $users = \App\Models\User::with('roles')
                        ->orderBy('created_at', 'desc')
                        ->get();
                    
                    // Получаем все бонусы одним запросом для оптимизации
                    $userIds = $users->pluck('id')->toArray();
                    $bonuses = \App\Models\UserBonus::whereIn('user_id', $userIds)
                        ->pluck('points', 'user_id')
                        ->toArray();
                    
                    // Получаем количество персональных промокодов для каждого пользователя
                    $promocodesCount = \App\Models\Promocode::whereIn('user_id', $userIds)
                        ->whereNotNull('user_id')
                        ->selectRaw('user_id, COUNT(*) as count')
                        ->groupBy('user_id')
                        ->pluck('count', 'user_id')
                        ->toArray();
                    
                    $users = $users->map(function ($user) use ($bonuses, $promocodesCount) {
                        // Получаем бонусы пользователя из предзагруженного массива
                        $bonusPoints = $bonuses[$user->id] ?? 0;
                        $hasPromocodes = isset($promocodesCount[$user->id]) && $promocodesCount[$user->id] > 0;
                        
                        $userData = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                            'google_id' => $user->google_id,
                            'yandex_id' => $user->yandex_id,
                            'vk_id' => $user->vk_id,
                            'phone_verified_at' => $user->phone_verified_at ? 1 : 0, // 1 если есть дата, 0 если null
                            'roles' => $user->roles->pluck('name'),
                            'email_verified_at' => $user->email_verified_at ? 1 : 0, // 1 если есть дата, 0 если null
                            'is_active' => $user->is_active,
                            'created_at' => $user->created_at,
                            'updated_at' => $user->updated_at,
                            'last_login_at' => $user->last_login_at,
                            'bonus_points' => $bonusPoints,
                            'bonuses' => $bonusPoints, // Дублируем для совместимости
                            'has_promocodes' => $hasPromocodes, // Флаг наличия персональных промокодов
                        ];

                        // Добавляем поля авторизации
                        $userData['google_id'] = $user->google_id;
                        $userData['yandex_id'] = $user->yandex_id;
                        $userData['vk_id'] = $user->vk_id;

                        return $userData;
                    });

                    return response()->json([
                        'success' => true,
                        'data' => $users
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения списка пользователей: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Создать нового пользователя
            Route::post('/', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email',
                        'password' => 'required|string|min:8',
                        'password_confirmation' => 'required|string|same:password',
                        'role' => 'string|exists:roles,name', // Принимаем одну роль
                        'roles' => 'array', // Также поддерживаем массив ролей для совместимости
                        'roles.*' => 'string|exists:roles,name',
                        'email_verified_at' => 'nullable|date', // Статус активности на основе email_verified_at
                        'is_active' => 'boolean', // Статус блокировки пользователя
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Создаем пользователя
                    $userData = [
                        'name' => $request->name,
                        'email' => $request->email,
                        'password' => \Illuminate\Support\Facades\Hash::make($request->password),
                    ];

                    // Добавляем статус активности на основе email_verified_at, если передан
                    if ($request->has('email_verified_at')) {
                        $userData['email_verified_at'] = $request->email_verified_at;
                    }

                    // Добавляем статус блокировки, если передан
                    if ($request->has('is_active')) {
                        $userData['is_active'] = $request->boolean('is_active');
                    }

                    $user = \App\Models\User::create($userData);

                    // Привязываем роли
                    if ($request->has('role') && !empty($request->role)) {
                        // Если передана одна роль
                        $user->roles()->attach(
                            \App\Models\Role::where('name', $request->role)->first()->id,
                            ['is_active' => true, 'assigned_at' => now()]
                        );
                    } elseif ($request->has('roles') && !empty($request->roles)) {
                        // Если передан массив ролей
                        $roleIds = \App\Models\Role::whereIn('name', $request->roles)->pluck('id');
                        foreach ($roleIds as $roleId) {
                            $user->roles()->attach($roleId, ['is_active' => true, 'assigned_at' => now()]);
                        }
                    } else {
                        // По умолчанию привязываем роль 'user'
                        $user->roles()->attach(
                            \App\Models\Role::where('name', 'user')->first()->id,
                            ['is_active' => true, 'assigned_at' => now()]
                        );
                    }

                    // Загружаем созданного пользователя с ролями
                    $user->load('roles');

                    return response()->json([
                        'success' => true,
                        'message' => 'Пользователь успешно создан',
                        'data' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'roles' => $user->roles->pluck('name'),
                            'email_verified_at' => $user->email_verified_at,
                            'is_active' => $user->is_active,
                            'created_at' => $user->created_at,
                            'updated_at' => $user->updated_at,
                        ]
                    ], 201);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка создания пользователя: ' . $e->getMessage()
                    ], 500);
                }
            });



            // Обновить пользователя
            Route::put('/{id}', function (Request $request, $id) {
                try {
                    $user = \App\Models\User::find($id);

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пользователь не найден'
                        ], 404);
                    }

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email,' . $id,
                        'role' => 'string|exists:roles,name', // Принимаем одну роль
                        'roles' => 'array', // Также поддерживаем массив ролей для совместимости
                        'roles.*' => 'string|exists:roles,name',
                        'email_verified_at' => 'nullable|date', // Статус активности на основе email_verified_at
                        'is_active' => 'boolean', // Статус блокировки пользователя
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // ВАЖНО: Сохраняем текущее значение email_verified_at ДО обновления
                    // чтобы Laravel не обнулил его при изменении email
                    $originalEmailVerifiedAt = $user->email_verified_at;

                    // Обновляем пользователя
                    $updateData = [
                        'name' => $request->name,
                        'email' => $request->email,
                    ];

                    // ВАЖНО: email_verified_at НЕ трогаем вообще, если оно не передано явно
                    // Это поле управляется только через отдельный endpoint toggle-email-verification
                    if ($request->has('email_verified_at')) {
                        $updateData['email_verified_at'] = $request->email_verified_at;
                    } else {
                        // Если не передано - явно сохраняем текущее значение, чтобы Laravel не обнулил его
                        $updateData['email_verified_at'] = $originalEmailVerifiedAt;
                    }

                    // Добавляем статус блокировки, если передан
                    if ($request->has('is_active')) {
                        $updateData['is_active'] = $request->boolean('is_active');
                    }

                    $user->update($updateData);

                    // Обновляем роли
                    if ($request->has('role') && !empty($request->role)) {
                        // Если передана одна роль
                        $user->roles()->sync([
                            \App\Models\Role::where('name', $request->role)->first()->id
                        ]);
                    } elseif ($request->has('roles') && !empty($request->roles)) {
                        // Если передан массив ролей
                        $user->roles()->sync($request->roles);
                    }

                    // Загружаем обновленные данные с ролями
                    $user->load('roles');

                    return response()->json([
                        'success' => true,
                        'message' => 'Пользователь успешно обновлен',
                        'data' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'roles' => $user->roles->pluck('name'),
                            'email_verified_at' => $user->email_verified_at,
                            'is_active' => $user->is_active,
                            'created_at' => $user->created_at,
                            'updated_at' => $user->updated_at,
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления пользователя: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Быстрое изменение статуса активности пользователя (email_verified_at)
            Route::put('/{id}/toggle-email-verification', function (Request $request, $id) {
                try {
                    $user = \App\Models\User::find($id);

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пользователь не найден'
                        ], 404);
                    }

                    // Переключаем статус подтверждения email
                    if ($user->email_verified_at) {
                        // Если email подтвержден - очищаем поле (деактивируем)
                        $user->email_verified_at = null;
                    } else {
                        // Если email не подтвержден - устанавливаем timestamp (активируем)
                        $user->email_verified_at = now();
                    }
                    $user->save();

                    return response()->json([
                        'success' => true,
                        'message' => 'Статус подтверждения email изменен',
                        'data' => [
                            'id' => $user->id,
                            'email_verified_at' => $user->email_verified_at
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка изменения статуса: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Быстрое изменение статуса блокировки пользователя (is_active)
            Route::put('/{id}/toggle-block', function (Request $request, $id) {
                try {
                    $user = \App\Models\User::find($id);

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пользователь не найден'
                        ], 404);
                    }

                    // Переключаем статус блокировки
                    $user->is_active = !$user->is_active;
                    $user->save();

                    return response()->json([
                        'success' => true,
                        'message' => 'Статус блокировки пользователя изменен',
                        'data' => [
                            'id' => $user->id,
                            'is_active' => $user->is_active
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка изменения статуса блокировки: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Удалить пользователя
            Route::delete('/{id}', function (Request $request, $id) {
                try {
                    $user = \App\Models\User::find($id);

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пользователь не найден'
                        ], 404);
                    }

                    // Нельзя удалить самого себя
                    if ($user->id === $request->user()->id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Нельзя удалить самого себя'
                        ], 422);
                    }

                    // Удаляем пользователя
                    $user->delete();

                    return response()->json([
                        'success' => true,
                        'message' => 'Пользователь успешно удален'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка удаления пользователя: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Управление бонусами пользователя
            Route::get('/{id}/bonuses', [\App\Http\Controllers\Admin\UserBonusController::class, 'show']);
            Route::put('/{id}/bonuses', [\App\Http\Controllers\Admin\UserBonusController::class, 'update']);
            Route::get('/{id}/bonuses/transactions', [\App\Http\Controllers\Admin\UserBonusController::class, 'transactions']);
        });

        // Menu management
        Route::prefix('menu')->group(function () {
            // Получить пункты меню для конкретного раздела (доступно менеджерам и админам)
            Route::get('/by-page/{pageId}', function ($pageId) {
                try {
                    $user = request()->user();
                    $page = \App\Models\AdminPage::find($pageId);

                    if (!$page) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Страница не найдена'
                        ], 404);
                    }

                    // Проверяем, есть ли у пользователя доступ к этой странице
                    $hasAccess = false;
                    $userRoles = $user->roles->pluck('name')->toArray();

                    if ($user->hasRole('admin')) {
                        $hasAccess = true; // Админ имеет доступ ко всем страницам
                    } else {
                        // Dashboard доступен всем авторизованным пользователям
                        if ($page->slug === 'dashboard') {
                            $hasAccess = true;
                        } else {
                            // Проверяем, есть ли у роли пользователя доступ к этой странице
                            $hasAccess = $page->roles()->whereIn('role_id', $user->roles->pluck('id'))->exists();
                        }
                    }



                    if (!$hasAccess) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Доступ запрещен'
                        ], 403);
                    }

                    // Получаем пункты меню для конкретного раздела
                    $menuItems = \App\Models\AdminMenuItem::where('page_id', $pageId)
                        ->where('is_active', true)
                        ->where('in_menu', true)
                        ->orderBy('order')
                        ->get();

                    return response()->json([
                        'success' => true,
                        'data' => $menuItems
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения меню: ' . $e->getMessage()
                    ], 500);
                }
            });
        });

        // Управление способами доставки (доступно менеджерам и админам)
        Route::middleware(['auth:sanctum', 'shop.access'])->prefix('site')->group(function () {
            Route::prefix('delivery-methods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'destroy']);
                Route::post('/reorder', [\App\Http\Controllers\Api\Admin\ShopDeliveryController::class, 'reorder']);

                // Изображения способов доставки
                Route::post('/upload-image', [\App\Http\Controllers\Api\Admin\DeliveryMethodImageController::class, 'upload']);
                Route::post('/remove-image', [\App\Http\Controllers\Api\Admin\DeliveryMethodImageController::class, 'remove']);
            });

            // Управление способами оплаты (доступно менеджерам и админам)
            Route::prefix('payment-methods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'destroy']);
                Route::post('/reorder', [\App\Http\Controllers\Api\Admin\ShopPaymentController::class, 'reorder']);

                // Изображения способов оплаты
                Route::post('/upload-image', [\App\Http\Controllers\Api\Admin\PaymentMethodImageController::class, 'upload']);
                Route::post('/remove-image', [\App\Http\Controllers\Api\Admin\PaymentMethodImageController::class, 'remove']);
            });
        });

        // Site management (только для админов)
        Route::middleware(['auth:sanctum', 'role:admin'])->prefix('site')->group(function () {
            // Шаблоны сайта
            Route::prefix('templates')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'store']);
                Route::get('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'show']);
                Route::put('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'update']);
                Route::delete('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'destroy']);
                Route::put('/{siteTemplate}/activate', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'activate']);
            });
        });

        // Слайдеры (доступны админам и пользователям с ролью site)
        Route::middleware(['auth:sanctum', 'role:admin,site'])->prefix('site')->group(function () {
            Route::prefix('sliders')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\SliderController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\SliderController::class, 'store']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\SliderController::class, 'show']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\SliderController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\SliderController::class, 'destroy']);
                Route::post('/{sliderId}/images', [\App\Http\Controllers\Api\Admin\SliderController::class, 'uploadImage']);
                Route::put('/{sliderId}/images/{imageId}', [\App\Http\Controllers\Api\Admin\SliderController::class, 'updateImage']);
                Route::delete('/{sliderId}/images/{imageId}', [\App\Http\Controllers\Api\Admin\SliderController::class, 'deleteImage']);
            });
        });

        // Site templates management (только для админов)
        Route::middleware('auth:sanctum')->prefix('site')->group(function () {
            // Тестовый endpoint для проверки меню
            Route::get('/test-menus', function () {
                try {
                    $count = \App\Models\SiteMenu::count();
                    return response()->json([
                        'success' => true,
                        'message' => 'Модель SiteMenu работает',
                        'count' => $count
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Тестовый endpoint для проверки пунктов меню
            Route::get('/test-menu-items', function () {
                try {
                    $count = \App\Models\SiteMenuItem::count();
                    return response()->json([
                        'success' => true,
                        'message' => 'Модель SiteMenuItem работает',
                        'count' => $count
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка: ' . $e->getMessage()
                    ], 500);
                }
            });
            // Шаблоны сайта
            Route::prefix('templates')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'store']);
                Route::get('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'show']);
                Route::put('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'update']);
                Route::delete('/{siteTemplate}', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'destroy']);
                Route::put('/{siteTemplate}/activate', [\App\Http\Controllers\Admin\SiteTemplateController::class, 'activate']);

            });

            // Шаблоны магазина
            Route::prefix('shop-templates')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'store']);
                Route::get('/{shopTemplate}', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'show']);
                Route::put('/{shopTemplate}', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'update']);
                Route::delete('/{shopTemplate}', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'destroy']);
                Route::put('/{shopTemplate}/activate', [\App\Http\Controllers\Admin\ShopTemplateController::class, 'activate']);
            });

            // Меню сайта
            Route::prefix('menus')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\SiteMenuController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\SiteMenuController::class, 'store']);
                Route::get('/{siteMenu}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'show']);
                Route::put('/{siteMenu}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'update']);
                Route::delete('/{siteMenu}', [\App\Http\Controllers\Admin\SiteMenuController::class, 'destroy']);
            });



            // Пункты меню сайта
            Route::prefix('menu-items')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'store']);
                Route::get('/{siteMenuItem}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'show']);
                Route::put('/{siteMenuItem}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'update']);
                Route::delete('/{siteMenuItem}', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'destroy']);
                Route::post('/order', [\App\Http\Controllers\Admin\SiteMenuItemController::class, 'updateOrder']);
            });


            // Управление уведомлениями Telegram
            Route::prefix('telegram-notifications')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'show']);
                Route::post('/send-test', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'sendTest']);
                Route::post('/{id}/retry', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'retry']);
                Route::post('/process-pending', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'processPending']);
                Route::get('/stats/overview', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'stats']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\TelegramNotificationController::class, 'destroy']);
            });

            // Управление настройками бонусов
            Route::prefix('bonus-settings')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'index']);
                Route::get('/active', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'getActive']);
                Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'destroy']);
                Route::post('/{id}/toggle-active', [\App\Http\Controllers\Api\Admin\ShopBonusSettingsController::class, 'toggleActive']);

                // Изображения бонусных систем
                Route::post('/upload-image', [\App\Http\Controllers\Api\Admin\BonusSettingImageController::class, 'upload']);
                Route::post('/remove-image', [\App\Http\Controllers\Api\Admin\BonusSettingImageController::class, 'remove']);
            });
        });

        // Получить все пункты меню (доступно менеджерам и админам, с фильтрацией по правам доступа)
        Route::get('/menu', function (Request $request) {
            try {
                $user = $request->user();
                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован'
                    ], 401);
                }

                // Получаем все пункты меню
                $allMenuItems = \App\Models\AdminMenuItem::with('page')
                    ->orderBy('order')
                    ->get();

                // Если пользователь - админ, возвращаем все пункты меню
                if ($user->hasRole('admin')) {
                    $menuItems = $allMenuItems->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'page_id' => $item->page_id,
                            'parent_id' => $item->parent_id,
                            'icon' => $item->icon,
                            'label' => $item->label,
                            'description' => $item->description,
                            'href' => $item->href,
                            'order' => $item->order,
                            'is_active' => $item->is_active,
                            'in_menu' => $item->in_menu ?? true,
                            'page_name' => $item->page->name ?? null,
                            'page_slug' => $item->page->slug ?? null,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    });
                } else {
                    // Для не-админов фильтруем пункты меню по правам доступа
                    $userRoleIds = $user->roles->pluck('id')->toArray();
                    
                    $menuItems = $allMenuItems->filter(function ($item) use ($user, $userRoleIds) {
                        // Если у пункта меню нет страницы, пропускаем
                        if (!$item->page) {
                            return false;
                        }
                        
                        // Dashboard доступен всем авторизованным пользователям
                        if ($item->page->slug === 'dashboard') {
                            return true;
                        }
                        
                        // Проверяем, есть ли у роли пользователя доступ к странице
                        return $item->page->roles()->whereIn('role_id', $userRoleIds)->exists();
                    })->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'page_id' => $item->page_id,
                            'parent_id' => $item->parent_id,
                            'icon' => $item->icon,
                            'label' => $item->label,
                            'description' => $item->description,
                            'href' => $item->href,
                            'order' => $item->order,
                            'is_active' => $item->is_active,
                            'in_menu' => $item->in_menu ?? true,
                            'page_name' => $item->page->name ?? null,
                            'page_slug' => $item->page->slug ?? null,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    })->values();
                }

                return response()->json([
                    'success' => true,
                    'data' => $menuItems
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения списка меню: ' . $e->getMessage()
                ], 500);
            }
        });

        // Menu management (только для админов - создание/редактирование/удаление)
        Route::middleware('role:admin')->prefix('menu')->group(function () {



            // Создать новый пункт меню
            Route::post('/', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'page_id' => 'required|exists:admin_pages,id',
                        'parent_id' => 'nullable|exists:admin_menu_items,id',
                        'icon' => 'nullable|string|max:255',
                        'label' => 'required|string|max:255',
                        'description' => 'nullable|string|max:1000',
                        'href' => 'nullable|string|max:255',
                        'order' => 'nullable|integer|min:0',
                        'is_active' => 'boolean',
                        'in_menu' => 'boolean'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $menuItem = \App\Models\AdminMenuItem::create([
                        'page_id' => $request->page_id,
                        'parent_id' => $request->parent_id,
                        'icon' => $request->icon,
                        'label' => $request->label,
                        'description' => $request->description,
                        'href' => $request->href ?? null,
                        'order' => $request->order ?? 0,
                        'is_active' => $request->is_active ?? true,
                        'in_menu' => $request->has('in_menu') ? (bool) $request->in_menu : true,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Пункт меню успешно создан',
                        'data' => $menuItem
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка создания пункта меню: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Обновить пункт меню
            Route::put('/{id}', function (Request $request, $id) {
                try {
                    $menuItem = \App\Models\AdminMenuItem::find($id);

                    if (!$menuItem) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пункт меню не найден'
                        ], 404);
                    }

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'page_id' => 'required|exists:admin_pages,id',
                        'parent_id' => 'nullable|exists:admin_menu_items,id',
                        'icon' => 'nullable|string|max:255',
                        'label' => 'required|string|max:255',
                        'description' => 'nullable|string|max:1000',
                        'href' => 'nullable|string|max:255',
                        'order' => 'nullable|integer|min:0',
                        'is_active' => 'boolean',
                        'in_menu' => 'boolean'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $menuItem->update([
                        'page_id' => $request->page_id,
                        'parent_id' => $request->parent_id,
                        'icon' => $request->icon,
                        'label' => $request->label,
                        'description' => $request->description,
                        'href' => $request->href ?? null,
                        'order' => $request->order ?? $menuItem->order,
                        'is_active' => $request->is_active ?? $menuItem->is_active,
                        'in_menu' => $request->has('in_menu') ? (bool) $request->in_menu : $menuItem->in_menu,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Пункт меню успешно обновлен',
                        'data' => $menuItem
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления пункта меню: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Удалить пункт меню
            Route::delete('/{id}', function ($id) {
                try {
                    $menuItem = \App\Models\AdminMenuItem::find($id);

                    if (!$menuItem) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пункт меню не найден'
                        ], 404);
                    }

                    $menuItem->delete();

                    return response()->json([
                        'success' => true,
                        'message' => 'Пункт меню успешно удален'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка удаления пункта меню: ' . $e->getMessage()
                    ], 500);
                }
            });

            // Изменить порядок пунктов меню
            Route::post('/order', function (Request $request) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'items' => 'required|array',
                        'items.*.id' => 'required|exists:admin_menu_items,id',
                        'items.*.order' => 'required|integer|min:0'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    foreach ($request->items as $item) {
                        \App\Models\AdminMenuItem::where('id', $item['id'])->update(['order' => $item['order']]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Порядок пунктов меню успешно обновлен'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления порядка: ' . $e->getMessage()
                    ], 500);
                }
            });
        });

        // User preferences
        Route::prefix('user')->group(function () {
            Route::get('/preferences', [\App\Http\Controllers\Admin\UserPreferencesController::class, 'getPreferences']);
            Route::put('/preferences', [\App\Http\Controllers\Admin\UserPreferencesController::class, 'updatePreferences']);
        });

        // Profile management
        Route::prefix('profile')->group(function () {
            Route::get('/', function (Request $request) {
                try {
                    $user = $request->user();
                    
                    // Загружаем роли пользователя с безопасной обработкой
                    $user->load('roles');
                    $roles = $user->roles ?? collect();
                    
                    $userData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'phone_verified_at' => $user->phone_verified_at, // Для определения телефонных пользователей
                        'avatar_url' => $user->avatar_url, // URL аватара от OAuth провайдеров
                        'google_id' => $user->google_id, // Для определения соц-аккаунта
                        'yandex_id' => $user->yandex_id, // Для определения соц-аккаунта
                        'vk_id' => $user->vk_id, // Для определения соц-аккаунта
                        'role' => $roles->first()?->name ?? 'user', // Основная роль для совместимости
                        'roles' => $roles->map(function($role) {
                            return [
                                'id' => $role->id,
                                'name' => $role->name,
                                'display_name' => $role->display_name
                            ];
                        }),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at
                    ];

                    return response()->json([
                        'success' => true,
                        'data' => $userData,
                        'message' => 'Profile retrieved successfully'
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Ошибка получения профиля: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                        'user_id' => $request->user()?->id
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения профиля: ' . $e->getMessage()
                    ], 500);
                }
            });

            Route::put('/', function (Request $request) {
                try {
                    $user = $request->user();

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email,' . $user->id,
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Обновляем данные пользователя
                    $user->update([
                        'name' => $request->name,
                        'email' => $request->email,
                    ]);

                    // Загружаем роли пользователя с безопасной обработкой
                    $user->load('roles');
                    $roles = $user->roles ?? collect();

                    // Получаем обновленные данные
                    $userData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $roles->first()?->name ?? 'user', // Основная роль для совместимости
                        'roles' => $roles->map(function($role) {
                            return [
                                'id' => $role->id,
                                'name' => $role->name,
                                'display_name' => $role->display_name
                            ];
                        }),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at
                    ];

                    return response()->json([
                        'success' => true,
                        'message' => 'Профиль успешно обновлен',
                        'data' => $userData
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Ошибка обновления профиля: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                        'user_id' => $request->user()?->id
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка обновления профиля: ' . $e->getMessage()
                    ], 500);
                }
            });

            Route::post('/check-name', function (Request $request) {
                try {
                    $user = $request->user();

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'name' => 'required|string|max:255'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $name = $request->name;

                    // Проверяем, есть ли пользователь с таким именем (исключая текущего)
                    $existingUser = \App\Models\User::where('name', $name)
                        ->where('id', '!=', $user->id)
                        ->first();

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'is_available' => !$existingUser,
                            'name' => $name
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка проверки имени',
                        'error_details' => [$e->getMessage()],
                        'error_code' => 'CHECK_NAME_ERROR'
                    ], 500);
                }
            });

            Route::post('/avatar', function (Request $request) {
                try {
                    $user = $request->user();

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB максимум
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    $file = $request->file('avatar');

                    // Путь для сохранения на фронтенде
                    $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
                    $dir = $frontendPublicPath . '/images/users';

                    // Создаем директорию, если её нет
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    // Удаляем старый аватар пользователя, если он есть
                    // Используем стандартное имя файла user_{id}.jpg
                    $oldAvatarPath = $dir . '/user_' . $user->id . '.jpg';
                    if (file_exists($oldAvatarPath)) {
                        unlink($oldAvatarPath);
                    }

                    // Сохраняем файл с именем user_{id}.jpg
                    $filename = 'user_' . $user->id . '.jpg';
                    $fullPath = $dir . '/' . $filename;
                    
                    // Получаем информацию об изображении
                    $imageInfo = getimagesize($file->getRealPath());
                    if (!$imageInfo) {
                        throw new \Exception('Не удалось получить информацию об изображении');
                    }
                    
                    $imageType = $imageInfo[2];
                    
                    // Загружаем изображение в зависимости от типа
                    $sourceImage = null;
                    switch ($imageType) {
                        case IMAGETYPE_JPEG:
                            $sourceImage = imagecreatefromjpeg($file->getRealPath());
                            break;
                        case IMAGETYPE_PNG:
                            $sourceImage = imagecreatefrompng($file->getRealPath());
                            break;
                        case IMAGETYPE_GIF:
                            $sourceImage = imagecreatefromgif($file->getRealPath());
                            break;
                        case IMAGETYPE_WEBP:
                            $sourceImage = imagecreatefromwebp($file->getRealPath());
                            break;
                        default:
                            throw new \Exception('Неподдерживаемый тип изображения');
                    }
                    
                    if (!$sourceImage) {
                        throw new \Exception('Не удалось загрузить изображение');
                    }
                    
                    // Сохраняем как JPEG с качеством 90
                    $result = imagejpeg($sourceImage, $fullPath, 90);
                    
                    // Освобождаем память
                    imagedestroy($sourceImage);
                    
                    if (!$result) {
                        throw new \Exception('Не удалось сохранить изображение');
                    }

                    // Аватар сохраняется только в папке images/users/user_{id}.jpg
                    // Поля avatar и avatar_url больше не используются в базе данных

                    return response()->json([
                        'success' => true,
                        'message' => 'Аватар успешно загружен',
                        'data' => [
                            'avatar' => '/images/users/' . $filename
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка загрузки аватара: ' . $e->getMessage()
                    ], 500);
                }
            });

            Route::delete('/avatar', function (Request $request) {
                try {
                    $user = $request->user();

                    // Удаляем файл аватара с фронтенда
                    // Используем стандартное имя файла user_{id}.jpg
                    $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
                    $filePath = $frontendPublicPath . '/images/users/user_' . $user->id . '.jpg';
                    
                    $fileDeleted = false;
                    if (file_exists($filePath)) {
                        $fileDeleted = unlink($filePath);
                    }

                    // Поля avatar и avatar_url больше не используются в базе данных

                    return response()->json([
                        'success' => true,
                        'message' => 'Аватар успешно удален',
                        'data' => [
                            'avatar' => null
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка удаления аватара: ' . $e->getMessage()
                    ], 500);
                }
            });

            Route::post('/change-password', function (Request $request) {
                try {
                    $user = $request->user();

                    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                        'current_password' => 'required|string',
                        'new_password' => 'required|string|min:8',
                        'new_password_confirmation' => 'required|string|same:new_password'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Проверяем текущий пароль
                    if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Текущий пароль неверен'
                        ], 422);
                    }

                    // Обновляем пароль
                    $user->update([
                        'password' => \Illuminate\Support\Facades\Hash::make($request->new_password)
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Пароль успешно изменен'
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка изменения пароля: ' . $e->getMessage()
                    ], 500);
                }
            });
        });

        // Шаблоны импорта товаров
        Route::prefix('import-templates')->group(function () {
            Route::get('/list', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'list']); // Легкий список для выпадающих меню
            Route::get('/', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'index']); // Полный список с настройками
            Route::post('/cleanup', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'cleanup']); // Очистка старых шаблонов
            Route::get('/{id}', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'destroy']);
            Route::post('/{id}/duplicate', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'duplicate']);
            Route::put('/{id}/set-default', [\App\Http\Controllers\Admin\ImportTemplateController::class, 'setDefault']);
        });

        // Автопарсинг Google Sheets
        Route::prefix('google-sheets-auto-parsing')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ShopGoogleSheetsAutoParsingController::class, 'index']);
            Route::get('/{id}', [\App\Http\Controllers\Admin\ShopGoogleSheetsAutoParsingController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Admin\ShopGoogleSheetsAutoParsingController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Admin\ShopGoogleSheetsAutoParsingController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopGoogleSheetsAutoParsingController::class, 'destroy']);
        });

        // Автопарсинг YML фидов
        Route::prefix('yml-feed-auto-parsing')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ShopYMLFeedAutoParsingController::class, 'index']);
            Route::get('/{id}', [\App\Http\Controllers\Admin\ShopYMLFeedAutoParsingController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Admin\ShopYMLFeedAutoParsingController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Admin\ShopYMLFeedAutoParsingController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopYMLFeedAutoParsingController::class, 'destroy']);
        });

        // Логи импорта товаров
        Route::prefix('import-logs')->group(function () {
            Route::get('/stats', [\App\Http\Controllers\Admin\ImportLogController::class, 'getLogStats']);
            Route::get('/{type}', [\App\Http\Controllers\Admin\ImportLogController::class, 'getLog']);
            Route::delete('/{type}', [\App\Http\Controllers\Admin\ImportLogController::class, 'clearLog']);
            Route::post('/load', [\App\Http\Controllers\Admin\ImportLogController::class, 'logLoad']);
            Route::post('/update', [\App\Http\Controllers\Admin\ImportLogController::class, 'logUpdate']);
            Route::post('/skip', [\App\Http\Controllers\Admin\ImportLogController::class, 'logSkip']);
            Route::post('/error', [\App\Http\Controllers\Admin\ImportLogController::class, 'logError']);

            // Пакетные запросы (поддержка обоих форматов: /batch и -batch)
            Route::post('/load/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logLoadBatch']);
            Route::post('/load-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logLoadBatch']);
            Route::post('/update/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logUpdateBatch']);
            Route::post('/update-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logUpdateBatch']);
            Route::post('/skip/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logSkipBatch']);
            Route::post('/skip-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logSkipBatch']);
            Route::post('/error/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logErrorBatch']);
            Route::post('/error-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logErrorBatch']);
            
            // Пакетное логирование изображений
            Route::post('/image-success/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logImageSuccessBatch']);
            Route::post('/image-success-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logImageSuccessBatch']);
            Route::post('/image-error/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logImageErrorBatch']);
            Route::post('/image-error-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logImageErrorBatch']);
            
            // Пакетное логирование вариаций
            Route::post('/variation/batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logVariationBatch']);
            Route::post('/variation-batch', [\App\Http\Controllers\Admin\ImportLogController::class, 'logVariationBatch']);
        });

        // Тестовый endpoint для проверки шаблонов импорта
        Route::get('/test-import-templates', function (Request $request) {
            try {
                $user = $request->user();
                return response()->json([
                    'success' => true,
                    'message' => 'Тест шаблонов импорта',
                    'user_id' => $user ? $user->id : null,
                    'user_roles' => $user ? $user->roles->pluck('name')->toArray() : [],
                    'templates_count' => \App\Models\ImportTemplate::count(),
                    'templates' => \App\Models\ImportTemplate::all()
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка: ' . $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ], 500);
            }
        });
    });


});

// Тестовый маршрут
Route::get('/test', function () {
    return response()->json([
        'message' => 'API работает!',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString()
    ]);
});





// Тестовый маршрут для проверки CORS
Route::options('/test-cors', function () {
    return response()->json([], 200);
});
Route::get('/test-cors', function () {
    return response()->json([
        'message' => 'CORS тест работает!',
        'origin' => request()->header('Origin'),
        'timestamp' => now()->toISOString()
    ]);
});

// Тестовый маршрут для проверки POST запросов
Route::options('/test-post', function () {
    return response()->json([], 200);
});
Route::post('/test-post', function () {
    return response()->json([
        'message' => 'POST запрос работает!',
        'origin' => request()->header('Origin'),
        'method' => request()->method(),
        'data' => request()->all()
    ]);
});

// Диагностический маршрут для проверки API
Route::get('/debug/api', function () {
    return response()->json([
        'success' => true,
        'message' => 'API маршруты работают',
        'server' => [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'url' => request()->url(),
            'method' => request()->method(),
            'headers' => request()->headers->all(),
        ],
        'routes' => [
            'api_prefix' => 'api',
            'public_site_info' => 'api/public/site-info',
            'test_route' => 'api/test'
        ]
    ]);
});

// Тестовый маршрут для проверки меню без авторизации
Route::get('/test-menus-no-auth', function () {
    try {
        $count = \App\Models\SiteMenu::count();
        return response()->json([
            'success' => true,
            'message' => 'Модель SiteMenu работает без авторизации',
            'count' => $count
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ], 500);
    }
});

// Тестовый маршрут для проверки авторизации
Route::middleware('auth:sanctum')->get('/test-auth', function (Request $request) {
    try {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'message' => 'Авторизация работает',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_roles' => $user->roles->pluck('name')->toArray()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Ошибка авторизации: ' . $e->getMessage()
        ], 500);
    }
});


// Тестовый маршрут для проверки роли админа
Route::middleware(['auth:sanctum', 'role:admin'])->get('/test-admin-role', function (Request $request) {
    try {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'message' => 'Роль админа работает',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_roles' => $user->roles->pluck('name')->toArray()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Ошибка проверки роли: ' . $e->getMessage()
        ], 500);
    }
});

// Тестовый маршрут
Route::get('/test', [\App\Http\Controllers\Api\Admin\TestController::class, 'test']);

// СДЭК API (публичные маршруты)
Route::prefix('cdek')->group(function () {
    Route::get('/cities', [\App\Http\Controllers\Api\Public\CdekController::class, 'getCities']);
    Route::get('/pickup-points', [\App\Http\Controllers\Api\Public\CdekController::class, 'getPickupPoints']);
    Route::post('/calculate', [\App\Http\Controllers\Api\Public\CdekController::class, 'calculateDelivery']);
    Route::post('/min-cost', [\App\Http\Controllers\Api\Public\CdekController::class, 'getMinDeliveryCost']);
});

// СДЭК API для создания заказов (авторизация опциональна - для незарегистрированных пользователей)
Route::prefix('sdek')->group(function () {
    Route::post('/create-order', [\App\Http\Controllers\SdekOrderController::class, 'createOrder']);
    Route::get('/order-status/{orderUuid}', [\App\Http\Controllers\SdekOrderController::class, 'getOrderStatus']);
    Route::post('/cancel-order/{orderUuid}', [\App\Http\Controllers\SdekOrderController::class, 'cancelOrder']);
    Route::post('/insurance-info', [\App\Http\Controllers\SdekOrderController::class, 'getInsuranceInfo']);
});

// Пользовательские маршруты (требуют авторизации)
Route::middleware('auth:sanctum')->group(function () {
    // Маршруты профиля пользователя
    Route::prefix('user')->group(function () {
        Route::get('/profile', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'getProfile']);
        Route::put('/profile', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'updateProfile']);
        Route::post('/change-password', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'changePassword']);
        Route::delete('/avatar', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'deleteAvatar']);
        Route::get('/statistics', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'getStatistics']);
        Route::post('/phone-change/send-code', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'sendPhoneChangeCode']);
        Route::post('/phone-change/verify-code', [\App\Http\Controllers\Api\Public\UserProfileController::class, 'verifyPhoneChangeCode']);
        // Маршруты для смены email
        Route::post('/email-change/send-code', [\App\Http\Controllers\Api\Public\EmailChangeController::class, 'sendVerificationCode']);
        Route::post('/email-change/verify-code', [\App\Http\Controllers\Api\Public\EmailChangeController::class, 'verifyCode']);
    });

    // Маршруты загрузки аватаров
    Route::prefix('upload')->group(function () {
        Route::post('/avatar', [\App\Http\Controllers\Api\Public\AvatarUploadController::class, 'uploadAvatar']);
        Route::delete('/avatar', [\App\Http\Controllers\Api\Public\AvatarUploadController::class, 'deleteAvatar']);
        Route::get('/file-info', [\App\Http\Controllers\Api\Public\AvatarUploadController::class, 'getFileInfo']);
    });
});
