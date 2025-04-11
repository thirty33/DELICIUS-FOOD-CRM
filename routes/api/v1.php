<?php

use App\Mail\UserCredentialsEmail;
use App\Mail\OrderEmail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
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
})
    ->middleware(ThrottleRequests::with(60, 1));


Route::get('/preview-email', function (Request $request) {
    // Obtenemos el usuario por email o id
    $email = $request->query('email', 'mehal32528@anlocc.com');

    // Buscar el usuario específico
    $user = User::where('email', $email)->first();

    // Si no se encuentra el usuario, mostrar un mensaje de error
    if (!$user) {
        return "Usuario con email {$email} no encontrado. Por favor especifica un email válido.";
    }

    // Retornar el mailable directamente para verlo en el navegador
    return new UserCredentialsEmail($user);
});

Route::get('/preview-order-email', function (Request $request) {
    // Obtenemos los parámetros
    $email = $request->query('email', 'contact_convenio_consolidado@example.com');
    $orderId = $request->query('order_id');
    
    // Primero buscamos al usuario
    $user = User::where('email', $email)->first();
    
    // Si no se encuentra el usuario, mostrar un mensaje de error
    if (!$user) {
        return "Usuario con email {$email} no encontrado. Por favor especifica un email válido.";
    }
    
    // Si se proporcionó un ID de orden específico, lo usamos
    if ($orderId) {
        $order = Order::find($orderId);
        
        // Verificamos que la orden pertenezca al usuario
        if (!$order || $order->user_id != $user->id) {
            return "Orden #{$orderId} no encontrada o no pertenece al usuario {$email}";
        }
    } else {
        // Si no se proporcionó ID, buscamos la primera orden del usuario
        $order = Order::where('user_id', $user->id)->first();
        
        // Si el usuario no tiene órdenes
        if (!$order) {
            return "El usuario {$email} no tiene ninguna orden registrada.";
        }
    }
    
    // Retornar el mailable directamente para verlo en el navegador
    return new OrderEmail($order);
});
