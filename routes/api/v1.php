<?php

use App\Facades\ImageSigner;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Dreamonkey\CloudFrontUrlSigner\Facades\CloudFrontUrlSigner;

use App\Http\Controllers\API\V1\Auth\{
    LoginController,
    LogoutController
};

use App\Http\Controllers\API\V1\{
    MenuController,
    CategoryController,
    OrderController
};

Route::prefix('auth')->group(function () {

    Route::middleware([ThrottleRequests::with(10, 1)])->post('login', LoginController::class)
        ->name('login');

    Route::group(['middleware' => ['auth:sanctum', ThrottleRequests::with(5, 1)]], function () {
        Route::post('logout', LogoutController::class)
            ->name('logout');
    });
});

Route::prefix('menus')->middleware(['auth:sanctum', ThrottleRequests::with(60, 1)])->group(function () {
    Route::get('/', [MenuController::class, 'index'])
        ->name('menus.index');
});

Route::prefix('categories')->middleware(['auth:sanctum', ThrottleRequests::with(60, 1)])->group(function () {
    Route::get('/{menu}', [CategoryController::class, 'index'])
        ->name('categories.show');
});

Route::prefix('orders')->middleware(['auth:sanctum', ThrottleRequests::with(60, 1)])->group(function () {
    Route::get('get-order/{date}', [OrderController::class, 'show'])
        ->name('orders.show');
    Route::get('get-order-by-id/{id}', [OrderController::class, 'showById'])
        ->name('orders.get-order-by-id');
    Route::get('get-orders', [OrderController::class, 'index'])
        ->name('orders.index');
    Route::post('create-or-update-order/{date}', [OrderController::class, 'update'])
        ->name('orders.update');
    Route::delete('delete-order-items/{date}', [OrderController::class, 'delete'])
        ->name('orders.delete');
    Route::post('update-order-status/{date}', [OrderController::class, 'updateOrderStatus'])
        ->name('orders.update_status');
    Route::post('partially-schedule-order/{date}', [OrderController::class, 'partiallyScheduleOrder'])
        ->name('orders.partially_schedule_order');
});
