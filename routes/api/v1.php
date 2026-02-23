<?php

use App\Enums\RoleName;
use App\Facades\ImageSigner;
use App\Http\Controllers\API\V1\Auth\LoginController;
use App\Http\Controllers\API\V1\Auth\LogoutController;
use App\Http\Controllers\API\V1\CategoryController;
use App\Http\Controllers\API\V1\MenuController;
use App\Http\Controllers\API\V1\OrderController;
use App\Http\Controllers\API\V1\UsersController;
use App\Http\Controllers\API\V1\WebRegistrationController;
use App\Http\Middleware\VerifyPublicApiKey;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::prefix('auth')->group(function () {

    Route::middleware([ThrottleRequests::with(10, 1)])->post('login', LoginController::class)
        ->name('login');

    Route::group(['middleware' => ['auth:sanctum']], function () {
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
    Route::get('/{menu}/groups', [CategoryController::class, 'categoryGroups'])
        ->name('categories.groups');
});

Route::prefix('orders')->middleware(['auth:sanctum', ThrottleRequests::with(60, 1)])->group(function () {
    Route::get('get-order/{date}', [OrderController::class, 'show'])
        ->name('orders.show');
    Route::get('get-previous-order/{date}', [OrderController::class, 'getPreviousOrder'])
        ->name('orders.get_previous_order');
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
    Route::patch('update-user-comment/{id}', [OrderController::class, 'updateUserComment'])
        ->name('orders.update_user_comment');
});

Route::prefix('users')->middleware(['auth:sanctum', ThrottleRequests::with(60, 1)])->group(function () {
    Route::get('subordinates', [UsersController::class, 'getSubordinateUsers'])
        ->name('users.subordinates');
});

Route::prefix('signed-urls')->middleware([
    'auth:sanctum',
    //  ThrottleRequests::with(30, 1)
])->group(function () {

    Route::post('generate', function (Request $request) {
        if (! $request->user()->hasRole(RoleName::ADMIN->value)) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
                'error' => 'insufficient_privileges',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'file_path' => 'required|string|max:500',
                'expiry_days' => 'sometimes|integer|min:1|max:100000',
            ]);

            $filePath = $validated['file_path'];
            $expiryDays = $validated['expiry_days'] ?? 1;

            $signedUrlData = ImageSigner::getSignedUrl($filePath, $expiryDays);

            return response()->json([
                'success' => true,
                'data' => $signedUrlData,
                'message' => 'Signed URL generated successfully',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating the signed URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    })->name('signed-urls.generate');

});

// WhatsApp Webhook
Route::prefix('whatsapp')->group(function () {
    Route::get('webhook', function (Request $request) {
        \Illuminate\Support\Facades\Log::info('WhatsApp Webhook GET (verification)', $request->all());

        $verifyToken = config('whatsapp.webhook_verify_token');
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    })->name('whatsapp.webhook.verify');

    Route::post('webhook', function (Request $request, \App\Services\Chat\ProcessWebhookService $service) {
        \Illuminate\Support\Facades\Log::info('WhatsApp Webhook POST: '.json_encode($request->all()));

        $service->process($request->all());

        return response()->json(['status' => 'received']);
    })->name('whatsapp.webhook');
});

// Web Registration - Public endpoint (API key required, no user auth)
Route::prefix('web-registration')->middleware([
    VerifyPublicApiKey::class,
    ThrottleRequests::with(1, 1),
])->group(function () {
    Route::post('/', [WebRegistrationController::class, 'store'])
        ->name('web-registration.store');
});
