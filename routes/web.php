<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ShopGoodsController;

Route::get('/', function () {
    return view('welcome');
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
