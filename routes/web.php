<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\ShopGoodsController;
use App\Http\Controllers\PageController;

Route::get('/', function () {
    return view('welcome');
});

// Page Builder public pages
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('page-builder.show');

// Debug routes for testing
Route::match(['GET', 'OPTIONS'], '/debug-check-slug', function(Request $request) {
    if ($request->isMethod('options')) {
        return response()->json([], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    $slug = $request->query('slug');
    \Log::info('Debug check slug called', ['slug' => $slug]);
    return response()->json([
        'success' => true,
        'exists' => false,
        'slug' => $slug,
        'debug' => 'no auth required'
    ])->header('Access-Control-Allow-Origin', '*')
     ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
     ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

// Debug route for creating pages without auth
Route::match(['POST', 'OPTIONS'], '/debug-create-page', function(Request $request) {
    if ($request->isMethod('options')) {
        return response()->json([], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    $data = $request->all();
    \Log::info('Debug create page called', ['data' => $data]);

    // Simple validation
    if (empty($data['title']) || empty($data['slug'])) {
        return response()->json([
            'success' => false,
            'message' => 'Title and slug are required',
            'data' => $data
        ], 422)->header('Access-Control-Allow-Origin', '*');
    }

    return response()->json([
        'success' => true,
        'data' => array_merge($data, ['id' => rand(1000, 9999)]),
        'message' => 'Page created (debug mode)',
        'debug' => true
    ])->header('Access-Control-Allow-Origin', '*')
     ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
     ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

// Временные файлы для импорта
Route::get('/temp-file/{filename}', function ($filename) {
    $path = storage_path('app/temp/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/xml',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ]);
})->name('temp-file');

// Отдельный маршрут для настроек СДЭК (без throttle для надежности)
Route::get('/delivery/cdek/settings', [App\Http\Controllers\DeliveryController::class, 'getCdekSettings'])->name('delivery.cdek.settings');
