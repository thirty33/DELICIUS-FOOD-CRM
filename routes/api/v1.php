<?php

use App\Facades\ImageSigner;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Dreamonkey\CloudFrontUrlSigner\Facades\CloudFrontUrlSigner;
use App\Enums\RoleName;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use App\Http\Controllers\API\V1\Auth\{
    LoginController,
    LogoutController
};

use App\Http\Controllers\API\V1\{
    MenuController,
    CategoryController,
    OrderController,
    UsersController
};

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

Route::prefix('signed-urls')->middleware(['auth:sanctum', ThrottleRequests::with(30, 1)])->group(function () {

    Route::post('generate', function (Request $request) {
        if (!$request->user()->hasRole(RoleName::ADMIN->value)) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
                'error' => 'insufficient_privileges'
            ], 403);
        }

        try {
            $validated = $request->validate([
                'file_path' => 'required|string|max:500',
                'expiry_days' => 'sometimes|integer|min:1|max:100000'
            ]);

            $filePath = $validated['file_path'];
            $expiryDays = $validated['expiry_days'] ?? 1;

            $signedUrlData = ImageSigner::getSignedUrl($filePath, $expiryDays);

            return response()->json([
                'success' => true,
                'data' => $signedUrlData,
                'message' => 'Signed URL generated successfully'
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating the signed URL',
                'error' => $e->getMessage()
            ], 500);
        }
    })->name('signed-urls.generate');

});

Route::prefix('defontana')->group(function () {

    Route::get('test-auth', function () {
        $credentials = [
            'Client' => '20230228152841155001',
            'Company' => '20230306205330705533',
            'User' => 'INTEGRACION',
            'Password' => 'FOOD'
        ];

        $url = 'https://replapi.defontana.com/api/auth?' . http_build_query($credentials);

        \Log::info('Defontana Auth Request', [
            'url' => $url,
            'credentials' => [
                'Client' => $credentials['Client'],
                'Company' => $credentials['Company'],
                'User' => $credentials['User'],
                'Password' => '***'
            ]
        ]);

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            \Log::info('Defontana Auth Response', [
                'status_code' => $statusCode,
                'body' => $data,
                'success' => $data['success'] ?? false
            ]);

            return response()->json([
                'success' => true,
                'defontana_response' => $data,
                'status_code' => $statusCode
            ], 200);

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \Log::error('Defontana Auth Request Exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);

        } catch (\Exception $e) {
            \Log::error('Defontana Auth General Exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    })->name('defontana.test-auth');

    Route::get('test-save-sale', function () {
        $credentials = [
            'Client' => '20230228152841155001',
            'Company' => '20230306205330705533',
            'User' => 'REPLICACION',
            'Password' => 'FOOD'
        ];

        $authUrl = 'https://replapi.defontana.com/api/auth?' . http_build_query($credentials);

        try {
            $client = new \GuzzleHttp\Client();

            // Step 1: Authentication
            \Log::info('Defontana SaveSale - Step 1: Authentication');
            $authResponse = $client->get($authUrl);
            $authData = json_decode($authResponse->getBody()->getContents(), true);

            if (!$authData['success'] || empty($authData['access_token'])) {
                \Log::error('Defontana SaveSale - Auth Failed', ['response' => $authData]);
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication failed',
                    'auth_response' => $authData
                ], 401);
            }

            $token = $authData['access_token'];
            \Log::info('Defontana SaveSale - Auth Success', ['token_length' => strlen($token)]);

            // Step 2: SaveSale
            $saveSaleUrl = 'https://replapi.defontana.com/api/sale/SaveSale';

            $saleData = [
                'documentType' => '33',
                'firstFolio' => 0,
                'lastFolio' => 0,
                'emissionDate' => [
                    'day' => 6,
                    'month' => 10,
                    'year' => 2025
                ],
                'firstFeePaid' => [
                    'day' => 6,
                    'month' => 10,
                    'year' => 2025
                ],
                'clientFile' => '66666666-6',
                'contactIndex' => 0,
                'paymentCondition' => 'CONTADO',
                'sellerFileId' => '11111111-1',
                'clientAnalysis' => [
                    'accountNumber' => '',
                    'businessCenter' => '',
                    'classifier01' => '',
                    'classifier02' => ''
                ],
                'saleAnalysis' => [
                    'accountNumber' => '',
                    'businessCenter' => '',
                    'classifier01' => '',
                    'classifier02' => ''
                ],
                'billingCoin' => 'PESO',
                'billingRate' => 1,
                'shopId' => 1,
                'priceList' => 'GENERAL',
                'giro' => 'VENTA DE ALIMENTOS',
                'district' => '',
                'contact' => '',
                'storage' => [
                    'code' => 'BODEGA01',
                    'motive' => 'VENTA',
                    'storageAnalysis' => [
                        'accountNumber' => '',
                        'businessCenter' => '',
                        'classifier01' => '',
                        'classifier02' => ''
                    ]
                ],
                'details' => [
                    [
                        'type' => 'A',
                        'code' => 'PROD001',
                        'count' => 1,
                        'productName' => 'PRODUCTO TEST',
                        'productNameBarCode' => 'PROD001',
                        'price' => 1000,
                        'unit' => 'UN',
                        'analysis' => [
                            'accountNumber' => '',
                            'businessCenter' => '',
                            'classifier01' => '',
                            'classifier02' => ''
                        ]
                    ]
                ],
                'saleTaxes' => [
                    [
                        'code' => 'IVA',
                        'value' => 19
                    ]
                ],
                'ventaRecDesGlobal' => [],
                'gloss' => 'Venta de prueba',
                'isTransferDocument' => true
            ];

            \Log::info('Defontana SaveSale - Request Body', ['data' => $saleData]);

            $saveSaleResponse = $client->post($saveSaleUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'bearer ' . $token
                ],
                'json' => ['Sale' => $saleData]
            ]);

            $saveSaleStatusCode = $saveSaleResponse->getStatusCode();
            $saveSaleBody = $saveSaleResponse->getBody()->getContents();
            $saveSaleData = json_decode($saveSaleBody, true);

            \Log::info('Defontana SaveSale - Response', [
                'status_code' => $saveSaleStatusCode,
                'body' => $saveSaleData
            ]);

            return response()->json([
                'success' => true,
                'auth_success' => true,
                'save_sale_response' => $saveSaleData,
                'status_code' => $saveSaleStatusCode
            ], 200);

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            \Log::error('Defontana SaveSale - Request Exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response' => $responseBody
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed',
                'error' => $e->getMessage(),
                'response' => $responseBody
            ], 500);

        } catch (\Exception $e) {
            \Log::error('Defontana SaveSale - General Exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    })->name('defontana.test-save-sale');

});
