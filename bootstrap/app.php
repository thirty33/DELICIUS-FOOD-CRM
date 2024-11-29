<?php

use App\Services\API\V1\ApiResponseService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\QueryException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->render(function (ThrottleRequestsException $exception) {
            $retryAfter = data_get($exception->getHeaders(), 'Retry-After');
            $maxAttempts = data_get($exception->getHeaders(), 'X-RateLimit-Limit');

            return ApiResponseService::throttled(
                maxAttempts: $maxAttempts,
                retryAfter: $retryAfter,
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception) {
            return ApiResponseService::notFound($exception->getMessage());
        });

        $exceptions->render(function (QueryException $exception) {
            return ApiResponseService::error('Cannot delete or update a parent row');
        });
    })->create();
