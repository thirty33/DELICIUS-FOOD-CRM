<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1') // 10 requests per minute
    ->group(function () {
        include __DIR__ . '/api/v1.php';
    });
