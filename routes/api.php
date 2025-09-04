<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Auth\AuthController;


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

// Публичные маршруты для авторизации (без Sanctum stateful middleware)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationEmail']);
Route::post('/check-email', [AuthController::class, 'checkEmail']);

// Phone authentication routes
Route::post('/phone/send-code', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'sendPhoneCode']);
Route::post('/phone/verify-code', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'verifyPhoneCode']);
Route::post('/phone/check-status', [\App\Http\Controllers\Auth\PhoneAuthController::class, 'checkCodeStatus']);

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

// Публичные маршруты для получения информации о сайте (с CORS middleware)
Route::middleware(['cors'])->group(function () {
    Route::options('/public/site-info', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site-info', [App\Http\Controllers\Api\Public\SiteInfoController::class, 'index']);

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
    Route::get('/public/site/template/active', [App\Http\Controllers\Api\Public\SiteTemplateController::class, 'getActive']);

    Route::options('/public/site/menu', function () {
        return response()->json([], 200);
    });
    Route::get('/public/site/menu', [App\Http\Controllers\Api\Public\SiteMenuController::class, 'getMenu']);
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

// Защищенные маршруты (требуют авторизации) - используют только token authentication
Route::middleware('auth:sanctum')->group(function () {
    // Авторизация
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/auth/check', [AuthController::class, 'check']);

    // Маршруты для администраторов и менеджеров
    Route::middleware('role:admin,manager')->prefix('admin')->group(function () {
        // Profile management
        Route::get('/profile', [\App\Http\Controllers\Api\Admin\ProfileController::class, 'index']);
        
        // Settings management (только просмотр для менеджеров)
        Route::prefix('settings')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SettingController::class, 'index']);
            
            // Только админы могут изменять настройки
            Route::middleware('role:admin')->group(function () {
                Route::post('/', [\App\Http\Controllers\Admin\SettingController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\SettingController::class, 'update']);
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
            Route::post('/', [\App\Http\Controllers\CategoryController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\CategoryController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\CategoryController::class, 'destroy']);
            Route::post('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'uploadCategoryImage']);
            Route::delete('/{id}/image', [\App\Http\Controllers\ImageUploadController::class, 'deleteCategoryImage']);
            Route::post('/temp/image', [\App\Http\Controllers\ImageUploadController::class, 'uploadTempImage']);
            Route::post('/upload-image', [\App\Http\Controllers\CategoryController::class, 'uploadImage']);
            Route::post('/sort-alphabetically', [\App\Http\Controllers\CategoryController::class, 'sortAlphabetically']);
            
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
        Route::middleware('shop.access')->prefix('shop')->group(function () {
            // Товары
            Route::prefix('goods')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'index']);
                Route::get('/filters', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'filters']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'destroy']);
                Route::post('/bulk-update', [\App\Http\Controllers\Admin\ShopGoodsController::class, 'bulkUpdate']);
                
                // Импорт/экспорт товаров
                Route::prefix('import-export')->group(function () {
                    Route::post('/export/csv', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'exportCsv']);
                    Route::post('/export/excel', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'exportExcel']);
                    Route::post('/import/csv', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'importCsv']);
                    Route::get('/template', [\App\Http\Controllers\Admin\ShopImportExportController::class, 'getTemplate']);
                });
                
                // Вариации товаров
                Route::prefix('{goodId}/variations')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'index']);
                    Route::get('/attributes', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'attributes']);
                    Route::get('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'show']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'store']);
                    Route::put('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'update']);
                    Route::delete('/{variationId}', [\App\Http\Controllers\Admin\ShopGoodVariationsController::class, 'destroy']);
                });
                
                // Изображения товаров
                Route::prefix('{goodId}/images')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'store']);
                    Route::put('/{imageId}', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'update']);
                    Route::delete('/{imageId}', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'destroy']);
                    Route::put('/{imageId}/main', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'setMain']);
                    Route::post('/reorder', [\App\Http\Controllers\Admin\ShopGoodImagesController::class, 'reorder']);
                });
                
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
            
            // Бренды
            Route::prefix('brands')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'index']);
                Route::get('/active', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'active']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopBrandsController::class, 'destroy']);
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
            
            // Свойства товаров
            Route::prefix('properties')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'index']);
                Route::get('/active', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'active']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopPropertiesController::class, 'destroy']);
            });
            
            // Заказы
            Route::prefix('orders')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'index']);
                Route::get('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'show']);
                Route::post('/', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'store']);
                Route::put('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'update']);
                Route::delete('/{id}', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'destroy']);
                Route::put('/{id}/status', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'updateStatus']);
                Route::post('/export', [\App\Http\Controllers\Admin\ShopOrdersController::class, 'export']);
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
            }); // Закрываем middleware для админов
        });

        // Users management (только для админов)
        Route::middleware('role:admin')->prefix('users')->group(function () {
            // Получить список всех пользователей
            Route::get('/', function () {
                try {
                    $users = \App\Models\User::with('roles')
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->map(function ($user) {
                            return [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'avatar' => $user->avatar,
                                'avatar_url' => $user->avatar_url,
                                'roles' => $user->roles->pluck('name'),
                                'is_active' => $user->is_active,
                                'created_at' => $user->created_at,
                                'updated_at' => $user->updated_at,
                            ];
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
                        'is_active' => 'boolean' // Статус активности
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
                    
                    // Добавляем статус активности, если передан
                    if ($request->has('is_active')) {
                        $userData['is_active'] = $request->boolean('is_active');
                    }
                    
                    $user = \App\Models\User::create($userData);

                    // Привязываем роли
                    if ($request->has('role') && !empty($request->role)) {
                        // Если передана одна роль
                        $user->roles()->attach(
                            \App\Models\Role::where('name', $request->role)->first()->id
                        );
                    } elseif ($request->has('roles') && !empty($request->roles)) {
                        // Если передан массив ролей
                        $user->roles()->attach(
                            \App\Models\Role::whereIn('name', $request->roles)->pluck('id')
                        );
                    } else {
                        // По умолчанию привязываем роль 'user'
                        $user->roles()->attach(
                            \App\Models\Role::where('name', 'user')->first()->id
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
                            'avatar' => $user->avatar,
                            'avatar_url' => $user->avatar_url,
                            'roles' => $user->roles->pluck('name'),
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
                        'is_active' => 'boolean' // Статус активности
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Обновляем пользователя
                    $updateData = [
                        'name' => $request->name,
                        'email' => $request->email,
                    ];
                    
                    // Добавляем статус активности, если передан
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
                            'avatar' => $user->avatar,
                            'avatar_url' => $user->avatar_url,
                            'roles' => $user->roles->pluck('name'),
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

            // Быстрое изменение статуса активности пользователя
            Route::put('/{id}/toggle-status', function (Request $request, $id) {
                try {
                    $user = \App\Models\User::find($id);

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Пользователь не найден'
                        ], 404);
                    }

                    // Переключаем статус активности
                    $user->is_active = !$user->is_active;
                    $user->save();

                    return response()->json([
                        'success' => true,
                        'message' => 'Статус активности пользователя изменен',
                        'data' => [
                            'id' => $user->id,
                            'is_active' => $user->is_active
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка изменения статуса: ' . $e->getMessage()
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
        });

        // Menu management (только для админов - создание/редактирование/удаление)
        Route::middleware('role:admin')->prefix('menu')->group(function () {
            // Получить все пункты меню (для MenuManager)
            Route::get('/', function () {
                try {
                    $menuItems = \App\Models\AdminMenuItem::with('page')
                        ->orderBy('order')
                        ->get()
                        ->map(function ($item) {
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
                                'page_name' => $item->page->name ?? null,
                                'page_slug' => $item->page->slug ?? null,
                                'created_at' => $item->created_at,
                                'updated_at' => $item->updated_at,
                            ];
                        });

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
                        'is_active' => 'boolean'
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
                        'is_active' => 'boolean'
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

        // Profile management
        Route::prefix('profile')->group(function () {
            Route::get('/', function (Request $request) {
                try {
                    $user = $request->user();
                    $userData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
                        'role' => $user->roles->first()?->name ?? 'user', // Основная роль для совместимости
                        'roles' => $user->roles->map(function($role) {
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

                    // Получаем обновленные данные
                    $userData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
                        'role' => $user->roles->first()?->name ?? 'user', // Основная роль для совместимости
                        'roles' => $user->roles->map(function($role) {
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

                    // Создаем уникальное имя файла
                    $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

                    // Путь для сохранения
                    $path = 'images/users/' . $filename;

                    // Создаем директорию, если её нет
                    if (!\Illuminate\Support\Facades\Storage::disk('public')->exists('images/users')) {
                        \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('images/users');
                    }

                    // Сохраняем файл
                    \Illuminate\Support\Facades\Storage::disk('public')->putFileAs('images/users', $file, $filename);

                    // Удаляем старый аватар, если он есть
                    if ($user->avatar && $user->avatar !== 'default-avatar.png') {
                        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar)) {
                            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
                        }
                    }

                    // Обновляем URL аватара в базе данных
                    $user->avatar = $path;
                    $user->save();

                    return response()->json([
                        'success' => true,
                        'message' => 'Аватар успешно загружен',
                        'data' => [
                            'avatar' => Storage::url($user->avatar)
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

                    // Проверяем, есть ли аватар
                    if (!$user->avatar) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Аватар не найден'
                        ], 404);
                    }

                    // Удаляем файл аватара
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
                    }

                    // Очищаем поле аватара в базе данных
                    $user->avatar = null;
                    $user->save();

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
