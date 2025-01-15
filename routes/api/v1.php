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
    ProductController,
    OrderController
};

Route::prefix('auth')->group(function () {
    // Route::post('register', RegisterController::class);
    Route::post('login', LoginController::class)
        ->name('login');

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::post('logout', LogoutController::class)
            ->name('logout');
    });
})
    ->middleware(ThrottleRequests::with(10, 1));

Route::prefix('menus')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [MenuController::class, 'index'])
        ->name('menus.index');
})
    ->middleware(ThrottleRequests::with(60, 1));

Route::prefix('categories')->middleware('auth:sanctum')->group(function () {
    Route::get('/{menu}', [CategoryController::class, 'index'])
        ->name('categories.show');
})
    ->middleware(ThrottleRequests::with(60, 1));

Route::prefix('orders')->middleware('auth:sanctum')->group(function () {
    Route::get('get-order/{date}', [OrderController::class, 'show'])
        ->name('orders.show');
    Route::post('create-or-update-order/{date}', [OrderController::class, 'update'])
        ->name('orders.update');
    Route::delete('delete-order-items/{date}', [OrderController::class, 'delete'])
        ->name('orders.delete');
    Route::post('update-order-status/{date}', [OrderController::class, 'updateOrderStatus'])
        ->name('orders.update_status');
})
    ->middleware(ThrottleRequests::with(60, 1));
