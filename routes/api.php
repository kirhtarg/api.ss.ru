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

// Публичные маршруты для получения информации о сайте
Route::get('/public/site-info', [App\Http\Controllers\Api\Public\SiteInfoController::class, 'index']);
Route::get('/public/settings', [App\Http\Controllers\Api\Public\SiteInfoController::class, 'settings']);

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

    // Маршруты для администраторов
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Profile management
        Route::get('/profile', [\App\Http\Controllers\Api\Admin\ProfileController::class, 'index']);
        
        // Settings management
        Route::prefix('settings')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SettingController::class, 'index']);
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
        });

        // Users management
        Route::prefix('users')->group(function () {
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
                        'roles' => 'array',
                        'roles.*' => 'string|exists:roles,name'
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Создаем пользователя
                    $user = \App\Models\User::create([
                        'name' => $request->name,
                        'email' => $request->email,
                        'password' => \Illuminate\Support\Facades\Hash::make($request->password),
                    ]);

                    // Привязываем роли, если они переданы
                    if ($request->has('roles') && !empty($request->roles)) {
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

            // Получить статистику пользователей
            Route::get('/statistics', function () {
                try {
                    $totalUsers = \App\Models\User::count();
                    $activeUsers = \App\Models\User::where('created_at', '>=', now()->subDays(30))->count();
                    $adminUsers = \App\Models\User::whereHas('roles', function ($query) {
                        $query->where('name', 'admin');
                    })->count();

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'total_users' => $totalUsers,
                            'active_users_30_days' => $activeUsers,
                            'admin_users' => $adminUsers,
                        ]
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка получения статистики пользователей: ' . $e->getMessage()
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
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка валидации',
                            'errors' => $validator->errors()
                        ], 422);
                    }

                    // Обновляем пользователя
                    $user->update([
                        'name' => $request->name,
                        'email' => $request->email,
                    ]);

                    // Обновляем роли, если они переданы
                    if ($request->has('roles')) {
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

            // Получить пункты меню для конкретного раздела
            Route::get('/by-page/{pageId}', function ($pageId) {
                try {
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

    // Маршруты для менеджеров и администраторов
    Route::middleware('role:admin,manager')->group(function () {
        // Здесь будут маршруты для менеджеров и администраторов
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
