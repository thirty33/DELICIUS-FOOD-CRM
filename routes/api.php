<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->as('v1.')
    ->group(function () {
        include __DIR__ . '/api/v1.php';
    });
