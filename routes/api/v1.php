<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\V1\Auth\{
    LoginController,
    LogoutController
};

use App\Http\Controllers\API\V1\{
    MenuController,
    CategoryController,
    ProductController
};

Route::prefix('auth')->group(function () {
    // Route::post('register', RegisterController::class);
    Route::post('login', LoginController::class);

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::post('logout', LogoutController::class);
    });
})
->middleware(ThrottleRequests::with(10, 1));

Route::prefix('menus')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [MenuController::class, 'index']);
})
->middleware(ThrottleRequests::with(60, 1));

Route::prefix('categories')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
})
->middleware(ThrottleRequests::with(60, 1));

Route::prefix('products')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
})
->middleware(ThrottleRequests::with(60, 1));