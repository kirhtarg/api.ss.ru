<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ShopGoodsController;

Route::get('/', function () {
    return view('welcome');
});

// Временные файлы YML для импорта теперь в api.php
