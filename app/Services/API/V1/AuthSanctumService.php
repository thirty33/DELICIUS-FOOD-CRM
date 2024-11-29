<?php

namespace App\Services\API\V1;

use App\Contracts\API\Auth\AuthServiceInterface;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthSanctumService implements AuthServiceInterface
{
    public function login(array $credentials): JsonResponse
    {
        if (! auth()->attempt([
            'email' => data_get($credentials, 'email'),
            'password' => data_get($credentials, 'password'),
        ])
        ) {
            return ApiResponseService::unauthorized();
        }

        $token = auth()
            ->user()
            ->createToken(data_get($credentials, 'device_name'))
            ->plainTextToken;

        return ApiResponseService::success([
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function register(array $data): JsonResponse
    {
        $user = User::create($data);

        $token = $user->createToken(data_get($data, 'device_name'))->plainTextToken;

        return ApiResponseService::success([
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->user()->tokens()->delete();

        return ApiResponseService::success(null, 'Successfully logged out');
    }
}
